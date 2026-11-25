<?php
/**
 * Offline tests: cost-price pricing and automatic synchronisation.
 *   1. option (and plan) prices come from the LogicBoxes COST price list
 *   2. automatic sync whenever a source price changes
 *   3. every currency and billing cycle
 *   4. new and existing products: missing options created, nothing duplicated
 *   5. Linux reseller cPanel blocks (10 accounts per block) with a recurring price
 * Run: php tests/run_pricing.php
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/fixtures.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use LbHosting\Accounts\AccountRepository;
use LbHosting\Pricing\PriceCatalog;
use LbHosting\Pricing\PriceNormalizer;
use LbHosting\Pricing\PricePlanner;
use LbHosting\Pricing\PriceSync;
use LbHosting\Products\OptionBuilder;
use LbHosting\Products\ProductGenerator;
use LbHosting\Products\ProductMap;
use LbHosting\Support\Settings;
use LbHosting\WHMCS\OptionMap;
use LbHosting\WHMCS\ProductConfig;
use LbHosting\WHMCS\ServerModule;

/** Cost prices (what LogicBoxes charges the reseller) - lower than the customer prices in fixtures.php. */
function costFixture(array $over = []): array
{
    return array_replace_recursive([
        'resellerhosting' => ['3001' => ['renew' => ['1' => 10, '12' => 9], 'add' => ['1' => 10, '12' => 9], 'ssl' => 2, 'addons' => ['whmcs' => 6, 'cpanel_blocks' => 4]]],
        'resellerwindowshosting' => ['3101' => ['renew' => ['12' => 12], 'addons' => ['whmcs' => 6]]],
        'multidomainhostinglinuxin' => ['812' => ['renew' => ['3' => 50, '12' => 40], 'ssl' => 100]],
        // KB 1029 band shape: key -> key -> bandid -> pricing (one price per action, no tenure)
        'singledomainhostinglinuxin' => ['singledomainhostinglinuxin' => [
            '1001' => ['pricing' => ['renew' => 60, 'add' => 55, 'ssl' => 90], 'description' => ['default' => true]],
        ]],
        'virtualserverlinuxus' => ['addons' => ['ipaddress' => 2, 'whmcs' => 10, 'cpanel' => 12], 'plans' => ['10' => ['renew' => ['1' => 7]], '11' => ['renew' => ['1' => 14]]]],
    ], $over);
}

function pricingDb(array $cost, ?array $sell = null): ScriptedTransport
{
    freshDb('lbhreseller', 'resellerhosting:3001');
    Capsule::table('tblproducts')->delete();
    Capsule::table('tblhosting')->delete();
    Settings::override(array_merge(Settings::DEFAULTS, ['upgrade_mode' => 'whmcs']));
    $t = new ScriptedTransport();
    ServerModule::$transport = $t;
    $t->on('GET', 'api/products/reseller-cost-price.json', 200, $cost);
    $t->on('GET', 'api/products/customer-price.json', 200, $sell ?? priceFixture());
    $t->on('GET', 'api/products/plan-details.json', 200, planFixture() + ['multidomainhostinglinuxin' => ['812' => ['plan_name' => 'MD Starter']]]);

    return $t;
}

function opts(array $over = []): array
{
    return array_merge(['price_source' => 'cost', 'new_group' => 'Hosting', 'hidden' => '1', 'create_options' => '1', 'markup_pct' => '50', 'rounding' => '2dp', 'source_currency_id' => '1', 'sync_prices' => '1'], $over);
}

function price(string $type, int $relid, int $currency = 1): ?object
{
    return Capsule::table('tblpricing')->where('type', $type)->where('relid', $relid)->where('currency', $currency)->first();
}

function optionId(int $mapId, string $logical): ?object
{
    foreach (ProductMap::options($mapId) as $o) {
        if ($o->logical === $logical) {
            return $o;
        }
    }

    return null;
}

$acc = static fn () => (new AccountRepository())->find(1);

