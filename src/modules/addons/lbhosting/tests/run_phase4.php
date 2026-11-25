<?php
/**
 * Offline test suite, Phase 4: provider-priced plan / add-on changes
 * (quote -> invoice -> paid -> applied once), the admin queue, and VPS Block
 * Storage. Run: php tests/run_phase4.php
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/fixtures.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use LbHosting\Products\ProductGenerator;
use LbHosting\Products\ProductMap;
use LbHosting\Service\AddonManager;
use LbHosting\Service\BlockStorageService;
use LbHosting\Service\Provisioner;
use LbHosting\Service\ServiceRepository;
use LbHosting\Service\UpgradeService;
use LbHosting\Support\Csrf;
use LbHosting\Support\RedirectSignal;
use LbHosting\Support\Settings;
use LbHosting\WHMCS\ClientAreaController;
use LbHosting\WHMCS\ServerModule;
use LbHosting\WHMCS\ServiceParams;

const SDH = 'api/singledomainhosting/linux/in';
const VPS = 'api/virtualserver/linux/us';

/** Service 20 on $productId, linked to order 9001 with ~200 days left. */
function service(int $productId, string $key, int $planId, float $amount, array $over = []): void
{
    Capsule::table('tblhosting')->insert(array_merge(['id' => 20, 'userid' => 1, 'orderid' => 5, 'packageid' => $productId, 'billingcycle' => 'Annually', 'nextduedate' => '2027-10-01', 'domain' => 'example.com', 'domainstatus' => 'Active', 'amount' => $amount, 'paymentmethod' => 'banktransfer'], $over));
    (new ServiceRepository())->upsert(20, ['order_id' => 9001, 'account_id' => 1, 'product_key' => $key, 'plan_id' => $planId, 'customer_id' => 555, 'tenure_months' => 12, 'priced_amount' => $amount]);
}

function sdh(): array
{
    $t = catalogDb();
    ServerModule::$transport = $t;
    $r = (new ProductGenerator())->generate('singledomain', 1, [
        ['product_key' => 'singledomainhostinglinuxin', 'plan_id' => 1001, 'plan_name' => 'Starter'],
        ['product_key' => 'singledomainhostinglinuxin', 'plan_id' => 1002, 'plan_name' => 'Business'],
    ], genOpts(['hidden' => '']));
    [$starter, $business] = $r['created'];
    service($starter, 'singledomainhostinglinuxin', 1001, 1306.80);
    $t->on('GET', SDH . '/details.json', 200, ['entityid' => '9001', 'currentstatus' => 'Active', 'planid' => '1001', 'customerid' => '555', 'endtime' => (string) (time() + 200 * 86400)]);

    return [$t, $starter, $business, $r];
}

function sorted(array $a): array
{
    sort($a);

    return $a;
}

function upg(int $id): object
{
    return Capsule::table('mod_lbh_upgrades')->where('id', $id)->first();
}

function ipOption(int $productId): int
{
    foreach (ProductMap::options((int) (new ProductMap())->forProduct($productId)->id) as $o) {
        if ($o->logical !== 'os') {
            return (int) $o->config_option_id;
        }
    }

    return 0;
}

// ------------------------------------------------------------------------------
section('Provider mode switches WHMCS upgrades off');
[$t, $starter, $business, $r] = sdh();
check('generator creates no WHMCS upgrade paths', $r['upgrades'] === 0 && Capsule::table('tblproduct_upgrade_products')->count() === 0);
check('configurable-option upgrades off on generated products', (int) Capsule::table('tblproducts')->where('id', $starter)->value('configoptionsupgrade') === 0);
Capsule::table('tblproduct_upgrade_products')->insert(['product_id' => $starter, 'upgrade_product_id' => $business]);
Capsule::table('tblproduct_upgrade_products')->insert(['product_id' => $starter, 'upgrade_product_id' => 999]);
check('enforceMode removes paths between mapped products only', UpgradeService::enforceMode() === 1 && Capsule::table('tblproduct_upgrade_products')->count() === 1);
Settings::override(array_merge(Settings::DEFAULTS, ['upgrade_mode' => 'whmcs']));
check('whmcs mode: enforceMode does nothing, flow disabled', UpgradeService::enforceMode() === 0 && !UpgradeService::enabled());
throwsLike('whmcs mode: quote refused', fn () => (new UpgradeService($t))->quote(20, ['kind' => 'plan', 'product_id' => $business]), 'not available');
Settings::override(Settings::DEFAULTS);

