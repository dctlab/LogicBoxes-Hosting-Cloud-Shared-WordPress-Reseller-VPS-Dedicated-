<?php
/**
 * Offline test suite, Phase 6: Import Manager (scan, match, link, import) and
 * the signed self-updater (manifest, package checks, install, rollback).
 * Run: php tests/run_phase6.php
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/fixtures.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use LbHosting\Accounts\AccountRepository;
use LbHosting\Import\Importer;
use LbHosting\Import\OrderScanner;
use LbHosting\Import\ProviderExtras;
use LbHosting\Products\ProductGenerator;
use LbHosting\Service\AddonManager;
use LbHosting\Service\Provisioner;
use LbHosting\Service\ServiceRepository;
use LbHosting\Support\Settings;
use LbHosting\Update\Manifest;
use LbHosting\Update\Updater;
use LbHosting\WHMCS\ServerModule;
use LbHosting\WHMCS\ServiceParams;

const SDH = 'api/singledomainhosting/linux/in';
const VPS = 'api/virtualserver/linux/us';

function imp(int $orderId): object
{
    return Capsule::table('mod_lbh_import_orders')->where('order_id', $orderId)->first();
}

// ------------------------------------------------------------------------------
section('Search response parsing (shape is not documented)');
check('numbered map with orders.orderid', OrderScanner::orderIds(['recsonpage' => '2', 'recsindb' => '2', '1' => ['orders.orderid' => '101', 'entity.description' => 'a.com'], '2' => ['orders.orderid' => '102']]) === [101, 102]);
check('plain list with orderid / entityid', OrderScanner::orderIds([['orderid' => 5], ['entityid' => '6']]) === [5, 6]);
check('wrapped list', OrderScanner::orderIds(['result' => [['order-id' => '7']], 'status' => 'Success']) === [7]);
check('empty / garbage', OrderScanner::orderIds([]) === [] && OrderScanner::orderIds(['recsonpage' => '0', 'recsindb' => '0']) === []);

section('Provider extras');
$x = ProviderExtras::read('vps', ['os' => 'Alma_9', 'addons' => [['addon_name' => 'cpanel', 'addOnID' => 1], ['addon_name' => 'ipaddress', 'addOnID' => 2], ['addon_name' => 'ipaddress', 'addOnID' => 3], ['addon_name' => 'whmcs', 'addOnID' => 4]]]);
check('VPS: OS, add-on counts, WHMCS', $x === ['os' => 'alma_9', 'addons' => ['cpanel' => 1, 'ipaddress' => 2, 'whmcs' => 1], 'dedicated_ips' => 0, 'ssl' => false, 'whmcs' => true] && ProviderExtras::summary($x) === 'alma_9, cpanel, ipaddress x2, whmcs');
$x = ProviderExtras::read('reseller', ['dedicatedips' => ['198.51.100.1', '198.51.100.2'], 'whmcslicensekey' => 'WHMCS-RES123456789']);
check('Reseller: dedicated IP count and licence', $x['dedicated_ips'] === 2 && $x['whmcs'] === true && $x['ssl'] === false);
check('Hosting: a dedicated IP on the order means the SSL / dedicated IP option', ProviderExtras::read('singledomain', ['dedicatedip' => '198.51.100.9'])['ssl'] === true && ProviderExtras::read('singledomain', ['ipaddress' => '203.0.113.9'])['ssl'] === false);

// ------------------------------------------------------------------------------
section('Scan');
$t = catalogDb();
ServerModule::$transport = $t;
$gen = new ProductGenerator();
[$pStarter] = $gen->generate('singledomain', 1, [['product_key' => 'singledomainhostinglinuxin', 'plan_id' => 1001, 'plan_name' => 'Starter']], genOpts(['markup_pct' => '0']))['created'];
[$pVps] = $gen->generate('vps', 1, [['product_key' => 'virtualserverlinuxus', 'plan_id' => 10, 'plan_name' => 'VPS S']], genOpts(['new_group' => 'VPS', 'markup_pct' => '0']))['created'];
Capsule::table('tblpaymentgateways')->insert(['gateway' => 'banktransfer', 'setting' => 'name', 'value' => 'Bank Transfer', 'order' => 1]);
Capsule::table('tblclients')->insert(['id' => 2, 'email' => 'bob@example.org', 'currency' => 1]);
// An existing, unlinked WHMCS service for old.com (client 1) - should be suggested for linking
Capsule::table('tblhosting')->insert(['id' => 30, 'userid' => 1, 'orderid' => 5, 'packageid' => $pStarter, 'billingcycle' => 'Annually', 'nextduedate' => '2027-01-01', 'domain' => 'old.com', 'domainstatus' => 'Active', 'amount' => '1188.00']);
// A service already linked to order 103
Capsule::table('tblhosting')->insert(['id' => 31, 'userid' => 1, 'orderid' => 5, 'packageid' => $pStarter, 'billingcycle' => 'Annually', 'nextduedate' => '2027-01-01', 'domain' => 'linked.com', 'domainstatus' => 'Active', 'amount' => '1188.00']);
(new ServiceRepository())->upsert(31, ['family' => 'singledomain', 'order_id' => 103, 'account_id' => 1, 'product_key' => 'singledomainhostinglinuxin']);

$end = time() + 120 * 86400;
$t->on('GET', SDH . '/search.json', 200, ['recsonpage' => '4', 'recsindb' => '4',
    '1' => ['orders.orderid' => '101'], '2' => ['orders.orderid' => '102'], '3' => ['orders.orderid' => '103'], '4' => ['orders.orderid' => '104']]);
$details = [
    101 => ['entityid' => '101', 'domainname' => 'New-Site.com', 'currentstatus' => 'Active', 'planid' => '1001', 'customerid' => '700', 'endtime' => (string) $end, 'dedicatedip' => '198.51.100.9', 'siteadminpassword' => 'TopSecret1!'],
    102 => ['entityid' => '102', 'domainname' => 'old.com', 'currentstatus' => 'Suspended', 'planid' => '1001', 'customerid' => '555', 'endtime' => (string) $end],
    103 => ['entityid' => '103', 'domainname' => 'linked.com', 'currentstatus' => 'Active', 'planid' => '1001', 'customerid' => '555', 'endtime' => (string) $end],
    104 => ['entityid' => '104', 'domainname' => 'gone.com', 'currentstatus' => 'Deleted', 'planid' => '1001', 'customerid' => '555'],
];
foreach ($details as $d) {
    $t->on('GET', SDH . '/details.json', 200, $d);
}
$t->on('GET', 'api/customers/details-by-id.json', 200, ['customerid' => '700', 'username' => 'Bob@Example.org', 'name' => 'Bob Builder']);
$t->on('GET', 'api/customers/details-by-id.json', 200, ['customerid' => '555', 'username' => 'jane@example.com', 'name' => 'Jane Doe']);
$acc = (new AccountRepository())->find(1);
$r = (new OrderScanner($t))->scan($acc, ['singledomain'], ['Active', 'Suspended']);
check('scan: 3 kept (the deleted order is dropped), all new', $r['found'] === 3 && $r['new'] === 3 && $r['updated'] === 0, json_encode($r));
check('other locations with no orders are not reported as errors unless LogicBoxes says why', count($r['errors']) > 0 && !array_filter($r['errors'], static fn ($e) => str_starts_with($e, 'Linux - India')), json_encode($r['errors']));
check('status filter is sent to LogicBoxes', $t->last('GET', SDH . '/search.json')['query']['status'] === ['Active', 'Suspended']);
$o101 = imp(101);
check('order 101: lower-cased domain, plan, expiry, customer matched to the WHMCS client by email, mapped product', $o101->domain === 'new-site.com' && (int) $o101->plan_id === 1001 && substr($o101->expires_at, 0, 10) === date('Y-m-d', $end) && $o101->customer_email === 'bob@example.org' && (int) $o101->match_client_id === 2 && (int) $o101->match_product_id === $pStarter && $o101->match_service_id === null && $o101->state === 'new');
check('no secret is staged', !str_contains(json_encode(Capsule::table('mod_lbh_import_orders')->get()->all()), 'TopSecret1!'));
check('order 102: existing unlinked service with the same domain suggested', (int) imp(102)->match_service_id === 30 && imp(102)->lb_status === 'Suspended');
check('order 103: already linked', imp(103)->state === 'linked' && (int) imp(103)->match_service_id === 31);
check('one customer lookup per customer', $t->count('GET', 'api/customers/details-by-id.json') === 2);

// a service with the same domain that belongs to ANOTHER client must not be suggested
Capsule::table('tblhosting')->insert(['id' => 32, 'userid' => 1, 'orderid' => 5, 'packageid' => $pStarter, 'billingcycle' => 'Annually', 'nextduedate' => '2027-01-01', 'domain' => 'new-site.com', 'domainstatus' => 'Active', 'amount' => '1188.00']);
$t->script['GET ' . SDH . '/details.json'] = [[200, json_encode($details[101])], [200, json_encode($details[102])], [200, json_encode($details[104])]];
$t->script['GET api/customers/details-by-id.json'] = [[200, json_encode(['customerid' => '700', 'username' => 'bob@example.org', 'name' => 'Bob Builder'])], [200, json_encode(['customerid' => '555', 'username' => 'jane@example.com', 'name' => 'Jane Doe'])]];
$n = $t->count('GET', SDH . '/details.json');
$r = (new OrderScanner($t))->scan($acc, ['singledomain'], ['Active', 'Suspended']);
check('re-scan: handled orders are not read again; the others are refreshed', $r['skipped'] === 1 && $r['updated'] === 2 && $r['new'] === 0 && $t->count('GET', SDH . '/details.json') === $n + 3, json_encode($r));
check('a same-domain service of a different client is never suggested', imp(101)->match_service_id === null && (int) imp(101)->match_client_id === 2);
Capsule::table('tblhosting')->where('id', 32)->delete();

// ------------------------------------------------------------------------------
section('Link');
$im = new Importer($t);
$before = count($t->calls);
$sid = $im->link((int) imp(102)->id);
$row = (new ServiceRepository())->get(30);
check('link attaches the order to the suggested service', $sid === 30 && (int) $row->order_id === 102 && $row->family === 'singledomain' && (int) $row->customer_id === 555 && imp(102)->state === 'linked');
check('linking calls nothing at LogicBoxes', count($t->calls) === $before);
check('the service\'s own options become the add-on baseline', AddonManager::recorded(30, 'ssl') === 0);
throwsLike('an order cannot be linked to a second service', fn () => $im->link((int) imp(102)->id, 31), 'already linked');
throwsLike('a service of another product module is refused', function () use ($im, $pVps) {
    Capsule::table('tblhosting')->insert(['id' => 40, 'userid' => 2, 'orderid' => 5, 'packageid' => $pVps, 'billingcycle' => 'Monthly', 'domain' => 'srv.example.org', 'domainstatus' => 'Active', 'amount' => '10.00']);
    $im->link((int) imp(101)->id, 40);
}, 'does not use the Single Domain Hosting module');

section('Import');
throwsLike('billing cycle is validated', fn () => $im->import((int) imp(101)->id, ['billingcycle' => 'Weekly', 'paymentmethod' => 'banktransfer']), 'billing cycle');
check('a failed attempt is marked, with the reason', imp(101)->state === 'error' && str_contains(imp(101)->message, 'billing cycle'));
throwsLike('payment method must be an active gateway', fn () => $im->import((int) imp(101)->id, ['billingcycle' => 'Annually', 'paymentmethod' => 'nope']), 'payment method');
$GLOBALS['localApi'] = [];
$before = count($t->calls);
$sid = $im->import((int) imp(101)->id, ['product_id' => $pVps, 'billingcycle' => 'Annually', 'paymentmethod' => 'banktransfer', 'set_amount' => true]);
$h = Capsule::table('tblhosting')->where('id', $sid)->first();
$add = array_values(array_filter($GLOBALS['localApi'], static fn ($c) => $c[0] === 'AddOrder'))[0][1];
$accept = array_values(array_filter($GLOBALS['localApi'], static fn ($c) => $c[0] === 'AcceptOrder'))[0][1];
check('the mapped product wins over a product chosen in the form', $add['pid'] === [$pStarter]);
check('AddOrder: mapped product, matched client, no invoice, no emails', $add['pid'] === [$pStarter] && $add['clientid'] === 2 && $add['noinvoice'] === true && $add['noemail'] === true && $add['noinvoiceemail'] === true && $add['billingcycle'] === ['annually']);
check('AcceptOrder without module setup or welcome email', $accept['autosetup'] === false && $accept['sendemail'] === false);
check('service: Active, next due date = LogicBoxes expiry', $h->domainstatus === 'Active' && $h->nextduedate === date('Y-m-d', $end) && $h->nextinvoicedate === $h->nextduedate && (int) $h->userid === 2 && $h->domain === 'new-site.com');
check('nothing is sent to LogicBoxes by an import', count($t->calls) === $before);
$opt = Capsule::table('tblhostingconfigoptions')->where('relid', $sid)->first();
check('the dedicated IP on the order becomes the Dedicated IP option', $opt && (int) $opt->qty === 1 && AddonManager::recorded($sid, 'ssl') === 1);
check('recurring amount from the price map: plan 99 x 12 + dedicated IP 150 x 12', abs((float) $h->amount - (1188 + 1800)) < 0.01 && abs((float) (new ServiceRepository())->get($sid)->priced_amount - 2988) < 0.01);
check('linked and marked imported', (int) (new ServiceRepository())->get($sid)->order_id === 101 && imp(101)->state === 'imported' && (int) imp(101)->match_service_id === $sid);
throwsLike('a second import of the same order is refused', fn () => $im->import((int) imp(101)->id, ['billingcycle' => 'Annually', 'paymentmethod' => 'banktransfer']), 'already imported');

// The baseline means the first module call changes nothing at LogicBoxes
$t->script['GET ' . SDH . '/details.json'] = [[200, json_encode($details[101])]];
$n = count($t->calls);
(new Provisioner('singledomain', null, null, $t))->reconcile(ServiceParams::build($sid));
check('Reconcile after import: only reads, orders nothing', $t->count('POST', SDH . '/enable-ssl.json') === 0 && $t->count('POST', SDH . '/disable-ssl.json') === 0 && array_filter(array_slice($t->calls, $n), static fn ($c) => $c['method'] !== 'GET') === []);
// and a renewal invoice paid tomorrow is not blocked by the "new order" guard
$t->on('POST', SDH . '/renew.json', 200, ['actionstatus' => 'Success']);
check('renewal of an imported service is not skipped as "just created"', (new Provisioner('singledomain', null, null, $t))->renew(ServiceParams::build($sid), 'hook') !== 'skipped' && $t->count('POST', SDH . '/renew.json') === 1);

section('Import: new client, suspended order, unmapped plan, VPS options');
$t->on('GET', VPS . '/search.json', 200, [['orderid' => 201], ['orderid' => 202]]);
$t->on('GET', VPS . '/details.json', 200, ['entityid' => '201', 'domainname' => 'srv9.example.net', 'currentstatus' => 'Suspended', 'planid' => '10', 'customerid' => '800', 'endtime' => (string) $end, 'os' => 'rocky_9',
    'addons' => [['addon_name' => 'cpanel', 'addOnID' => 1, 'isPaid' => true], ['addon_name' => 'ipaddress', 'addOnID' => 2, 'isPaid' => true], ['addon_name' => 'ipaddress', 'addOnID' => 3, 'isPaid' => true]]]);
$t->on('GET', VPS . '/details.json', 200, ['entityid' => '202', 'domainname' => 'srv10.example.net', 'currentstatus' => 'Active', 'planid' => '999', 'customerid' => '800', 'endtime' => (string) $end]);
$t->script['GET api/customers/details-by-id.json'] = [[200, json_encode(['customerid' => '800', 'username' => 'carol@example.net', 'name' => 'Carol Ann Smith', 'company' => 'Carol Ltd', 'address1' => '1 High St', 'city' => 'Pune', 'state' => 'MH', 'zip' => '411001', 'country' => 'in', 'telnocc' => '91', 'telno' => '9000000000'])]];
$r = (new OrderScanner($t))->scan($acc, ['vps'], ['Active', 'Suspended']);
check('VPS scan: 2 orders, unknown customer, plan 999 has no mapped product', $r['new'] === 2 && imp(201)->match_client_id === null && imp(201)->customer_email === 'carol@example.net' && (int) imp(201)->match_product_id === $pVps && imp(202)->match_product_id === null);
throwsLike('unknown client and "create missing clients" off -> refused', fn () => $im->import((int) imp(201)->id, ['billingcycle' => 'Monthly', 'paymentmethod' => 'banktransfer']), 'No WHMCS client matches carol@example.net');
$GLOBALS['localApi'] = [];
$sid = $im->import((int) imp(201)->id, ['billingcycle' => 'Monthly', 'paymentmethod' => 'banktransfer', 'create_client' => true, 'set_amount' => true]);
$client = array_values(array_filter($GLOBALS['localApi'], static fn ($c) => $c[0] === 'AddClient'))[0][1];
check('client created from LogicBoxes customer details, without a welcome email', $client['email'] === 'carol@example.net' && $client['firstname'] === 'Carol' && $client['lastname'] === 'Ann Smith' && $client['country'] === 'IN' && $client['phonenumber'] === '91.9000000000' && $client['noemail'] === true && strlen($client['password2']) === 24);
$h = Capsule::table('tblhosting')->where('id', $sid)->first();
check('suspended at LogicBoxes -> Suspended in WHMCS with a reason', $h->domainstatus === 'Suspended' && str_contains((string) $h->suspendreason, 'LogicBoxes'));
$co = ServiceParams::configOptions($sid);
check('VPS options mirror the order: OS, control panel, 2 IPs', ($co['Operating System'] ?? '') === 'rocky_9' && ($co['Control Panel'] ?? '') === 'cpanel' && (int) ($co['Dedicated IP'] ?? 0) === 2, json_encode($co));
check('baseline recorded: cPanel x1, ipaddress x2', AddonManager::recorded($sid, 'addon:cpanel') === 1 && AddonManager::recorded($sid, 'addon:ipaddress') === 2);
check('recurring: plan 10 + cPanel 20 + 2 IPs x 3 = 36.00', abs((float) $h->amount - 36.0) < 0.01);
throwsLike('a chosen product of another module is refused', fn () => $im->import((int) imp(202)->id, ['product_id' => $pStarter, 'billingcycle' => 'Monthly', 'paymentmethod' => 'banktransfer']), 'No VPS');
throwsLike('unmapped plan needs a product to be chosen', fn () => $im->import((int) imp(202)->id, ['billingcycle' => 'Monthly', 'paymentmethod' => 'banktransfer']), 'No VPS');
$sid2 = $im->import((int) imp(202)->id, ['product_id' => $pVps, 'billingcycle' => 'Monthly', 'paymentmethod' => 'banktransfer', 'set_amount' => false]);
check('chosen product used; existing client (just created) reused; amount left alone', (int) Capsule::table('tblhosting')->where('id', $sid2)->value('packageid') === $pVps && (int) Capsule::table('tblhosting')->where('id', $sid2)->value('userid') === (int) $h->userid && Capsule::table('tblclients')->where('email', 'carol@example.net')->count() === 1 && (float) Capsule::table('tblhosting')->where('id', $sid2)->value('amount') === 0.0);

section('Import: safety rules');
$mk = static function (int $id, int $orderId, array $o = []) use ($pStarter): void {
    Capsule::table('mod_lbh_import_orders')->insert($o + ['id' => $id, 'account_id' => 1, 'family' => 'singledomain', 'product_key' => 'singledomainhostinglinuxin', 'order_id' => $orderId, 'domain' => 'safe' . $id . '.com', 'customer_id' => 555, 'customer_email' => 'jane@example.com', 'lb_status' => 'Active', 'plan_id' => 1001, 'expires_at' => '2027-06-01 00:00:00', 'match_client_id' => 1, 'match_product_id' => $pStarter, 'state' => 'new', 'scanned_at' => date('Y-m-d H:i:s')]);
};
$io = ['billingcycle' => 'Annually', 'paymentmethod' => 'banktransfer'];
$mk(80, 401, ['lb_status' => 'InActive']);
throwsLike('only Active and Suspended orders are imported', fn () => $im->import(80, $io), 'Only Active and Suspended');
$mk(81, 402, ['expires_at' => null]);
throwsLike('no expiry from LogicBoxes -> refused (WHMCS would invoice it at once)', fn () => $im->import(81, $io), 'expiry date');
$mk(82, 403);
Capsule::table('tblproducts')->where('id', $pStarter)->update(['autosetup' => 'order']);
throwsLike('a product that provisions on order is refused', fn () => $im->import(82, $io), 'as soon as an order is placed');
Capsule::table('tblproducts')->where('id', $pStarter)->update(['autosetup' => 'payment']);
check('none of the refused imports created a service', Capsule::table('tblhosting')->whereIn('domain', ['safe80.com', 'safe81.com', 'safe82.com'])->count() === 0);
// the same order reachable through a second API account of the same reseller
$mk(83, 101, ['account_id' => 2]);
throwsLike('an order already linked through another API account is refused', fn () => $im->import(83, $io + ['product_id' => $pStarter]), 'already linked');
// name-only suggestion: unknown customer, one service with the same hostname
Capsule::table('tblhosting')->insert(['id' => 60, 'userid' => 1, 'orderid' => 5, 'packageid' => $pStarter, 'billingcycle' => 'Annually', 'nextduedate' => '2027-01-01', 'domain' => 'shared-name.com', 'domainstatus' => 'Active', 'amount' => '1188.00']);
$m = \LbHosting\Import\Matcher::match(1, 'singledomain', 'singledomainhostinglinuxin', 501, ['domain' => 'shared-name.com', 'customer_email' => 'stranger@nowhere.test', 'plan_id' => 1001]);
check('unknown customer + same name: suggested as "domain" only', $m['match_service_id'] === 60 && $m['match_by'] === 'domain' && $m['match_client_id'] === null);
$mk(84, 501, ['domain' => 'shared-name.com', 'customer_email' => 'stranger@nowhere.test', 'match_client_id' => null, 'match_service_id' => 60, 'match_by' => 'domain']);
throwsLike('a name-only match is never linked without an explicit confirmation', fn () => $im->link(84), 'only has the same name');
check('...and stays unlinked', (new ServiceRepository())->get(60) === null);
check('with the admin\'s confirmation it links', $im->link(84, null, true) === 60 && (int) (new ServiceRepository())->get(60)->order_id === 501);
Capsule::table('tblhosting')->insert(['id' => 61, 'userid' => 2, 'orderid' => 5, 'packageid' => $pStarter, 'billingcycle' => 'Annually', 'domain' => 'twice.com', 'domainstatus' => 'Active', 'amount' => '1.00']);
Capsule::table('tblhosting')->insert(['id' => 62, 'userid' => 1, 'orderid' => 5, 'packageid' => $pStarter, 'billingcycle' => 'Annually', 'domain' => 'twice.com', 'domainstatus' => 'Active', 'amount' => '1.00']);
check('unknown customer + two services with that name: nothing suggested', \LbHosting\Import\Matcher::match(1, 'singledomain', 'singledomainhostinglinuxin', 502, ['domain' => 'twice.com', 'customer_email' => null])['match_service_id'] === null);
check('known customer: only that client\'s service', \LbHosting\Import\Matcher::match(1, 'singledomain', 'singledomainhostinglinuxin', 502, ['domain' => 'twice.com', 'customer_email' => 'bob@example.org'])['match_service_id'] === 61);

section('Import: ignore / restore / failures');
Capsule::table('mod_lbh_import_orders')->insert(['id' => 90, 'account_id' => 1, 'family' => 'singledomain', 'product_key' => 'singledomainhostinglinuxin', 'order_id' => 301, 'domain' => 'x.com', 'customer_id' => 555, 'customer_email' => 'jane@example.com', 'lb_status' => 'Active', 'plan_id' => 1001, 'expires_at' => '2027-06-01 00:00:00', 'match_client_id' => 1, 'match_product_id' => $pStarter, 'state' => 'new', 'scanned_at' => date('Y-m-d H:i:s')]);
$im->ignore(90);
check('ignored', imp(301)->state === 'ignored');
$t->script['GET ' . SDH . '/search.json'] = [[200, json_encode([['orderid' => 301]])]];
(new OrderScanner($t))->scan($acc, ['singledomain'], ['Active']);
check('a later scan never un-ignores', imp(301)->state === 'ignored');
$im->restore(90);
$GLOBALS['failAddOrder'] = true;
throwsLike('WHMCS refuses the order -> error state, nothing linked', fn () => $im->import(90, ['billingcycle' => 'Annually', 'paymentmethod' => 'banktransfer']), 'AddOrder failed');
$GLOBALS['failAddOrder'] = false;
check('error recorded, order still importable', imp(301)->state === 'error' && Capsule::table('mod_lbh_services')->where('order_id', 301)->count() === 0);
$im->ignore((int) imp(101)->id);
check('an imported order cannot be flipped to ignored', imp(101)->state === 'imported');
$t->script['GET ' . SDH . '/search.json'] = [[500, json_encode(['status' => 'ERROR', 'message' => 'Access Denied: You are not authorized to perform this action'])]];
$r = (new OrderScanner($t))->scan($acc, ['singledomain'], ['Active']);
check('a real API error is reported per location', (bool) array_filter($r['errors'], static fn ($e) => str_contains($e, 'Access Denied')));

// ------------------------------------------------------------------------------
section('Updater: package rules');
foreach (['modules/addons/lbhosting/src/X.php', 'modules/servers/lbhvps/lbhvps.php', 'modules/servers/lbhwordpress/templates/clientarea.tpl'] as $ok) {
    try {
        Updater::assertSafePath($ok);
        check('allowed ' . $ok, true);
    } catch (\Throwable $e) {
        check('allowed ' . $ok, false, $e->getMessage());
    }
}
foreach (['modules/addons/lbhosting/./overrides/lang/english.php', 'modules/addons/lbhosting//overrides/lang/english.php', 'modules/servers/lbhvps/./lbhvps.php'] as $bad) {
    throwsLike('refused ' . $bad, fn () => Updater::assertSafePath($bad), 'outside the module folders');
}
check('overrides protected whatever the letter case', Updater::isProtected('modules/addons/lbhosting/Overrides/lang/english.php') && Updater::isProtected('modules/servers/lbhvps/OVERRIDES/x.tpl'));
foreach (['modules/addons/other/x.php', '../configuration.php', 'modules/servers/lbhvps/../../../configuration.php', '/etc/passwd', 'modules/servers/lbtitan/x.php', 'modules/servers/lbhvpsevil/x.php', 'modules\\servers\\lbhvps\\x.php', 'configuration.php'] as $bad) {
    throwsLike('refused ' . $bad, fn () => Updater::assertSafePath($bad), 'outside the module folders');
}
check('overrides protected', Updater::isProtected('modules/servers/lbhvps/overrides/templates/clientarea.tpl') && Updater::isProtected('modules/addons/lbhosting/overrides/lang/english.php') && !Updater::isProtected('modules/addons/lbhosting/lang/english.php'));

section('Updater: signed manifest');
$kp = sodium_crypto_sign_keypair();
$pk = base64_encode(sodium_crypto_sign_publickey($kp));
$sk = sodium_crypto_sign_secretkey($kp);
$m = Manifest::sign(['module' => 'lbhosting', 'version' => '9.1.0', 'zip_url' => 'https://u.example.com/lbhosting.zip', 'sha256' => str_repeat('a', 64)], $sk);
check('signed manifest verifies', Manifest::verify((string) json_encode($m), $pk)->version() === '9.1.0');
$m2 = $m;
$m2['zip_url'] = 'https://evil.example.com/x.zip';
throwsLike('tampered manifest refused', fn () => Manifest::verify((string) json_encode($m2), $pk), 'INVALID');
$m2 = $m;
$m2['sha256'] = str_repeat('b', 64);
throwsLike('swapped checksum refused', fn () => Manifest::verify((string) json_encode($m2), $pk), 'INVALID');
throwsLike('manifest signed with another key refused', fn () => Manifest::verify((string) json_encode($m), base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()))), 'INVALID');
throwsLike('unsigned manifest refused', fn () => Manifest::verify((string) json_encode(['module' => 'lbhosting', 'version' => '9.1.0']), $pk), 'not signed');
throwsLike('manifest for another module refused', fn () => Manifest::verify((string) json_encode(Manifest::sign(['module' => 'lbsuite', 'version' => '9.0.0', 'zip_url' => 'https://u/x.zip', 'sha256' => str_repeat('a', 64)], $sk)), $pk), 'different module');
throwsLike('http download URL refused even when signed', fn () => Manifest::verify((string) json_encode(Manifest::sign(['module' => 'lbhosting', 'version' => '9.0.0', 'zip_url' => 'http://u/x.zip', 'sha256' => str_repeat('a', 64)], $sk)), $pk), 'must be https');
throwsLike('no public key configured', fn () => Manifest::verify((string) json_encode($m), ''), 'public key');

section('Updater: install, protect overrides, roll back');
$root = sys_get_temp_dir() . '/lbh-test-root-' . getmypid();
$GLOBALS['attachments_dir'] = $root . '/attachments';
@mkdir($root . '/attachments', 0700, true);
$put = static function (string $rel, string $content) use ($root): void {
    @mkdir(dirname($root . '/' . $rel), 0755, true);
    file_put_contents($root . '/' . $rel, $content);
};
$put('modules/addons/lbhosting/lbhosting.php', 'OLD');
$put('modules/addons/lbhosting/overrides/lang/english.php', 'MY OVERRIDE');
$put('modules/servers/lbhvps/overrides/templates/clientarea.tpl', 'MY TEMPLATE');
$put('configuration.php', 'CONFIG');
$build = static function (array $files): string {
    $f = tempnam(sys_get_temp_dir(), 'lbhpkg');
    $z = new ZipArchive();
    $z->open($f, ZipArchive::OVERWRITE);
    foreach ($files as $name => $content) {
        $z->addFromString($name, $content);
    }
    $z->close();
    $bytes = (string) file_get_contents($f);
    unlink($f);

    return $bytes;
};
$good = ['modules/addons/lbhosting/lbhosting.php' => 'NEW', 'modules/addons/lbhosting/autoload.php' => "<?php define('LBH_VERSION', '9.1.0');", 'modules/addons/lbhosting/src/New.php' => 'NEW FILE',
    'modules/addons/lbhosting/overrides/lang/english.php' => 'SHIPPED OVERRIDE', 'modules/servers/lbhvps/overrides/templates/clientarea.tpl' => 'SHIPPED TEMPLATE'];
foreach (Updater::SERVER_MODULES as $mod) {
    $good['modules/servers/' . $mod . '/' . $mod . '.php'] = 'NEW ' . $mod;
}
$serve = static function (string $zipBytes, string $version, ?string $sha = null) use ($sk): callable {
    $manifest = (string) json_encode(Manifest::sign(['module' => 'lbhosting', 'version' => $version, 'zip_url' => 'https://u.example.com/pkg.zip', 'sha256' => $sha ?? hash('sha256', $zipBytes), 'min_php' => '8.1', 'notes' => 'Test <b>release</b>'], $sk));

    return static fn (string $url, int $max): string => str_ends_with($url, '.zip') ? $zipBytes : $manifest;
};
Settings::set('update_url', 'https://u.example.com/lbhosting.json');
Settings::set('update_pubkey', $pk);

$zip = $build($good);
$state = (new Updater($serve($zip, '9.1.0'), $root))->check();
check('check: newer version found, signature verified', $state['ok'] && $state['available'] && $state['latest'] === '9.1.0' && $state['php_ok']);
check('check: same version -> nothing to install', (new Updater($serve($zip, LBH_VERSION), $root))->check()['available'] === false);
throwsLike('install refuses a package that does not match the signed checksum', fn () => (new Updater($serve($zip, '9.1.0', str_repeat('c', 64)), $root))->install(), 'does not match the signed SHA-256');
check('...and nothing was written', file_get_contents($root . '/modules/addons/lbhosting/lbhosting.php') === 'OLD');
throwsLike('install refuses a package with a file outside the module folders', fn () => (new Updater($serve($build($good + ['configuration.php' => 'PWNED']), '9.1.0'), $root))->install(), 'outside the module folders');
throwsLike('...also with a path traversal', fn () => (new Updater($serve($build($good + ['modules/addons/lbhosting/../../../configuration.php' => 'PWNED']), '9.1.0'), $root))->install(), 'outside the module folders');
$incomplete = $good;
unset($incomplete['modules/servers/lbhcloud/lbhcloud.php']);
throwsLike('install refuses an incomplete package', fn () => (new Updater($serve($build($incomplete), '9.1.0'), $root))->install(), 'incomplete');
check('WHMCS configuration untouched by every refused package', file_get_contents($root . '/configuration.php') === 'CONFIG' && file_get_contents($root . '/modules/addons/lbhosting/lbhosting.php') === 'OLD');
throwsLike('install refuses a package whose own version differs from the manifest', fn () => (new Updater($serve($zip, '9.5.0'), $root))->install(), 'is not version 9.5.0');
check('...and nothing was written', file_get_contents($root . '/modules/addons/lbhosting/lbhosting.php') === 'OLD');
throwsLike('install refuses an older or equal version', fn () => (new Updater($serve($zip, '0.0.1'), $root))->install(), 'No newer version');

$v = (new Updater($serve($zip, '9.1.0'), $root))->install();
check('install: files replaced and added', $v === '9.1.0' && file_get_contents($root . '/modules/addons/lbhosting/lbhosting.php') === 'NEW' && file_get_contents($root . '/modules/addons/lbhosting/src/New.php') === 'NEW FILE' && file_get_contents($root . '/modules/servers/lbhdedicated/lbhdedicated.php') === 'NEW lbhdedicated');
check('install: overrides are never overwritten', file_get_contents($root . '/modules/addons/lbhosting/overrides/lang/english.php') === 'MY OVERRIDE' && file_get_contents($root . '/modules/servers/lbhvps/overrides/templates/clientarea.tpl') === 'MY TEMPLATE');
$backups = glob($root . '/attachments/lbhosting-backups/*.zip') ?: [];
$bz = new ZipArchive();
$bz->open($backups[0]);
check('install: the previous files were backed up outside the module', count($backups) === 1 && $bz->getFromName('modules/addons/lbhosting/lbhosting.php') === 'OLD');
$bz->close();
check('state saved, no leftover temp files', Updater::state()['available'] === false && (glob($root . '/modules/addons/lbhosting/*.lbh-new') ?: []) === []);

// roll back when a file cannot be written half-way
$put('modules/addons/lbhosting/lbhosting.php', 'BEFORE ROLLBACK');
@mkdir($root . '/modules/addons/lbhosting/zz', 0755, true);
@mkdir($root . '/modules/addons/lbhosting/zz/blocked.php', 0755, true); // a directory where a file must go -> rename fails
$bad = $good;
$bad['modules/addons/lbhosting/autoload.php'] = "<?php define('LBH_VERSION', '9.2.0');";
$bad['modules/addons/lbhosting/migrations/0099_new.php'] = 'NEW MIGRATION';
$bad['modules/addons/lbhosting/zz/blocked.php'] = 'X';
throwsLike('a write failure half-way rolls everything back', fn () => (new Updater($serve($build($bad), '9.2.0'), $root))->install(), 'rolled back');
check('previous version restored', file_get_contents($root . '/modules/addons/lbhosting/lbhosting.php') === 'BEFORE ROLLBACK' && str_contains((string) file_get_contents($root . '/modules/addons/lbhosting/autoload.php'), '9.1.0'));
check('files the failed update had added are removed again', !file_exists($root . '/modules/addons/lbhosting/migrations/0099_new.php'));
unset($GLOBALS['attachments_dir']);
throwsLike('no private backup folder -> nothing is installed (no shared temp fallback)', fn () => (new Updater($serve($build(array_merge($good, ['modules/addons/lbhosting/autoload.php' => "<?php define('LBH_VERSION', '9.3.0');"])), '9.3.0'), $root))->install(), 'No writable backup directory');
throwsLike('a manifest that cannot be canonicalised is refused', fn () => Manifest::canonical(['x' => NAN]), 'canonical');

// tidy up
$rm = static function (string $dir) use (&$rm): void {
    foreach (glob($dir . '/{,.}[!.,!..]*', GLOB_BRACE) ?: [] as $f) {
        is_dir($f) ? $rm($f) : @unlink($f);
    }
    @rmdir($dir);
};
$rm($root);

done();