// ------------------------------------------------------------------------------
section('Cost price list: shapes');
$rows = PriceNormalizer::normalize(costFixture());
$find = static fn (string $key, int $plan, string $item, string $action = '', int $months = 0) => array_values(array_filter($rows, static fn ($r) => $r['product_key'] === $key && $r['plan_id'] === $plan && $r['item'] === $item && $r['action'] === $action && $r['months'] === $months));
check('hosting shape: plan tenures, ssl, plan-level add-ons (cPanel blocks, WHMCS)', $find('resellerhosting', 3001, 'plan', 'renew', 12)[0]['price'] === 9.0 && $find('resellerhosting', 3001, 'ssl')[0]['price'] === 2.0 && $find('resellerhosting', 3001, 'addon:cpanel_blocks')[0]['price'] === 4.0 && $find('resellerhosting', 3001, 'addon:whmcs')[0]['price'] === 6.0);
check('Single Domain band shape (KB 1029): per-action price read as 1 month, ssl as extra', $find('singledomainhostinglinuxin', 1001, 'plan', 'renew', 1)[0]['price'] === 60.0 && $find('singledomainhostinglinuxin', 1001, 'ssl')[0]['price'] === 90.0);
check('server shape: product-level add-ons + plans', $find('virtualserverlinuxus', 0, 'addon:ipaddress')[0]['price'] === 2.0 && $find('virtualserverlinuxus', 11, 'plan', 'renew', 1)[0]['price'] === 14.0);

section('Fetch: both lists stored, changes detected');
$t = pricingDb(costFixture());
$r = (new PriceCatalog($t))->refresh($acc());
check('cost list from products/reseller-cost-price.json and customer list both stored', $r['cost'] > 0 && $r['prices'] > 0 && $t->count('GET', 'api/products/reseller-cost-price.json') === 1 && Capsule::table('mod_lbh_prices')->where('kind', 'cost')->count() === $r['cost'] && Capsule::table('mod_lbh_prices')->where('kind', 'sell')->count() === $r['prices']);
check('first fetch counts as a change', $r['changed'] === true);
check('same prices again: no change', (new PriceCatalog($t))->refresh($acc())['changed'] === false);
$t->script['GET api/products/reseller-cost-price.json'] = [[200, json_encode(costFixture(['resellerhosting' => ['3001' => ['addons' => ['cpanel_blocks' => 5]]]]))]];
check('one cost price changed: detected', (new PriceCatalog($t))->refresh($acc())['changed'] === true && PriceCatalog::extra(1, 'resellerhosting', 3001, 'addon:cpanel_blocks', 'cost') === 5.0);
$t->script['GET api/products/reseller-cost-price.json'] = [[500, json_encode(['status' => 'ERROR', 'message' => 'Access denied'])]];
$r = (new PriceCatalog($t))->refresh($acc());
check('a failed cost fetch keeps the stored cost prices and says so', isset($r['errors']['cost prices']) && PriceCatalog::extra(1, 'resellerhosting', 3001, 'addon:cpanel_blocks', 'cost') === 5.0);