// ------------------------------------------------------------------------------
section('Plan change priced by LogicBoxes (modify-pricing.json)');
$svc = new UpgradeService($t);
$c = $svc->choices(20);
check('choices: the other plan with its annual price, the Dedicated IP option', count($c['plans']) === 1 && $c['plans'][0]['product_id'] === $business && abs($c['plans'][0]['price'] - 2626.8) < 0.01 && count($c['options']) === 1 && $c['options'][0]['logical'] === 'dedicated_ip');
$t->on('GET', SDH . '/modify-pricing.json', 200, ['upgradecost' => 350, 'remainingmonths' => 7]);
$q = $svc->quote(20, ['kind' => 'plan', 'product_id' => $business]);
$mp = $t->last('GET', SDH . '/modify-pricing.json');
check('quote asks LogicBoxes for order 9001 -> plan 1002', $mp['query']['order-id'] === '9001' && $mp['query']['new-plan-id'] === '1002');
check('quote: provider cost 350 for 7 months, +10% markup = 385.00', $q['cost'] === 350.0 && $q['months'] === 7 && $q['amount'] === 385.0 && $q['currency_code'] === 'INR');
check('quote: new recurring = 199 x 12 + 10%', abs($q['recurring'] - 2626.8) < 0.01 && $q['lines'][0] === 'Starter (Linux - India) -> Business (Linux - India)', json_encode($q['lines']));
check('a quote changes nothing', Capsule::table('mod_lbh_upgrades')->count() === 0 && $t->count('POST', SDH . '/modify.json') === 0);
throwsLike('a product that is not offered is refused', fn () => $svc->quote(20, ['kind' => 'plan', 'product_id' => 999]), 'not available');
throwsLike('the current product is refused', fn () => $svc->quote(20, ['kind' => 'plan', 'product_id' => $starter]), 'not available');

$res = $svc->confirm(20, ['kind' => 'plan', 'product_id' => $business]);
$inv = Capsule::table('tblinvoices')->where('id', $res['invoice_id'])->first();
check('confirm raises an unpaid WHMCS invoice for 385.00', $res['invoice_id'] > 0 && !$res['applied'] && (float) $inv->total === 385.0 && $inv->status === 'Unpaid' && str_contains($inv->notes, '7 month(s) remaining'));
check('nothing sent to LogicBoxes before payment', upg($res['upgrade_id'])->status === 'invoiced' && $t->count('POST', SDH . '/modify.json') === 0 && (int) Capsule::table('tblhosting')->where('id', 20)->value('packageid') === $starter);
throwsLike('one open change per service', fn () => $svc->quote(20, ['kind' => 'plan', 'product_id' => $business]), 'already in progress');

$t->on('POST', SDH . '/modify.json', 200, ['actionstatus' => 'Success']);
$t->script['GET ' . SDH . '/details.json'] = [[200, json_encode(['entityid' => '9001', 'currentstatus' => 'Active', 'planid' => '1001', 'customerid' => '555', 'endtime' => (string) (time() + 200 * 86400)])], [200, json_encode(['entityid' => '9001', 'currentstatus' => 'Active', 'planid' => '1002', 'customerid' => '555', 'endtime' => (string) (time() + 200 * 86400)])]];
$svc->onInvoicePaid((int) $res['invoice_id']);
$m = $t->last('POST', SDH . '/modify.json');
$h = Capsule::table('tblhosting')->where('id', 20)->first();
check('InvoicePaid -> modify.json to plan 1002', $m && $m['query']['new-plan-id'] === '1002' && $m['query']['order-id'] === '9001', json_encode(upg($res['upgrade_id'])));
check('service moved to the new product, recurring 2626.80', (int) $h->packageid === $business && abs((float) $h->amount - 2626.8) < 0.01 && upg($res['upgrade_id'])->status === 'applied');
check('recurring change is logged and stays price-sync managed', Capsule::table('mod_lbh_price_changes')->where('target', 'service')->where('relid', 20)->where('source', 'change')->count() === 1 && abs((float) (new ServiceRepository())->get(20)->priced_amount - 2626.8) < 0.01);
$svc->onInvoicePaid((int) $res['invoice_id']);
$svc->apply($res['upgrade_id']);
$svc->apply($res['upgrade_id'], true);
check('paid twice / retried: applied exactly once', $t->count('POST', SDH . '/modify.json') === 1);

