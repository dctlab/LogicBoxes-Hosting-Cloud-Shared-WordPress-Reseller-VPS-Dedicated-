<?php
/**
 * Offline test suite, Phase 3: price catalogue, configurable-option builder,
 * product generator, price sync and recurring amounts.
 * Run: php tests/run_phase3.php
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/fixtures.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use LbHosting\Accounts\AccountRepository;
use LbHosting\Pricing\PriceCalculator;
use LbHosting\Pricing\PriceCatalog;
use LbHosting\Pricing\PriceNormalizer;
use LbHosting\Pricing\PricePlanner;
use LbHosting\Pricing\PriceSync;
use LbHosting\Pricing\RecurringPricer;
use LbHosting\Products\OptionBuilder;
use LbHosting\Products\ProductGenerator;
use LbHosting\Products\ProductMap;
use LbHosting\Support\Settings;
use LbHosting\WHMCS\OptionMap;
use LbHosting\WHMCS\ProductConfig;

// ------------------------------------------------------------------------------
section('Normalizer');
$rows = PriceNormalizer::normalize(priceFixture());
$find = static fn (string $key, int $plan, string $item, string $action = '', int $months = 0) => array_values(array_filter($rows, static fn ($r) => $r['product_key'] === $key && $r['plan_id'] === $plan && $r['item'] === $item && $r['action'] === $action && $r['months'] === $months));
check('hosting plan rows (add/renew x tenure)', count($find('singledomainhostinglinuxin', 1001, 'plan', 'renew', 24)) === 1 && $find('singledomainhostinglinuxin', 1001, 'plan', 'renew', 12)[0]['price'] === 99.0);
check('hosting "ssl" -> plan-level extra', $find('singledomainhostinglinuxin', 1001, 'ssl')[0]['price'] === 150.0);
check('cloud "plans" wrapper + product-level addons', count($find('cloudsiteslinuxus', 300109, 'plan', 'renew', 12)) === 1 && $find('cloudsiteslinuxus', 0, 'addon:cpu_1')[0]['price'] === 4.0);
check('dedicated addons + plans', $find('dedicatedserverlinuxin', 0, 'addon:cpanel')[0]['price'] === 25.0 && $find('dedicatedserverlinuxin', 105, 'plan', 'renew', 1)[0]['price'] === 110.0);
check('domain and other products ignored', !array_filter($rows, static fn ($r) => in_array($r['product_key'], ['dotcom', 'titanmailindia'], true)));
check('onlyOurs filters the raw JSON', array_keys(PriceNormalizer::onlyOurs(['dotcom' => [], 'resellerhosting' => []])) === ['resellerhosting']);

// ------------------------------------------------------------------------------
section('Calculator');
$c = new PriceCalculator([['action' => 'renew', 'months' => 1, 'price' => 129], ['action' => 'renew', 'months' => 12, 'price' => 99], ['action' => 'add', 'months' => 12, 'price' => 79]], 'auto', 'renew');
check('auto basis: 12m/1m ratio < 4 -> per_month', $c->basis() === 'per_month');
check('per_month: annual = 12 x 99, monthly = 129', $c->cyclePrice(12) === 1188.0 && $c->cyclePrice(1) === 129.0 && $c->cyclePrice(24) === null);
check('sells at renewal price, not the add promo', $c->tenures() === [1, 12]);
$c2 = new PriceCalculator([['action' => 'renew', 'months' => 1, 'price' => 10], ['action' => 'renew', 'months' => 12, 'price' => 100]], 'auto');
check('auto basis: ratio > 4 -> per_term', $c2->basis() === 'per_term' && $c2->cyclePrice(12) === 100.0);
check('add price used when no renew price', (new PriceCalculator([['action' => 'add', 'months' => 12, 'price' => 5]], 'per_month', 'renew'))->cyclePrice(12) === 60.0);
check('rounding modes', PriceCalculator::round(10.001, '2dp') === 10.0 && PriceCalculator::round(10.2, 'whole') === 11.0 && PriceCalculator::round(10.2, '99') === 10.99 && PriceCalculator::round(10.995, '99') === 11.99);
check('markup + conversion INR -> USD', PriceCalculator::sellPrice(1000, 10, 1.0, 0.012, '2dp') === 13.2);
check('extras per year vs per month', PriceCalculator::extraForCycle(120, 12, 'per_year') === 120.0 && PriceCalculator::extraForCycle(10, 3, 'per_month') === 30.0);

// ------------------------------------------------------------------------------
section('Catalogue storage');
$t = catalogDb('whmcs');
check('price rows stored', Capsule::table('mod_lbh_prices')->where('account_id', 1)->count() > 15);
check('plan details stored with names', Capsule::table('mod_lbh_plans')->where('product_key', 'singledomainhostinglinuxin')->where('plan_id', 1002)->value('plan_name') === 'Business');
check('extra lookup: plan level, then product level', PriceCatalog::extra(1, 'singledomainhostinglinuxin', 1001, 'ssl') === 150.0 && PriceCatalog::extra(1, 'virtualserverlinuxus', 10, 'addon:cpanel') === 20.0 && PriceCatalog::extra(1, 'virtualserverlinuxus', 10, 'addon:plesk_10_domain') === null);
check('Dedicated supported_os read from plan details', PriceCatalog::supportedOs(PriceCatalog::planDetails(1, 'dedicatedserverlinuxin', 105)) === ['ubuntu_22' => 'Ubuntu 22.04', 'alma_9' => 'AlmaLinux 9']);
check('VPS isSSDPlan read', PriceCatalog::isSsd(PriceCatalog::planDetails(1, 'virtualserverlinuxus', 12)) === false && PriceCatalog::isSsd(PriceCatalog::planDetails(1, 'virtualserverlinuxus', 10)) === true);
$plans = PriceCatalog::plans(1, 'singledomain');
check('family plan list with priced flag', count($plans) === 3 && $plans[0]['plan_name'] === 'Starter' && $plans[0]['priced']);
$before = Capsule::table('mod_lbh_prices')->count();
$t->script['GET api/products/customer-price.json'] = [[500, json_encode(['status' => 'ERROR', 'message' => 'IP not whitelisted'])]];
$res = (new PriceCatalog($t))->refresh((new AccountRepository())->find(1));
check('failed fetch keeps the previous catalogue', isset($res['errors']['prices']) && Capsule::table('mod_lbh_prices')->count() === $before);

// ------------------------------------------------------------------------------
section('Option specs per family');
$names = static fn (array $specs) => array_map(static fn ($s) => $s['logical'], $specs);
$s = OptionBuilder::specs('singledomain', 'singledomainhostinglinuxin', 1, 1001);
check('SDH: Dedicated IP yes/no priced from "ssl"', $names($s['specs']) === ['dedicated_ip'] && $s['specs'][0]['type'] === 3 && $s['specs'][0]['subs'][0]['item'] === 'ssl');
$s = OptionBuilder::specs('singledomain', 'singledomainhostinglinuxin', 1, 1003);
check('SDH plan without "ssl" price and no manual price: option skipped', $s['specs'] === [] && count($s['skipped']) === 1);
$s = OptionBuilder::specs('singledomain', 'singledomainhostinglinuxin', 1, 1003, ['dedicated_ip' => '120']);
check('...and a typed-in price does not create it either (prices come from LogicBoxes only)', $s['specs'] === []);
$s = OptionBuilder::specs('cloud', 'cloudsiteslinuxus', 1, 300109);
check('Cloud: CPU + RAM from catalogue, dedicated IP skipped without manual price', $names($s['specs']) === ['cpu', 'ram'] && count($s['skipped']) === 1);
$s = OptionBuilder::specs('cloud', 'cloudsiteslinuxus', 1, 300109, ['dedicated_ip' => '200']);
check('Cloud dedicated IP with manual price (fulfilled by hand)', $names($s['specs']) === ['cpu', 'ram', 'dedicated_ip'] && $s['specs'][2]['subs'][0]['item'] === 'manual');
$s = OptionBuilder::specs('reseller', 'resellerhosting', 1, 3001, ['whmcs' => '15', 'cpanel_blocks' => '9']);
check('Reseller Linux without WHMCS / cPanel-block prices at LogicBoxes: only the IPs (typed-in prices ignored)', $names($s['specs']) === ['dedicated_ip'] && $s['specs'][0]['type'] === 4 && count($s['skipped']) === 2);
$s = OptionBuilder::specs('reseller', 'resellerwindowshosting', 1, 3101, ['dedicated_ip' => '5', 'whmcs' => '15', 'cpanel_blocks' => '9']);
check('Reseller Windows: no cPanel blocks', !in_array('cpanel_blocks', $names($s['specs']), true));
$s = OptionBuilder::specs('vps', 'virtualserverlinuxus', 1, 10);
$panel = array_values(array_filter($s['specs'], static fn ($x) => $x['logical'] === 'control_panel'))[0];
check('VPS SSD: OS, panel (only priced panels + none), IP, WHMCS; no storage', $names($s['specs']) === ['os', 'control_panel', 'dedicated_ip', 'whmcs'] && array_column($panel['subs'], 'code') === ['', 'cpanel', 'plesk_30_domain']);
$s = OptionBuilder::specs('vps', 'virtualserverlinuxus', 1, 12);
check('VPS HDD plan gets Storage with priced sizes only', in_array('storage', $names($s['specs']), true) && array_column(array_values(array_filter($s['specs'], static fn ($x) => $x['logical'] === 'storage'))[0]['subs'], 'code') === ['', 'storage_1']);
$s = OptionBuilder::specs('dedicated', 'dedicatedserverlinuxin', 1, 105, ['bandwidth_tiers' => "10TB|10 TB extra = 25\nbad line\n20TB = 45"]);
$os = array_values(array_filter($s['specs'], static fn ($x) => $x['logical'] === 'os'))[0];
$bw = array_values(array_filter($s['specs'], static fn ($x) => $x['logical'] === 'bandwidth'))[0];
check('Dedicated IN: OS from plan supported_os, no storage', array_column($os['subs'], 'code') === ['ubuntu_22', 'alma_9'] && !in_array('storage', $names($s['specs']), true));
check('Dedicated bandwidth tiers parsed (bad lines ignored), manual', array_column($bw['subs'], 'code') === ['', '10TB', '20TB'] && $bw['subs'][1]['manual'] === 25.0 && $bw['subs'][2]['item'] === 'manual');

// ------------------------------------------------------------------------------
section('Product generator');
$gen = new ProductGenerator();
$sdhPlans = [
    ['product_key' => 'singledomainhostinglinuxin', 'plan_id' => 1001, 'plan_name' => 'Starter'],
    ['product_key' => 'singledomainhostinglinuxin', 'plan_id' => 1002, 'plan_name' => 'Business'],
];
$r = $gen->generate('singledomain', 1, $sdhPlans, genOpts());
check('2 products created, options created, prices written', count($r['created']) === 2 && $r['options'] === 2 && $r['prices'] > 0, json_encode($r));
$pid = $r['created'][0];
$prod = Capsule::table('tblproducts')->where('id', $pid)->first();
check('product: name, hidden, hosting type, module settings', $prod->name === 'Starter (Linux - India)' && (int) $prod->hidden === 1 && $prod->type === 'hostingaccount' && $prod->servertype === 'lbhsingledomain' && $prod->configoption2 === 'singledomainhostinglinuxin:1001' && $prod->configoption1 === '1');
check('new product group created', Capsule::table('tblproductgroups')->where('name', 'Hosting')->exists());
$pr = pricing('product', $pid);
check('INR price: renew 129/month +10% = 141.90 monthly, 99x12 +10% = 1306.80 annually', abs($pr->monthly - 141.90) < 0.001 && abs($pr->annually - 1306.80) < 0.001);
check('cycles LogicBoxes does not price are disabled (-1)', (float) $pr->quarterly === -1.0 && (float) $pr->triennially === -1.0);
check('USD price converted', abs((float) pricing('product', $pid, 2)->annually - 15.68) < 0.001);
$map = (new ProductMap())->forProduct($pid);
$opt = ProductMap::options((int) $map->id)[0];
$optRow = Capsule::table('tblproductconfigoptions')->where('id', $opt->config_option_id)->first();
check('option created as "Dedicated IP", yes/no, linked to the product', $optRow->optionname === 'Dedicated IP' && (int) $optRow->optiontype === 3 && Capsule::table('tblproductconfiglinks')->where('pid', $pid)->where('gid', $map->config_group_id)->exists());
$op = pricing('configoptions', (int) $opt->config_sub_id);
check('option price: ssl 150/month +10% -> 165 monthly, 1980 annually', abs($op->monthly - 165) < 0.001 && abs($op->annually - 1980) < 0.001);
check('upgrade paths both ways for Single Domain', Capsule::table('tblproduct_upgrade_products')->count() === 2);

// The generated option is read back by the provisioning code
$sub = Capsule::table('tblproductconfigoptionssub')->where('id', $opt->config_sub_id)->first();
$desired = (new OptionMap(['configoptions' => [$optRow->optionname => 1]]))->desired(new ProductConfig('singledomain', ['configoption2' => $prod->configoption2]));
check('round trip: generated option name is understood by provisioning', $desired['ssl'] === true);

$r2 = $gen->generate('singledomain', 1, $sdhPlans, genOpts(['markup_pct' => '0']));
check('re-run: products updated, nothing duplicated', count($r2['created']) === 0 && count($r2['updated']) === 2 && $r2['options'] === 0 && Capsule::table('tblproducts')->count() === 2 && Capsule::table('tblproductconfigoptions')->count() === 2 && Capsule::table('tblproduct_upgrade_products')->count() === 2);
check('re-run applies the new markup', abs((float) pricing('product', $pid)->monthly - 129.0) < 0.001);

// VPS: upgrade paths only upward, server product type, default OS
$r = $gen->generate('vps', 1, [
    ['product_key' => 'virtualserverlinuxus', 'plan_id' => 10, 'plan_name' => 'VPS S'],
    ['product_key' => 'virtualserverlinuxus', 'plan_id' => 11, 'plan_name' => 'VPS L'],
], genOpts(['new_group' => 'VPS', 'markup_pct' => '0']));
[$vS, $vL] = $r['created'];
$vp = Capsule::table('tblproducts')->where('id', $vS)->first();
check('VPS product: server type, Default OS alma_8, no domain options', $vp->type === 'server' && $vp->configoption4 === 'alma_8' && (int) $vp->showdomainoptions === 0);
check('VPS upgrade path only S -> L (no downgrade path)', Capsule::table('tblproduct_upgrade_products')->where('product_id', $vS)->where('upgrade_product_id', $vL)->exists() && !Capsule::table('tblproduct_upgrade_products')->where('product_id', $vL)->where('upgrade_product_id', $vS)->exists());
$vMap = (new ProductMap())->forProduct($vS);
$osSub = array_values(array_filter(ProductMap::options((int) $vMap->id), static fn ($o) => $o->logical === 'os'));
check('VPS OS option: 6 free sub-options named "code|Label"', count($osSub) === 6 && Capsule::table('tblproductconfigoptionssub')->where('id', $osSub[0]->config_sub_id)->value('optionname') === 'alma_8|AlmaLinux 8' && (float) pricing('configoptions', (int) $osSub[0]->config_sub_id)->monthly === 0.0);
$panelNone = array_values(array_filter(ProductMap::options((int) $vMap->id), static fn ($o) => $o->logical === 'control_panel' && $o->code === ''))[0];
check('"None" panel sub-option named none|None, free', Capsule::table('tblproductconfigoptionssub')->where('id', $panelNone->config_sub_id)->value('optionname') === 'none|None');
$cp = array_values(array_filter(ProductMap::options((int) $vMap->id), static fn ($o) => $o->code === 'cpanel'))[0];
check('cPanel add-on price per month x months (20 -> 240 annually)', abs((float) pricing('configoptions', (int) $cp->config_sub_id)->annually - 240) < 0.001);
$d = (new OptionMap(['configoptions' => ['Operating System' => 'rocky_9', 'Control Panel' => 'cpanel', 'Dedicated IP' => 2, 'WHMCS License' => 1]]))->desired(new ProductConfig('vps', ['configoption2' => $vp->configoption2]));
check('round trip VPS: OS + panel + IP + WHMCS understood', $d['os'] === 'rocky_9' && $d['addons']['cpanel'] === 1 && $d['addons']['ipaddress'] === 2 && $d['addons']['whmcs'] === 1);

// Cloud + Dedicated
$r = $gen->generate('cloud', 1, [['product_key' => 'cloudsiteslinuxus', 'plan_id' => 300109, 'plan_name' => 'Cloud Starter']], genOpts(['new_group' => 'Cloud', 'markup_pct' => '0', 'manual' => ['dedicated_ip' => '2.5']]));
$cMap = (new ProductMap())->forProduct($r['created'][0]);
check('Cloud: CPU, RAM, Dedicated IP options; no upgrade paths (no plan-change API)', count(ProductMap::options((int) $cMap->id)) === 3 && $r['upgrades'] === 0);
check('Cloud monthly not priced -> disabled; annually 20.99x12', (float) pricing('product', $r['created'][0])->monthly === -1.0 && abs((float) pricing('product', $r['created'][0])->annually - 251.88) < 0.001);
$r = $gen->generate('dedicated', 1, [['product_key' => 'dedicatedserverlinuxin', 'plan_id' => 105, 'plan_name' => 'DS-1']], genOpts(['new_group' => 'Dedicated', 'markup_pct' => '0', 'bandwidth_tiers' => '10TB|10 TB = 25']));
$dp = Capsule::table('tblproducts')->where('id', $r['created'][0])->first();
check('Dedicated: default OS = first supported_os, description has specs', $dp->configoption4 === 'ubuntu_22' && str_contains((string) $dp->description, 'CPU cores: 8'));
// WordPress + Business
$s = OptionBuilder::specs('wordpress', 'wordpresshostingusa', 1, 300109);
check('WordPress specs: CPU + IP priced from catalogue, RAM skipped (no price)', $names($s['specs']) === ['cpu', 'dedicated_ip'] && count($s['skipped']) === 1 && $s['specs'][1]['subs'][0]['item'] === 'addon:ipaddress');
$s = OptionBuilder::specs('wordpress', 'wordpresshostingusa', 1, 300109, ['ram' => '3']);
check('...a typed-in RAM price does not create it', $names($s['specs']) === ['cpu', 'dedicated_ip']);
$r = $gen->generate('wordpress', 1, [['product_key' => 'wordpresshostingusa', 'plan_id' => 300109, 'plan_name' => 'WP Starter']], genOpts(['new_group' => 'WordPress', 'markup_pct' => '0', 'manual' => ['ram' => '3']]));
$wpProd = Capsule::table('tblproducts')->where('id', $r['created'][0])->first();
check('WordPress product: module lbhwordpress, 2 options (no RAM price at LogicBoxes), renewal price 20.99x12, no upgrade paths', $wpProd->servertype === 'lbhwordpress' && $wpProd->type === 'hostingaccount' && $r['options'] === 2 && abs((float) pricing('product', $r['created'][0])->annually - 251.88) < 0.001 && $r['upgrades'] === 0);
$d = (new OptionMap(['configoptions' => ['CPU' => 2, 'RAM' => 1, 'Dedicated IP' => 1]]))->desired(new ProductConfig('wordpress', ['configoption2' => $wpProd->configoption2]));
check('round trip WordPress: cpu_1 / ram_1 / ipaddress', $d['addons'] === ['cpu_1' => 2, 'ram_1' => 1, 'ipaddress' => 1]);
$before = Capsule::table('tblproduct_upgrade_products')->count();
$r = $gen->generate('business', 1, [['product_key' => 'businesshostinglinuxin', 'plan_id' => 41, 'plan_name' => 'Biz Starter'], ['product_key' => 'businesshostinglinuxin', 'plan_id' => 42, 'plan_name' => 'Biz Pro']], genOpts(['new_group' => 'Business', 'markup_pct' => '0']));
$bz = Capsule::table('tblproducts')->where('id', $r['created'][0])->first();
check('Business products: name, quarterly 500x3 + annually 450x12, upgrade paths both ways', $bz->name === 'Biz Starter (Linux - India)' && $bz->servertype === 'lbhbusiness' && abs((float) pricing('product', $r['created'][0])->quarterly - 1500) < 0.001 && abs((float) pricing('product', $r['created'][0])->annually - 5400) < 0.001 && Capsule::table('tblproduct_upgrade_products')->count() === $before + 2);
check('Business: Dedicated IP option only where the plan has an "ssl" price', $r['options'] === 1 && (bool) array_filter($r['messages'], static fn ($m) => str_contains($m, 'Biz Pro') && str_contains($m, 'skipped')));

throwsLike('plans of another family refused', fn () => $gen->generate('cloud', 1, [['product_key' => 'resellerhosting', 'plan_id' => 3001, 'plan_name' => 'x']], genOpts()), 'does not belong');
throwsLike('no currency refused', fn () => $gen->generate('reseller', 1, [['product_key' => 'resellerhosting', 'plan_id' => 3001, 'plan_name' => 'x']], genOpts(['source_currency_id' => '9'])), 'currency');

// ------------------------------------------------------------------------------
section('Price sync and recurring amounts');
$sdhMap = (new ProductMap())->forProduct($pid);
Capsule::table('mod_lbh_product_map')->where('id', $sdhMap->id)->update(['update_recurring' => 1]);
// a service on the SDH Starter product with the dedicated IP selected, priced like WHMCS would
Capsule::table('tblhosting')->insert(['id' => 20, 'userid' => 1, 'packageid' => $pid, 'billingcycle' => 'Annually', 'domain' => 'a.com', 'domainstatus' => 'Active', 'amount' => 1188 + 1800]);
Capsule::table('tblhostingconfigoptions')->insert(['relid' => 20, 'configid' => $opt->config_option_id, 'optionid' => $opt->config_sub_id, 'qty' => 1]);
Capsule::table('mod_lbh_services')->insert(['service_id' => 20, 'family' => 'singledomain', 'order_id' => 1]);
// a service with a hand-set price
Capsule::table('tblhosting')->insert(['id' => 21, 'userid' => 1, 'packageid' => $pid, 'billingcycle' => 'Annually', 'domain' => 'b.com', 'domainstatus' => 'Active', 'amount' => 500]);
check('RecurringPricer recognises a correctly priced service', RecurringPricer::afterChange(20, 'CreateAccount') === 2988.0 && (float) Capsule::table('mod_lbh_services')->where('service_id', 20)->value('priced_amount') === 2988.0);
$sync = new PriceSync();
check('in sync -> no changes', $sync->preview()['changes'] === []);
// LogicBoxes raises the SDH Starter renewal and ssl prices
Capsule::table('mod_lbh_prices')->where('product_key', 'singledomainhostinglinuxin')->where('plan_id', 1001)->where('action', 'renew')->where('months', 12)->update(['price' => 109]);
Capsule::table('mod_lbh_prices')->where('product_key', 'singledomainhostinglinuxin')->where('plan_id', 1001)->where('item', 'ssl')->update(['price' => 160]);
$p = $sync->preview();
$targets = array_count_values(array_map(static fn ($c) => $c['target'], $p['changes']));
check('preview: product, option and ONE managed service change (manual price untouched)', ($targets['product'] ?? 0) >= 2 && ($targets['configoptions'] ?? 0) >= 2 && ($targets['service'] ?? 0) === 1);
$svc = array_values(array_filter($p['changes'], static fn ($c) => $c['target'] === 'service'))[0];
check('new service amount = 109x12 + 160x12', $svc['relid'] === 20 && abs($svc['new'] - (1308 + 1920)) < 0.001);
$n = $sync->apply($p['changes'], 'manual');
check('apply writes everything and logs it', $n === count($p['changes']) && Capsule::table('mod_lbh_price_changes')->count() >= $n && abs((float) pricing('product', $pid)->annually - 1308) < 0.001);
check('service updated, manual service untouched', (float) Capsule::table('tblhosting')->where('id', 20)->value('amount') === 3228.0 && (float) Capsule::table('tblhosting')->where('id', 21)->value('amount') === 500.0);
check('priced_amount follows', (float) Capsule::table('mod_lbh_services')->where('service_id', 20)->value('priced_amount') === 3228.0);
Capsule::table('tblhosting')->where('id', 20)->update(['amount' => 999]);
Capsule::table('mod_lbh_prices')->where('product_key', 'singledomainhostinglinuxin')->where('plan_id', 1001)->where('action', 'renew')->where('months', 12)->update(['price' => 119]);
$p = $sync->preview();
check('a service an admin re-priced is no longer managed', !array_filter($p['changes'], static fn ($c) => $c['target'] === 'service'));
// add-on price disappears from the catalogue
Capsule::table('mod_lbh_prices')->where('product_key', 'singledomainhostinglinuxin')->where('plan_id', 1001)->where('item', 'ssl')->delete();
$p = $sync->preview();
check('missing option price: warning, option price left alone', (bool) array_filter($p['warnings'], static fn ($w) => str_contains($w, 'no longer lists a price')) && !array_filter($p['changes'], static fn ($c) => $c['target'] === 'configoptions' && (int) $c['relid'] === (int) $opt->config_sub_id));
Settings::override(array_merge(Settings::DEFAULTS, ['extras_basis' => 'per_year']));
$planner = new PricePlanner((new ProductMap())->forProduct($vS));
check('extras basis per_year divides by 12', abs($planner->optionMonthly($cp) - 20 / 12) < 0.0001);
Settings::override(Settings::DEFAULTS);
$t = new ScriptedTransport();
$t->on('GET', 'api/products/customer-price.json', 200, priceFixture());
$t->on('GET', 'api/products/plan-details.json', 200, planFixture());
$res = (new PriceSync($t))->runScheduled(true);
check('scheduled run refreshes the catalogue and applies', $res['written'] > 0 && $t->count('GET', 'api/products/customer-price.json') === 1);

// map existing
Capsule::table('tblproducts')->insert(['id' => 500, 'name' => 'Hand-made R1', 'servertype' => 'lbhreseller', 'configoption1' => '1', 'configoption2' => 'resellerhosting:3001']);
$mid = $gen->mapExisting(500, ['price_source' => 'sell', 'markup_pct' => '0', 'source_currency_id' => '1', 'sync_prices' => '1', 'create_options' => '1', 'manual' => ['whmcs' => '10']]);
check('existing product mapped with options (LogicBoxes-priced only)', (int) (new ProductMap())->find($mid)->product_id === 500 && count(ProductMap::options($mid)) === 1);
throwsLike('second mapping of the same product refused', fn () => $gen->mapExisting(500, ['price_source' => 'sell', 'source_currency_id' => '1']), 'already mapped');

done();