// ------------------------------------------------------------------------------
section('1 + 3. Products and options priced from COST, every currency and cycle');
$t = pricingDb(costFixture());
(new PriceCatalog($t))->refresh($acc());
throwsLike('cost price with 0% markup is refused (would sell at cost)', fn () => (new ProductGenerator())->generate('reseller', 1, [['product_key' => 'resellerhosting', 'plan_id' => 3001, 'plan_name' => 'R1']], opts(['markup_pct' => '0'])), 'markup above 0%');
$gen = new ProductGenerator();
$r = $gen->generate('reseller', 1, [['product_key' => 'resellerhosting', 'plan_id' => 3001, 'plan_name' => 'R1']], opts());
$pid = $r['created'][0];
$map = (new ProductMap())->forProduct($pid);
check('mapping priced from cost', $map->price_source === 'cost' && (float) $map->markup_pct === 50.0);
$p = price('product', $pid);
check('plan: cost renew 10/month and 9/month (12m) + 50% -> monthly 15, annually 162', abs((float) $p->monthly - 15) < 0.001 && abs((float) $p->annually - 162) < 0.001);
check('cycles LogicBoxes does not sell for the plan are disabled', (float) $p->quarterly === -1.0 && (float) $p->triennially === -1.0);
$usd = price('product', $pid, 2);
check('every WHMCS currency: USD = INR x 0.012', abs((float) $usd->annually - round(162 * 0.012, 2)) < 0.001);
$names = array_map(static fn ($o) => $o->logical, ProductMap::options((int) $map->id));
check('options: Dedicated IPs, WHMCS licence and cPanel blocks - all from the cost list', array_values(array_unique($names)) === ['dedicated_ip', 'whmcs', 'cpanel_blocks'] && optionId((int) $map->id, 'cpanel_blocks')->price_item === 'addon:cpanel_blocks' && optionId((int) $map->id, 'whmcs')->price_item === 'addon:whmcs');
$ip = price('configoptions', (int) optionId((int) $map->id, 'dedicated_ip')->config_sub_id);
check('option price in all six cycles: ssl cost 2 x months + 50%', abs($ip->monthly - 3) < 0.001 && abs($ip->quarterly - 9) < 0.001 && abs($ip->semiannually - 18) < 0.001 && abs($ip->annually - 36) < 0.001 && abs($ip->biennially - 72) < 0.001 && abs($ip->triennially - 108) < 0.001);
$ipUsd = price('configoptions', (int) optionId((int) $map->id, 'dedicated_ip')->config_sub_id, 2);
check('...and in every currency', $ipUsd && abs((float) $ipUsd->annually - round(36 * 0.012, 2)) < 0.001);

section('5. Linux reseller cPanel blocks');
$cb = optionId((int) $map->id, 'cpanel_blocks');
$opt = Capsule::table('tblproductconfigoptions')->where('id', $cb->config_option_id)->first();
$sub = Capsule::table('tblproductconfigoptionssub')->where('id', $cb->config_sub_id)->first();
check('quantity option "cPanel Blocks", 0-20 blocks, one sub-option "Block of 10 extra cPanel accounts"', $opt->optionname === 'cPanel Blocks' && (int) $opt->optiontype === 4 && (int) $opt->qtymaximum === 20 && $sub->optionname === 'Block of 10 extra cPanel accounts');
$cbp = price('configoptions', (int) $cb->config_sub_id);
check('recurring price per block: cost 4/month + 50% = 6 monthly, 72 annually', abs($cbp->monthly - 6) < 0.001 && abs($cbp->annually - 72) < 0.001);
$d = (new OptionMap(['configoptions' => ['cPanel Blocks' => 3]]))->desired(new ProductConfig('reseller', ['configoption2' => 'resellerhosting:3001']));
check('the module reads the generated option as 3 blocks', $d['cpanel_blocks'] === 3);
Capsule::table('tblclients')->where('id', 1)->update(['currency' => 1]);
Capsule::table('tblhosting')->insert(['id' => 70, 'userid' => 1, 'packageid' => $pid, 'billingcycle' => 'Annually', 'domain' => 'r.example.com', 'domainstatus' => 'Active', 'amount' => '0']);
Capsule::table('tblhostingconfigoptions')->insert(['relid' => 70, 'configid' => $cb->config_option_id, 'optionid' => $cb->config_sub_id, 'qty' => 3]);
check('service recurring amount = plan 162 + 3 blocks x 72 = 378', abs((new PricePlanner($map))->serviceAmount(70, 'Annually', 1) - 378) < 0.001);
$r = $gen->generate('reseller', 1, [['product_key' => 'resellerwindowshosting', 'plan_id' => 3101, 'plan_name' => 'RW1']], opts());
$wm = (new ProductMap())->forProduct($r['created'][0]);
check('Windows reseller: no cPanel blocks option', optionId((int) $wm->id, 'cpanel_blocks') === null && optionId((int) $wm->id, 'whmcs') !== null);