// ------------------------------------------------------------------------------
section('Fallback price, failures, retry, cancel');
[$t, $starter, $business] = sdh();
$svc = new UpgradeService($t);
$t->on('GET', SDH . '/modify-pricing.json', 500, ['status' => 'ERROR', 'message' => 'Not supported']);
$q = $svc->quote(20, ['kind' => 'plan', 'product_id' => $business]);
check('modify-pricing unavailable -> catalogue difference x remaining months', in_array($q['months'], [6, 7], true) && abs($q['cost'] - 100 * $q['months']) < 0.001 && abs($q['amount'] - 110 * $q['months']) < 0.001);

$GLOBALS['failCreateInvoice'] = true;
throwsLike('invoice cannot be created -> error, nothing pending', fn () => $svc->confirm(20, ['kind' => 'plan', 'product_id' => $business]), 'could not create the invoice');
$GLOBALS['failCreateInvoice'] = false;
check('the failed attempt is closed, client can try again', $svc->pending(20) === null && Capsule::table('mod_lbh_upgrades')->where('status', 'cancelled')->count() === 1);

$res = $svc->confirm(20, ['kind' => 'plan', 'product_id' => $business]);
$t->on('POST', SDH . '/modify.json', 200, ['status' => 'ERROR', 'message' => 'Insufficient funds in reseller account']);
$svc->onInvoicePaid((int) $res['invoice_id']);
$u = upg($res['upgrade_id']);
check('LogicBoxes refuses a PAID change -> failed with the reason, error logged', $u->status === 'failed' && str_contains((string) $u->error, 'Insufficient funds') && Capsule::table('mod_lbh_logs')->where('action', 'upgrade')->where('level', 'error')->count() === 1);
check('a failed change still blocks a second one', $svc->pending(20) !== null);
$t->script['POST ' . SDH . '/modify.json'] = [[200, json_encode(['actionstatus' => 'Success'])]];
$svc->apply($res['upgrade_id']);
check('retry after fixing the cause -> applied', upg($res['upgrade_id'])->status === 'applied' && (int) Capsule::table('tblhosting')->where('id', 20)->value('packageid') === $business && upg($res['upgrade_id'])->error === null);