section('cPanel blocks under an undocumented name');
foreach (['additional_cpanel_accounts' => true, 'cpanel_accounts_10' => true, 'extra_cpanel' => true, 'cpanel' => false] as $code => $match) {
    $t2 = pricingDb(['resellerhosting' => ['3001' => ['renew' => ['12' => 9], 'addons' => [$code => 4]]]]);
    (new PriceCatalog($t2))->refresh($acc());
    $hit = OptionBuilder::priceFor('cpanel_blocks', 1, 'resellerhosting', 3001, 'cost');
    check('"' . $code . '" ' . ($match ? 'recognised' : 'NOT taken (that is the cPanel licence)'), $match ? ($hit && $hit['item'] === 'addon:' . $code) : $hit === null);
}
$t = pricingDb(['resellerhosting' => ['3001' => ['renew' => ['12' => 9], 'ssl' => 2, 'addons' => ['cp_pack_x' => 4]]]]);
(new PriceCatalog($t))->refresh($acc());
$r = (new ProductGenerator())->generate('reseller', 1, [['product_key' => 'resellerhosting', 'plan_id' => 3001, 'plan_name' => 'R1']], opts());
$map = (new ProductMap())->forProduct($r['created'][0]);
check('unknown name: option not created, and the generator says why', optionId((int) $map->id, 'cpanel_blocks') === null && (bool) array_filter($r['messages'], static fn ($m) => str_contains($m, 'cPanel Blocks') && str_contains($m, 'cost price list')));
(new ProductMap())->save((int) $map->id, ['price_items' => json_encode(['cpanel_blocks' => 'addon:cp_pack_x'])]);
$e = OptionBuilder::ensure((new ProductMap())->find((int) $map->id));
check('bound to the LogicBoxes item on the product page: created and priced from it', $e['created'] === 1 && optionId((int) $map->id, 'cpanel_blocks')->price_item === 'addon:cp_pack_x');
(new PriceSync())->syncAll('manual');
check('...its price follows that item (4 + 50% = 6)', abs(price('configoptions', (int) optionId((int) $map->id, 'cpanel_blocks')->config_sub_id)->monthly - 6) < 0.001);

section('Band-shaped cost list (Single Domain)');
$t = pricingDb(costFixture());
(new PriceCatalog($t))->refresh($acc());
$r = (new ProductGenerator())->generate('singledomain', 1, [['product_key' => 'singledomainhostinglinuxin', 'plan_id' => 1001, 'plan_name' => 'Starter']], opts());
$p = price('product', $r['created'][0]);
check('monthly cost 60 applied to every tenure the plan is sold in (1, 12, 24 months) + 50%', abs($p->monthly - 90) < 0.001 && abs($p->annually - 1080) < 0.001 && abs($p->biennially - 2160) < 0.001 && (float) $p->quarterly === -1.0);
$sm = (new ProductMap())->forProduct($r['created'][0]);
check('dedicated IP priced from the band\'s ssl cost (90 + 50%)', abs(price('configoptions', (int) optionId((int) $sm->id, 'dedicated_ip')->config_sub_id)->monthly - 135) < 0.001);

section('No made-up prices');
$s = OptionBuilder::specs('reseller', 'resellerhosting', 1, 3001, ['whmcs' => '99', 'cpanel_blocks' => '99'], 'cost');
$items = [];
foreach ($s['specs'] as $sp) {
    $items[$sp['logical']] = $sp['subs'][0]['item'];
}
check('typed-in prices are ignored where LogicBoxes has a price', $items['whmcs'] === 'addon:whmcs' && $items['cpanel_blocks'] === 'addon:cpanel_blocks');
check('only items LogicBoxes never prices take a typed-in price', OptionBuilder::isManualOnly('cloud', 'dedicated_ip') && OptionBuilder::isManualOnly('vps', 'block_storage') && !OptionBuilder::isManualOnly('reseller', 'cpanel_blocks') && !OptionBuilder::isManualOnly('reseller', 'whmcs'));

// ------------------------------------------------------------------------------
section('4. Existing products: missing options created, nothing duplicated');
$noAddons = costFixture();
unset($noAddons['resellerhosting']['3001']['addons']);
$t = pricingDb($noAddons);
(new PriceCatalog($t))->refresh($acc());
// A hand-made product that already has its own "cPanel Blocks" option
Capsule::table('tblproducts')->insert(['id' => 600, 'name' => 'Hand-made R1', 'servertype' => 'lbhreseller', 'configoption1' => '1', 'configoption2' => 'resellerhosting:3001']);
$g = Capsule::table('tblproductconfiggroups')->insertGetId(['name' => 'My options']);
Capsule::table('tblproductconfiglinks')->insert(['gid' => $g, 'pid' => 600]);
$handOpt = Capsule::table('tblproductconfigoptions')->insertGetId(['gid' => $g, 'optionname' => 'cPanel Blocks', 'optiontype' => 4, 'qtyminimum' => 0, 'qtymaximum' => 10, 'order' => 1, 'hidden' => 0]);
$handSub = Capsule::table('tblproductconfigoptionssub')->insertGetId(['configid' => $handOpt, 'optionname' => '10 accounts', 'sortorder' => 0, 'hidden' => 0]);
$mid = (new ProductGenerator())->mapExisting(600, opts());
$map = (new ProductMap())->find($mid);
check('mapped: only the Dedicated IPs option can be priced yet (no add-on prices at LogicBoxes)', array_values(array_unique(array_map(static fn ($o) => $o->logical, ProductMap::options($mid)))) === ['dedicated_ip']);
$countOpts = static fn () => Capsule::table('tblproductconfigoptions')->count();
$before = $countOpts();
// LogicBoxes starts pricing WHMCS and cPanel blocks
$t->script['GET api/products/reseller-cost-price.json'] = [[200, json_encode(costFixture())]];
Settings::override(array_merge(Settings::DEFAULTS, ['upgrade_mode' => 'whmcs']));
$res = (new PriceSync($t))->autoSync(true);
check('automatic sync: the price change is detected and applied', $res['ran'] && $res['changed'] && $res['written'] > 0);
check('missing WHMCS option created by the automatic sync', $res['options'] === 1 && optionId($mid, 'whmcs') !== null && $countOpts() === $before + 1);
check('the cron never takes over the hand-made "cPanel Blocks" option, never adds a second one, and says so', optionId($mid, 'cpanel_blocks') === null && (bool) array_filter($res['warnings'], static fn ($w) => str_contains($w, 'already has an option')));
$r = (new PriceSync())->syncAll('manual');
check('an admin run ADOPTS it (no duplicate): same option, same sub-option', $countOpts() === $before + 1 && (int) optionId($mid, 'cpanel_blocks')->config_option_id === (int) $handOpt && (int) optionId($mid, 'cpanel_blocks')->config_sub_id === (int) $handSub);
check('the adopted option now has the LogicBoxes price (4 + 50% = 6 / block / month)', abs(price('configoptions', (int) $handSub)->monthly - 6) < 0.001 && abs(price('configoptions', (int) $handSub)->triennially - 216) < 0.001);
$subsBefore = Capsule::table('tblproductconfigoptionssub')->count();
$rowsBefore = Capsule::table('mod_lbh_option_map')->count();
for ($i = 0; $i < 3; $i++) {
    OptionBuilder::ensure((new ProductMap())->find($mid));
    (new PriceSync())->syncAll('manual');
}
check('running it again and again creates nothing new', $countOpts() === $before + 1 && Capsule::table('tblproductconfigoptionssub')->count() === $subsBefore && Capsule::table('mod_lbh_option_map')->count() === $rowsBefore);
$g2 = (new ProductGenerator())->generate('reseller', 1, [['product_key' => 'resellerhosting', 'plan_id' => 3001, 'plan_name' => 'R1']], opts());
check('re-running the generator on the mapped plan updates, never duplicates', $g2['created'] === [] && $g2['options'] === 0 && $countOpts() === $before + 1);