[$t, $starter, $business] = sdh();
$svc = new UpgradeService($t);
$t->on('GET', SDH . '/modify-pricing.json', 200, ['upgradecost' => 100, 'remainingmonths' => 7]);
$res = $svc->confirm(20, ['kind' => 'plan', 'product_id' => $business]);
$svc->onInvoiceCancelled((int) $res['invoice_id']);
check('invoice cancelled -> change dropped', upg($res['upgrade_id'])->status === 'cancelled' && $svc->pending(20) === null);
$svc->onInvoicePaid((int) $res['invoice_id']);
check('a cancelled change is never applied', $t->count('POST', SDH . '/modify.json') === 0);
$res = $svc->confirm(20, ['kind' => 'plan', 'product_id' => $business]);
$svc->cancel($res['upgrade_id']);
check('admin cancel of an invoiced change', upg($res['upgrade_id'])->status === 'cancelled');
throwsLike('an applied / cancelled change cannot be cancelled again', fn () => $svc->cancel($res['upgrade_id']), 'Only invoiced or failed');
Capsule::table('mod_lbh_upgrades')->insert(['id' => 90, 'service_id' => 20, 'client_id' => 1, 'kind' => 'plan', 'payload' => json_encode(['new_product_id' => $business]), 'summary' => 'x', 'months' => 7, 'provider_cost' => 1, 'amount' => 1, 'currency_id' => 1, 'status' => 'applying', 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
$svc->apply(90, true);
check('a change being applied right now is not touched', upg(90)->status === 'applying' && $t->count('POST', SDH . '/modify.json') === 0);
Capsule::table('mod_lbh_upgrades')->where('id', 90)->update(['updated_at' => date('Y-m-d H:i:s', time() - 3600)]);
$svc->apply(90);
check('interrupted change: the hook path leaves it alone', upg(90)->status === 'applying');
$t->on('POST', SDH . '/modify.json', 200, ['actionstatus' => 'Success']);
$svc->apply(90, true);
check('interrupted change: admin Retry finishes it', upg(90)->status === 'applied', json_encode(upg(90)));
Capsule::table('tblhosting')->where('id', 20)->update(['domainstatus' => 'Suspended']);
throwsLike('suspended services cannot be changed', fn () => $svc->quote(20, ['kind' => 'options', 'options' => []]), 'Only active');

// ------------------------------------------------------------------------------
section('Add-on changes');
[$t, $starter, $business] = sdh();
$svc = new UpgradeService($t);
$ip = ipOption($starter);
throwsLike('no change -> refused', fn () => $svc->quote(20, ['kind' => 'options', 'options' => [$ip => '0']]), 'Nothing was changed');
throwsLike('non-numeric value refused', fn () => $svc->quote(20, ['kind' => 'options', 'options' => [$ip => '1; DROP']]), 'Invalid value');
throwsLike('options of other products are ignored', fn () => $svc->quote(20, ['kind' => 'options', 'options' => [99999 => '1']]), 'Nothing was changed');
$q = $svc->quote(20, ['kind' => 'options', 'options' => [$ip => '5']]);
check('Dedicated IP on: 150/month x remaining months, +10%', abs($q['cost'] - 150 * $q['months']) < 0.001 && abs($q['amount'] - 165 * $q['months']) < 0.001 && $q['payload']['options'][$ip] === 1);
check('new recurring includes the option (1306.80 + 150 x 12 x 1.1)', abs($q['recurring'] - (1306.8 + 1980)) < 0.01);
$res = $svc->confirm(20, ['kind' => 'options', 'options' => [$ip => '1']]);
check('option not written before payment', Capsule::table('tblhostingconfigoptions')->where('relid', 20)->count() === 0);
$t->on('POST', SDH . '/enable-ssl.json', 200, ['actionstatus' => 'Success']);
$svc->onInvoicePaid((int) $res['invoice_id']);
check('paid -> enable-ssl.json, option stored, recurring updated', $t->count('POST', SDH . '/enable-ssl.json') === 1 && (int) Capsule::table('tblhostingconfigoptions')->where('relid', 20)->where('configid', $ip)->value('qty') === 1 && AddonManager::recorded(20, 'ssl') === 1 && abs((float) Capsule::table('tblhosting')->where('id', 20)->value('amount') - 3286.8) < 0.01, json_encode(upg($res['upgrade_id'])));

// plan change carries the option over to the new product's option
$t->on('GET', SDH . '/modify-pricing.json', 200, ['upgradecost' => 0]);
$t->on('POST', SDH . '/modify.json', 200, ['actionstatus' => 'Success']);
$res = $svc->confirm(20, ['kind' => 'plan', 'product_id' => $business]);
$ip2 = ipOption($business);
check('free plan change applied at once, no invoice', $res['applied'] && $res['invoice_id'] === null && $t->count('POST', SDH . '/modify.json') === 1);
check('option choice carried over to the new product', ServiceParams::configOptions(20) !== [] && (int) Capsule::table('tblhostingconfigoptions')->where('relid', 20)->where('configid', $ip2)->value('qty') === 1 && Capsule::table('tblhostingconfigoptions')->where('relid', 20)->count() === 1);
check('recurring = new plan + option', abs((float) Capsule::table('tblhosting')->where('id', 20)->value('amount') - (2626.8 + 1980)) < 0.01);
check('dedicated IP not ordered twice', $t->count('POST', SDH . '/enable-ssl.json') === 1);

// removal: free, immediate
$t->on('POST', SDH . '/disable-ssl.json', 200, ['actionstatus' => 'Success']);
$res = $svc->confirm(20, ['kind' => 'options', 'options' => [$ip2 => '0']]);
check('removing an add-on: no invoice, no refund, applied at once', $res['applied'] && $res['amount'] === 0.0 && $t->count('POST', SDH . '/disable-ssl.json') === 1 && Capsule::table('tblinvoices')->count() === 1);
check('recurring drops back to the plan price', abs((float) Capsule::table('tblhosting')->where('id', 20)->value('amount') - 2626.8) < 0.01);

// ------------------------------------------------------------------------------
section('VPS: upgrade only, catalogue-priced, Block Storage');
$t = catalogDb();
ServerModule::$transport = $t;
Settings::override(array_merge(Settings::DEFAULTS, ['block_storage_plan_id' => '77']));
$r = (new ProductGenerator())->generate('vps', 1, [
    ['product_key' => 'virtualserverlinuxus', 'plan_id' => 10, 'plan_name' => 'VPS S'],
    ['product_key' => 'virtualserverlinuxus', 'plan_id' => 11, 'plan_name' => 'VPS L'],
], genOpts(['new_group' => 'VPS', 'markup_pct' => '0', 'hidden' => '', 'manual' => ['block_storage' => '0.10']]));
[$vS, $vL] = $r['created'];
service($vL, 'virtualserverlinuxus', 11, 240.0, ['billingcycle' => 'Monthly', 'amount' => 20.0, 'domain' => 'srv1.example.com']);
$vd = ['entityid' => '9001', 'currentstatus' => 'Active', 'planid' => '11', 'customerid' => '555', 'endtime' => (string) (time() + 20 * 86400)];
$t->on('GET', VPS . '/details.json', 200, $vd);
$svc = new UpgradeService($t);
check('VPS L: no downgrade offered', $svc->choices(20)['plans'] === []);
Capsule::table('tblhosting')->where('id', 20)->update(['packageid' => $vS]);
(new ServiceRepository())->upsert(20, ['plan_id' => 10]);
$t->script['GET ' . VPS . '/details.json'] = [[200, json_encode(['planid' => '10'] + $vd)]];
$c = $svc->choices(20);
check('VPS S: upgrade to L offered; OS is not a changeable option', count($c['plans']) === 1 && $c['plans'][0]['product_id'] === $vL && !in_array('os', array_column($c['options'], 'logical'), true) && in_array('block_storage', array_column($c['options'], 'logical'), true));
$q = $svc->quote(20, ['kind' => 'plan', 'product_id' => $vL]);
check('VPS plan quote: (20 - 10) x 1 remaining month, no modify-pricing call', $q['amount'] === 10.0 && $q['months'] === 1 && $t->count('GET', VPS . '/modify-pricing.json') === 0);

$bs = 0;
foreach ($c['options'] as $o) {
    if ($o['logical'] === 'block_storage') {
        $bs = $o['config_id'];
    }
}
throwsLike('Block Storage below 10 GB refused', fn () => $svc->quote(20, ['kind' => 'options', 'options' => [$bs => '5']]), 'between 10 and 500');
throwsLike('Block Storage above the option maximum refused', fn () => $svc->quote(20, ['kind' => 'options', 'options' => [$bs => '900']]), 'maximum is 500');
$q = $svc->quote(20, ['kind' => 'options', 'options' => [$bs => '50']]);
check('50 GB x 0.10 x 1 month = 5.00', $q['amount'] === 5.0 && $q['lines'] === ['Block Storage: 0 -> 50']);
$res = $svc->confirm(20, ['kind' => 'options', 'options' => [$bs => '50']]);
$t->on('POST', 'api/blockstorage/us/add.json', 200, ['entityid' => '9500', 'actionstatus' => 'Success']);
$svc->onInvoicePaid((int) $res['invoice_id']);
$a = $t->last('POST', 'api/blockstorage/us/add.json');
check('paid -> blockstorage/us/add.json attached to the VPS order', $a && $a['query']['storage-size-gb'] === '50' && $a['query']['attach-order-id'] === '9001' && $a['query']['plan-id'] === '77' && $a['query']['customer-id'] === '555' && $a['query']['months'] === '1', json_encode(upg($res['upgrade_id'])));
$vol = BlockStorageService::volume(20);
check('volume recorded (order 9500, 50 GB)', $vol && (int) $vol->order_id === 9500 && (int) $vol->size_gb === 50 && upg($res['upgrade_id'])->status === 'applied');
check('VPS recurring: 10 + 50 x 0.10 = 15.00', abs((float) Capsule::table('tblhosting')->where('id', 20)->value('amount') - 15.0) < 0.001);

$q = $svc->quote(20, ['kind' => 'options', 'options' => [$bs => '80']]);
check('growing 50 -> 80 charges the 30 GB added', $q['amount'] === 3.0);
$res = $svc->confirm(20, ['kind' => 'options', 'options' => [$bs => '80']]);
$t->on('POST', 'api/blockstorage/us/upgrade.json', 200, ['actionstatus' => 'Success']);
$svc->onInvoicePaid((int) $res['invoice_id']);
$u = $t->last('POST', 'api/blockstorage/us/upgrade.json');
check('upgrade.json gets the FINAL size on the volume order', $u && $u['query']['storage-size-gb'] === '80' && $u['query']['order-id'] === '9500' && (int) BlockStorageService::volume(20)->size_gb === 80 && $t->count('POST', 'api/blockstorage/us/add.json') === 1);
throwsLike('shrinking a volume is refused before any invoice', fn () => $svc->quote(20, ['kind' => 'options', 'options' => [$bs => '40']]), 'cannot be made smaller');

// lifecycle follows the server
$p = new Provisioner('vps', null, null, $t);
$t->on('POST', 'api/orders/suspend.json', 200, ['actionstatus' => 'Success']);
$t->on('POST', 'api/orders/unsuspend.json', 200, ['actionstatus' => 'Success']);
$p->suspend(ServiceParams::build(20) + ['suspendreason' => 'Overdue']);
$sus = array_values(array_filter($t->calls, static fn ($c) => $c['path'] === 'api/orders/suspend.json'));
check('suspend: server order and volume order', count($sus) === 2 && sorted(array_map(static fn ($c) => $c['query']['order-id'], $sus)) === ['9001', '9500'] && BlockStorageService::volume(20)->status === 'Suspended');
$p->unsuspend(ServiceParams::build(20));
check('unsuspend: volume active again', BlockStorageService::volume(20)->status === 'Active');
$t->on('POST', VPS . '/renew.json', 200, ['actionstatus' => 'Success']);
$t->on('POST', 'api/blockstorage/us/renew.json', 200, ['actionstatus' => 'Success']);
Capsule::table('mod_lbh_services')->where('service_id', 20)->update(['created_at' => date('Y-m-d H:i:s', time() - 86400 * 30)]);
$p->renew(ServiceParams::build(20), 'test');
$rn = $t->last('POST', 'api/blockstorage/us/renew.json');
check('renew: the volume is renewed for the same term', $rn && $rn['query']['order-id'] === '9500' && $rn['query']['months'] === '1');
$t->script['POST api/blockstorage/us/renew.json'] = [[200, json_encode(['status' => 'ERROR', 'message' => 'boom'])]];
Capsule::table('mod_lbh_renewals')->delete();
$ok = true;
try {
    $p->renew(ServiceParams::build(20), 'test', true);
} catch (\Throwable $e) {
    $ok = false;
}
check('a volume renewal failure never fails the server renewal, but is logged', $ok && Capsule::table('mod_lbh_logs')->where('action', 'storage')->where('level', 'error')->count() === 1);

Settings::override(array_merge(Settings::DEFAULTS, ['block_storage_plan_id' => '77', 'addon_removal' => '0']));
throwsLike('"Add-on removal" off: clients cannot remove the volume (it would stay billed at LogicBoxes)', fn () => $svc->quote(20, ['kind' => 'options', 'options' => [$bs => '0']]), 'cannot be reduced online');
Settings::override(array_merge(Settings::DEFAULTS, ['block_storage_plan_id' => '77', 'addon_removal' => '1']));
$t->on('POST', 'api/blockstorage/us/delete.json', 200, ['actionstatus' => 'Success']);
$res = $svc->confirm(20, ['kind' => 'options', 'options' => [$bs => '0']]);
check('removal: free, applied at once, delete.json for the volume', $res['applied'] && $res['invoice_id'] === null && ($t->last('POST', 'api/blockstorage/us/delete.json')['query']['order-id'] ?? '') === '9500' && BlockStorageService::volume(20)->status === 'Deleted');
check('recurring back to the plan price', abs((float) Capsule::table('tblhosting')->where('id', 20)->value('amount') - 10.0) < 0.001);

// ------------------------------------------------------------------------------
section('Client area: POST flow');
[$t, $starter, $business] = sdh();
$t->on('GET', SDH . '/modify-pricing.json', 200, ['upgradecost' => 350, 'remainingmonths' => 7]);
$cp = fn () => ClientAreaController::render('singledomain', ServiceParams::build(20) + ['status' => 'Active']);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_POST = [];
$v = $cp()['vars'] ?? $cp();
$v = $v['vars'] ?? $v;
check('GET: choices and a token', is_array($v['lbh_upgrade']) && count($v['lbh_upgrade']['plans']) === 1 && $v['lbh_token'] !== '' && $v['lbh_quote'] === null, json_encode(array_keys($v)));
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['lbh_action' => 'upgrade_confirm', 'lbh_kind' => 'plan', 'lbh_product' => (string) $business, 'lbh_token' => 'forged'];
$v = $cp();
$v = $v['vars'] ?? $v;
check('POST with a bad token: refused, nothing created', $v['lbh_error'] !== '' && Capsule::table('mod_lbh_upgrades')->count() === 0);
$_POST = ['lbh_action' => 'upgrade_confirm', 'lbh_kind' => 'plan', 'lbh_product' => (string) $business, 'lbh_token' => Csrf::token('client21')];
$v = $cp();
$v = $v['vars'] ?? $v;
check('a token for another service is refused', $v['lbh_error'] !== '' && Capsule::table('mod_lbh_upgrades')->count() === 0);
$_POST = ['lbh_action' => 'upgrade_quote', 'lbh_kind' => 'plan', 'lbh_product' => (string) $business, 'lbh_token' => Csrf::token('client20')];
$v = $cp();
$v = $v['vars'] ?? $v;
check('quote POST: amount, months text, nothing created', is_array($v['lbh_quote']) && $v['lbh_quote']['amount'] === '385.00' && str_contains($v['lbh_quote']['months_text'], '7') && $v['lbh_quote']['product_id'] === $business && Capsule::table('mod_lbh_upgrades')->count() === 0);
$_POST = ['lbh_action' => 'upgrade_quote', 'lbh_kind' => 'plan', 'lbh_product' => '999', 'lbh_token' => Csrf::token('client20')];
$v = $cp();
$v = $v['vars'] ?? $v;
check('quote for a product that is not offered: client-safe error', str_contains($v['lbh_error'], 'not available'));
$_POST = ['lbh_action' => 'upgrade_confirm', 'lbh_kind' => 'plan', 'lbh_product' => (string) $business, 'lbh_token' => Csrf::token('client20')];
$to = '';
try {
    $cp();
} catch (RedirectSignal $e) {
    $to = $e->getMessage();
}
$invId = (int) Capsule::table('mod_lbh_upgrades')->value('invoice_id');
check('confirm POST: redirect to the invoice', $invId > 0 && str_contains($to, 'viewinvoice.php?id=' . $invId), $to);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_POST = [];
$v = $cp();
$v = $v['vars'] ?? $v;
check('afterwards: pending change with a pay link', ($v['lbh_upgrade']['pending']['invoice_id'] ?? 0) === $invId && $v['lbh_upgrade']['pending']['status'] === 'invoiced');
Settings::override(array_merge(Settings::DEFAULTS, ['upgrade_mode' => 'whmcs']));
$v = $cp();
$v = $v['vars'] ?? $v;
check('whmcs mode: no change form', $v['lbh_upgrade'] === null);

done();