section('Options that cannot be adopted');
// a group shared by two products
Capsule::table('tblproducts')->insert(['id' => 601, 'name' => 'Hand-made RW1', 'servertype' => 'lbhreseller', 'configoption1' => '1', 'configoption2' => 'resellerwindowshosting:3101']);
$shared = Capsule::table('tblproductconfiggroups')->insertGetId(['name' => 'Shared']);
Capsule::table('tblproductconfiglinks')->insert(['gid' => $shared, 'pid' => 601]);
Capsule::table('tblproductconfiglinks')->insert(['gid' => $shared, 'pid' => 602]);
$sharedOpt = Capsule::table('tblproductconfigoptions')->insertGetId(['gid' => $shared, 'optionname' => 'WHMCS License', 'optiontype' => 3, 'qtyminimum' => 0, 'qtymaximum' => 1, 'order' => 1, 'hidden' => 0]);
Capsule::table('tblproductconfigoptionssub')->insert(['configid' => $sharedOpt, 'optionname' => 'Yes', 'sortorder' => 0, 'hidden' => 0]);
$m601 = (new ProductGenerator())->mapExisting(601, opts());
$whmcsOpts = Capsule::table('tblproductconfigoptions')->where('optionname', 'WHMCS License')->count();
$groups601 = Capsule::table('tblproductconfiglinks')->where('pid', 601)->pluck('gid')->all();
check('an option in a group shared with another product is neither adopted nor duplicated', optionId($m601, 'whmcs') === null && Capsule::table('tblproductconfigoptions')->whereIn('gid', $groups601)->where('optionname', 'WHMCS License')->count() === 1);
check('...and the sync explains why', (bool) array_filter(OptionBuilder::ensure((new ProductMap())->find($m601))['skipped'], static fn ($w) => str_contains($w, 'shared with other products')));

section('Recurring amounts keep options the module does not manage');
$planner = new PricePlanner((new ProductMap())->find($mid));
$own = Capsule::table('tblproductconfigoptions')->insertGetId(['gid' => $g, 'optionname' => 'Daily Backups', 'optiontype' => 3, 'qtyminimum' => 0, 'qtymaximum' => 1, 'order' => 9, 'hidden' => 0]);
$ownSub = Capsule::table('tblproductconfigoptionssub')->insertGetId(['configid' => $own, 'optionname' => 'Yes', 'sortorder' => 0, 'hidden' => 0]);
Capsule::table('tblpricing')->insert(['type' => 'configoptions', 'relid' => $ownSub, 'currency' => 1, 'msetupfee' => 0, 'qsetupfee' => 0, 'ssetupfee' => 0, 'asetupfee' => 0, 'bsetupfee' => 0, 'tsetupfee' => 0, 'monthly' => 5, 'quarterly' => 15, 'semiannually' => 30, 'annually' => 60, 'biennially' => 120, 'triennially' => 180]);
Capsule::table('tblhosting')->insert(['id' => 90, 'userid' => 1, 'packageid' => 600, 'billingcycle' => 'Annually', 'domain' => 'bk.example.com', 'domainstatus' => 'Active', 'amount' => '0']);
Capsule::table('tblhostingconfigoptions')->insert(['relid' => 90, 'configid' => $own, 'optionid' => $ownSub, 'qty' => 1]);
$plan = $planner->targets()['product'][1]['annually'];
check('service amount = plan + the hand-made option at its own WHMCS price', abs($planner->serviceAmount(90, 'Annually', 1) - ($plan + 60)) < 0.001);

section('Older manual price follows LogicBoxes once it is listed');
Capsule::table('mod_lbh_option_map')->where('map_id', $mid)->where('logical', 'whmcs')->update(['price_item' => 'manual', 'manual_price' => 99]);
$w = (new PriceSync())->preview([$mid]);
check('the sync warns about an option still priced by hand', (bool) array_filter($w['warnings'], static fn ($x) => str_contains($x, 'priced by hand')));
OptionBuilder::ensure((new ProductMap())->find($mid));
check('ensure moves it back to the LogicBoxes item', optionId($mid, 'whmcs')->price_item === 'addon:whmcs' && optionId($mid, 'whmcs')->manual_price === null);

section('No free options from a price of 0');
$t0 = pricingDb(['resellerhosting' => ['3001' => ['renew' => ['12' => 9], 'ssl' => 0, 'addons' => ['whmcs' => 0, 'cpanel_blocks' => 4]]]]);
(new PriceCatalog($t0))->refresh($acc());
$s0 = OptionBuilder::specs('reseller', 'resellerhosting', 1, 3001, [], 'cost');
check('items listed at 0 are not created as free options', array_map(static fn ($x) => $x['logical'], $s0['specs']) === ['cpanel_blocks']);
$tz = pricingDb(['singledomainhostinglinuxin' => ['1002' => ['renew' => ['12' => 0], 'add' => ['12' => 0]]]]);
(new PriceCatalog($tz))->refresh($acc());
$rz = (new ProductGenerator())->generate('singledomain', 1, [['product_key' => 'singledomainhostinglinuxin', 'plan_id' => 1002, 'plan_name' => 'Business']], opts());
check('a plan with no (or only 0) cost price: no product created, the generator says why', $rz['created'] === [] && (bool) array_filter($rz['messages'], static fn ($m) => str_contains($m, 'no price in the LogicBoxes cost price list')));

// ------------------------------------------------------------------------------
section('2. Automatic sync whenever the source price changes');
$t = pricingDb(costFixture());
(new PriceCatalog($t))->refresh($acc());
$r = (new ProductGenerator())->generate('reseller', 1, [['product_key' => 'resellerhosting', 'plan_id' => 3001, 'plan_name' => 'R1']], opts(['update_recurring' => '1']));
$pid = $r['created'][0];
$map = (new ProductMap())->forProduct($pid);
$cb = optionId((int) $map->id, 'cpanel_blocks');
Capsule::table('tblhosting')->insert(['id' => 71, 'userid' => 1, 'packageid' => $pid, 'billingcycle' => 'Annually', 'domain' => 'r2.example.com', 'domainstatus' => 'Active', 'amount' => '378.00']);
Capsule::table('tblhostingconfigoptions')->insert(['relid' => 71, 'configid' => $cb->config_option_id, 'optionid' => $cb->config_sub_id, 'qty' => 3]);
Settings::set('price_sync_interval', '6');
$sync = new PriceSync($t);
$first = $sync->autoSync();
check('first check runs (never checked before)', $first['ran'] === true);
$n = $t->count('GET', 'api/products/reseller-cost-price.json');
check('within the interval nothing is fetched', $sync->autoSync()['ran'] === false && $t->count('GET', 'api/products/reseller-cost-price.json') === $n);
Settings::set('price_sync_last', date('Y-m-d H:i:s', time() - 7 * 3600));
$same = $sync->autoSync();
check('interval passed, prices unchanged: fetched, nothing written', $same['ran'] && !$same['changed'] && $same['written'] === 0);
$t->script['GET api/products/reseller-cost-price.json'] = [[200, json_encode(costFixture(['resellerhosting' => ['3001' => ['renew' => ['12' => 10], 'addons' => ['cpanel_blocks' => 5]]]]))]];
Settings::set('price_sync_last', date('Y-m-d H:i:s', time() - 7 * 3600));
$chg = $sync->autoSync();
check('LogicBoxes raises the plan and block cost: applied at once', $chg['changed'] && $chg['written'] > 0);
check('plan 10/month x 12 + 50% = 180; block 5 + 50% = 7.50 / month (90 / year)', abs(price('product', $pid)->annually - 180) < 0.001 && abs(price('configoptions', (int) $cb->config_sub_id)->annually - 90) < 0.001);
check('existing service follows (update recurring on): 180 + 3 x 90 = 450', abs((float) Capsule::table('tblhosting')->where('id', 71)->value('amount') - 450) < 0.001);
check('every change is logged', Capsule::table('mod_lbh_price_changes')->where('source', 'auto')->count() > 0);
Settings::set('price_sync_enabled', '0');
Settings::set('price_sync_last', date('Y-m-d H:i:s', time() - 7 * 3600));
check('switched off: nothing runs', $sync->autoSync()['ran'] === false);

section('Admin: switch existing products to cost price');
Settings::set('price_sync_enabled', '1');
$t = pricingDb(costFixture());
(new PriceCatalog($t))->refresh($acc());
$r = (new ProductGenerator())->generate('reseller', 1, [['product_key' => 'resellerhosting', 'plan_id' => 3001, 'plan_name' => 'R1']], opts(['price_source' => 'sell', 'markup_pct' => '0']));
$map = (new ProductMap())->forProduct($r['created'][0]);
check('priced from the customer list: no WHMCS / cPanel-block options (that list has none)', $map->price_source === 'sell' && optionId((int) $map->id, 'cpanel_blocks') === null);
$_SESSION = [];
$_GET = ['page' => 'products'];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['do' => 'switch_source', 'markup_pct' => '0', 'lbh_token' => \LbHosting\Support\Csrf::token('admin')];
$out = (new \LbHosting\Admin\AddonController(['modulelink' => 'addonmodules.php?module=lbhosting']))->dispatch();
check('a markup is required to switch to cost', str_contains($out, 'above 0%') && (new ProductMap())->find((int) $map->id)->price_source === 'sell');
$_POST = ['do' => 'switch_source', 'markup_pct' => '40', 'lbh_token' => \LbHosting\Support\Csrf::token('admin')];
$out = (new \LbHosting\Admin\AddonController(['modulelink' => 'addonmodules.php?module=lbhosting']))->dispatch();
$map = (new ProductMap())->find((int) $map->id);
check('switched to cost + 40%, options LogicBoxes prices in the cost list created, nothing written yet', $map->price_source === 'cost' && (float) $map->markup_pct === 40.0 && optionId((int) $map->id, 'cpanel_blocks') !== null && str_contains($out, 'review the pending changes'));
$pending = (new PriceSync())->preview([(int) $map->id]);
check('the new cost-based prices are pending (plan annually 9 x 12 + 40% = 151.20)', (bool) array_filter($pending['changes'], static fn ($c) => $c['target'] === 'product' && $c['cycle'] === 'annually' && (int) $c['currency'] === 1 && abs($c['new'] - 151.2) < 0.001));
$_SERVER['REQUEST_METHOD'] = 'GET';
$_POST = [];
$_GET = ['page' => 'map', 'id' => (string) $map->id];
$html = (new \LbHosting\Admin\AddonController(['modulelink' => 'addonmodules.php?module=lbhosting']))->dispatch();
check('product page shows each option\'s LogicBoxes cost item and lets you choose another', str_contains($html, 'cost price list') && str_contains($html, 'name="bind[cpanel_blocks]"') && str_contains($html, 'addon:cpanel_blocks'));

// ------------------------------------------------------------------------------
section('Clear the price catalog');
$t = pricingDb(costFixture());
(new PriceCatalog($t))->refresh($acc());
$res = ['resellerhosting'];
$rk = array_keys(\LbHosting\Catalog\ProductFamily::keys('reseller'));
$others = count(array_filter(Capsule::table('mod_lbh_prices')->get()->all(), static fn ($r) => !in_array($r->product_key, $rk, true)));
$n = PriceCatalog::clear(1, 'reseller');
check('one product type: its prices and plans removed, the rest kept, raw JSON kept', $n['prices'] > 0 && Capsule::table('mod_lbh_prices')->where('product_key', 'resellerhosting')->count() === 0
    && Capsule::table('mod_lbh_plans')->where('product_key', 'resellerhosting')->count() === 0 && Capsule::table('mod_lbh_prices')->count() === $others && $others > 0 && Capsule::table('mod_lbh_price_snapshots')->count() > 0);
$gen = new ProductGenerator();
(new PriceCatalog($t))->refresh($acc());
$gen->generate('reseller', 1, [['product_key' => 'resellerhosting', 'plan_id' => 3001, 'plan_name' => 'R1']], opts());
$before = Capsule::table('tblpricing')->where('type', 'product')->get()->all();
$n = PriceCatalog::clear(1);
check('everything: all prices, plans and raw JSON of the account removed', $n['prices'] > 0 && Capsule::table('mod_lbh_prices')->count() === 0 && Capsule::table('mod_lbh_plans')->count() === 0 && Capsule::table('mod_lbh_price_snapshots')->count() === 0);
$p = (new PriceSync($t))->preview();
check('price sync with an empty catalog changes nothing and says why', $p['changes'] === [] && (bool) array_filter($p['warnings'], static fn ($w) => str_contains($w, 'No prices found')));
(new PriceSync($t))->syncAll('auto');
check('WHMCS product prices untouched', Capsule::table('tblpricing')->where('type', 'product')->get()->all() == $before);
$r = (new PriceCatalog($t))->refresh($acc());
check('fetching again restores the catalog', $r['cost'] > 0 && Capsule::table('mod_lbh_plans')->where('product_key', 'resellerhosting')->count() > 0);

done();
