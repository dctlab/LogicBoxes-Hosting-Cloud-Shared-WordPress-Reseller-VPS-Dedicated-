<?php
/**
 * Offline test suite (Phase 2): API client, product registry, option mapping
 * and the full provisioning lifecycle of all six families.
 * Run: php tests/run.php
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use LbHosting\Api\HostingApi;
use LbHosting\Api\LogicBoxesClient;
use LbHosting\Catalog\ProductFamily;
use LbHosting\Service\AddonManager;
use LbHosting\Service\Provisioner;
use LbHosting\Service\ServiceRepository;
use LbHosting\Support\Settings;
use LbHosting\WHMCS\OptionMap;
use LbHosting\WHMCS\ProductConfig;
use LbHosting\WHMCS\ServerModule;

function sorted(array $a): array
{
    sort($a);

    return $a;
}

function prov(string $family, ScriptedTransport $t): Provisioner
{
    return new Provisioner($family, null, null, $t);
}

function addOk(ScriptedTransport $t, string $path, int $orderId = 9001): void
{
    $t->on('POST', 'api/' . $path . '/add.json', 200, ['entityid' => (string) $orderId, 'actiontype' => 'AddOrder', 'actionstatus' => 'Success', 'description' => 'example.com']);
    $t->on('GET', 'api/' . $path . '/orderid.json', 500, ['status' => 'ERROR', 'message' => 'Order Id not found for domain']);
}

// ------------------------------------------------------------------------------
section('API client (legacy HTTP API)');
$t = new ScriptedTransport();
$c = new LogicBoxesClient('12345', 'SECRETKEY123', true, null, $t);
check('test host', $c->host() === 'https://test.httpapi.com/');
$api = new HostingApi($c);
$t->on('POST', 'api/virtualserver/linux/us/add.json', 200, ['entityid' => '77', 'actionstatus' => 'Success']);
$api->add('vps', 'virtualserverlinuxus', 'srv.example.com', 5, 10, 12, 'NoInvoice', false, ['os' => 'alma_9', 'addon' => ['cpanel', 'whmcs']]);
$call = $t->last('POST', 'api/virtualserver/linux/us/add.json');
check('POST goes in the form body, not the URL', strpos((string) $call['body'], 'plan-id=10') !== false && strpos((string) $call['body'], 'api-key=') !== false);
check('array param sent as repeated keys', ($call['query']['addon'] ?? null) === ['cpanel', 'whmcs']);
check('auth + auto-renew false sent', ($call['query']['auth-userid'] ?? '') === '12345' && ($call['query']['auto-renew'] ?? '') === 'false');
check('extractError legacy ERROR', LogicBoxesClient::extractError(['status' => 'ERROR', 'message' => 'bad']) === 'bad');
check('extractError actionstatus Failed', LogicBoxesClient::extractError(['actionstatus' => 'Failed', 'actionstatusdesc' => 'no funds']) === 'no funds');
check('extractError success payload', LogicBoxesClient::extractError(['entityid' => '1', 'actionstatus' => 'Success']) === null);
$t->on('GET', 'api/dedicatedserver/linux/us/details.json', 200, ['entityid' => '77', 'siteadminusername' => 'root', 'siteadminpassword' => 'S3cr3tPass!', 'addons' => [['addon_name' => 'whmcs', 'licenseKey' => 'WHMCS-ABCDEF123456']]]);
$api->details('dedicated', 'dedicatedserverlinuxus', 77);
$log = end($GLOBALS['moduleLog']);
check('server password and licence passed to WHMCS log masking', in_array('S3cr3tPass!', $log['replace'], true) && in_array('WHMCS-ABCDEF123456', $log['replace'], true) && in_array('SECRETKEY123', $log['replace'], true));
check('API key not in logged request', strpos(json_encode($log['req']), 'SECRETKEY123') === false);
$t->on('GET', 'api/singledomainhosting/linux/in/orderid.json', 500, ['status' => 'ERROR', 'message' => 'Website doesn\'t exist for example.org']);
check('orderid not found -> null', $api->orderIdForDomain('singledomain', 'singledomainhostinglinuxin', 'example.org') === null);
$t->on('GET', 'api/singledomainhosting/linux/in/orderid.json', 200, '4242');
$t->script['GET api/singledomainhosting/linux/in/orderid.json'] = [[200, '4242']];
check('orderid bare integer body', $api->orderIdForDomain('singledomain', 'singledomainhostinglinuxin', 'example.org') === 4242);
throwsLike('addon not allowed for key refused before any call', fn () => $api->addonAdd('dedicated', 'dedicatedserverlinuxin', 1, 'storage_1', 'NoInvoice'), 'not available');
throwsLike('modify refused for Cloud', fn () => $api->modify('cloud', 'cloudsiteslinuxus', 1, 2, 12, 'NoInvoice'), 'cannot change the plan');
throwsLike('invalid invoice option refused', fn () => $api->renew('vps', 'virtualserverlinuxus', 1, 12, 'Free', false), 'invoice option');
$t->on('GET', 'api/products/plan-details.json', 200, ['multidomainhostinglinuxin' => ['812' => ['plan_name' => 'MD Starter'], '813' => ['plan_name' => 'MD Pro']], 'resellerhosting' => ['1' => ['plan_name' => 'R1']]]);
$plans = $api->plansForKeys(['multidomainhostinglinuxin', 'multidomainhosting']);
check('plansForKeys: one call, filtered per key', count($plans['multidomainhostinglinuxin']) === 2 && !isset($plans['multidomainhosting']) && $t->count('GET', 'api/products/plan-details.json') === 1);

// ------------------------------------------------------------------------------
section('Product registry');
$total = 0;
$modules = [];
foreach (ProductFamily::all() as $f) {
    $modules[] = ProductFamily::module($f);
    foreach (ProductFamily::keys($f) as $k => $m) {
        $total++;
        if (ProductFamily::forKey($k) !== $f || !preg_match(ProductFamily::isRest($f) ? '#^[a-z]+$#' : '#^[a-z]+/[a-z]+/[a-z]+$#', $m['path'])) {
            check('key ' . $k . ' consistent', false, $m['path']);
        }
    }
}
check('42 product keys across 8 families, each maps back to its family', $total === 42 && count(ProductFamily::all()) === 8, (string) $total);
check('8 distinct modules', count(array_unique($modules)) === 8);
check('WordPress is the only REST family; Business is legacy, India only', ProductFamily::isRest('wordpress') && !ProductFamily::isRest('business') && array_keys(ProductFamily::keys('business')) === ['businesshostinglinuxin'] && ProductFamily::path('business', 'businesshostinglinuxin') === 'businesshosting/linux/in');
check('Business dedicated IP is order-only -> listed as a manual item', ProductFamily::manualItems('business') === ['dedicated_ip'] && ProductFamily::manualItems('wordpress') === []);
check('MDH Linux US Texas key is "multidomainhosting"', ProductFamily::path('multidomain', 'multidomainhosting') === 'multidomainhosting/linux/us');
check('Reseller Windows US key path', ProductFamily::path('reseller', 'resellerwindowshosting') === 'resellerhosting/windows/us');
check('Cloud IN path', ProductFamily::path('cloud', 'cloudsiteslinuxin') === 'cloudsites/linux/in');
check('VPS uses virtualserver (KVM) paths', ProductFamily::path('vps', 'virtualserverlinuxin') === 'virtualserver/linux/in');
check('Managed server is a dedicated variant', ProductFamily::forKey('managedserverlinuxus') === 'dedicated' && ProductFamily::isManaged('dedicated', 'managedserverlinuxus') && ProductFamily::path('dedicated', 'managedserverlinuxus') === 'managedserver/linux/us');
check('TR / US-CA paths flagged PATTERN', ProductFamily::keyMeta('singledomain', 'singledomainhostinglinuxtr')['conf'] === 'PATTERN' && ProductFamily::keyMeta('multidomain', 'multidomainwindowshostingusca')['conf'] === 'PATTERN');
check('Dedicated IN has no storage add-ons', !isset(ProductFamily::addons('dedicated', 'dedicatedserverlinuxin')['storage_1']) && isset(ProductFamily::addons('dedicated', 'dedicatedserverlinuxus')['storage_1']));
check('Dedicated Windows: OS required, Windows list', ProductFamily::osRequired('dedicated', 'dedicatedserverwindowsin') && isset(ProductFamily::osList('dedicated', 'dedicatedserverwindowsin')['win2k19']));
check('Managed server takes no OS', ProductFamily::osList('dedicated', 'managedserverlinuxus') === []);
check('Manual items: Cloud dedicated IP, Dedicated bandwidth', ProductFamily::manualItems('cloud') === ['dedicated_ip'] && ProductFamily::manualItems('dedicated') === ['bandwidth']);
check('keyLabel', ProductFamily::keyLabel('multidomain', 'multidomainwindowshostinguk') === 'Windows - United Kingdom' && ProductFamily::keyLabel('dedicated', 'managedserverlinuxus') === 'Managed - Linux - United States (Texas)');

// ------------------------------------------------------------------------------
section('Product config and configurable options');
[$k, $p] = ProductConfig::parsePlan('multidomain', 'multidomainhostinglinuxin:812');
check('parse key:id', $k === 'multidomainhostinglinuxin' && $p === 812);
[$k, $p] = ProductConfig::parsePlan('multidomain', 'virtualserverlinuxus:5');
check('key from another family is not accepted', $k === 'multidomainhosting' && $p === 5);
$cfg = new ProductConfig('vps', ['configoption2' => 'virtualserverlinuxus:10', 'configoption3' => 'Bogus', 'configoption4' => 'ubuntu_22', 'configoption5' => 'cpanel, whmcs, nonsense']);
check('server config: default OS + included add-ons filtered', $cfg->defaultOs === 'ubuntu_22' && $cfg->includedAddons === ['cpanel', 'whmcs'] && $cfg->invoiceOption === 'NoInvoice');
$om = new OptionMap(['configoptions' => ['Operating System|OS' => 'alma_9|AlmaLinux 9', 'Control Panel' => 'Plesk (30 domains) plesk_30_domain', 'Dedicated IP' => 2, 'WHMCS License' => 'on', 'Storage' => 'None']]);
$d = $om->desired(new ProductConfig('vps', ['configoption2' => 'virtualserverlinuxus:10']));
check('VPS: OS from "code|Name" option', $d['os'] === 'alma_9');
check('VPS: panel group resolved from friendly name, others 0', $d['addons']['plesk_30_domain'] === 1 && $d['addons']['cpanel'] === 0 && $d['addons']['plesk_10_domain'] === 0);
check('VPS: IP quantity, WHMCS yes, storage none', $d['addons']['ipaddress'] === 2 && $d['addons']['whmcs'] === 1 && $d['addons']['storage_1'] === 0);
$d = (new OptionMap(['configoptions' => ['Dedicated IP' => '1']]))->desired(new ProductConfig('singledomain', ['configoption2' => 'singledomainhostinglinuxin:1']));
check('SDH dedicated IP -> ssl', $d['ssl'] === true && $d['addons'] === []);
$d = (new OptionMap(['configoptions' => ['CPU' => '2', 'RAM' => '1', 'Dedicated IP' => '1']]))->desired(new ProductConfig('cloud', ['configoption2' => 'cloudsiteslinuxus:300109']));
check('Cloud: cpu_1/ram_1 units, dedicated IP manual', $d['addons'] === ['cpu_1' => 2, 'ram_1' => 1] && $d['manual'] === ['dedicated_ip' => '1']);
$d = (new OptionMap(['configoptions' => ['cPanel Blocks' => 3]]))->desired(new ProductConfig('reseller', ['configoption2' => 'resellerwindowshosting:1']));
check('Reseller Windows: cPanel blocks ignored (Linux only)', $d['cpanel_blocks'] === null);
$d = (new OptionMap(['configoptions' => ['Control Panel' => 'DirectAdmin']]))->desired(new ProductConfig('vps', ['configoption2' => 'virtualserverlinuxus:10']));
check('unrecognised dropdown value reported', $d['unknown'] === ['control_panel']);
$d = (new OptionMap(['configoptions' => []]))->desired(new ProductConfig('vps', ['configoption2' => 'virtualserverlinuxus:10']));
check('no options -> nothing desired (provider left as is)', $d['addons'] === [] && $d['os'] === null);

// ------------------------------------------------------------------------------
section('Create - all six families');
$cases = [
    ['lbhsingledomain', 'singledomain', 'singledomainhostinglinuxin:1001', 'singledomainhosting/linux/in', [], 'example.com'],
    ['lbhmultidomain', 'multidomain', 'multidomainwindowshostinguk:2001', 'multidomainhosting/windows/uk', [], 'example.com'],
    ['lbhreseller', 'reseller', 'resellerhosting:3001', 'resellerhosting/linux/us', [], 'example.com'],
    ['lbhcloud', 'cloud', 'cloudsiteslinuxin:300109', 'cloudsites/linux/in', [], 'example.com'],
    ['lbhvps', 'vps', 'virtualserverlinuxus:10', 'virtualserver/linux/us', [], 'srv1.example.com'],
    ['lbhdedicated', 'dedicated', 'dedicatedserverlinuxus:105', 'dedicatedserver/linux/us', [], 'dedi.example.com'],
];
foreach ($cases as [$module, $family, $plan, $path, $opts, $domain]) {
    freshDb($module, $plan, [], ['domain' => $domain]);
    $t = transportWithCustomer();
    addOk($t, $path);
    $t->on('GET', 'api/' . $path . '/details.json', 200, ['entityid' => '9001', 'currentstatus' => 'Active', 'endtime' => '1822000000', 'customerid' => '555', 'ipaddress' => '203.0.113.9', 'siteadminusername' => 'exampl', 'siteadminpassword' => 'pw-should-not-store']);
    $r = prov($family, $t)->create(params($plan, ['domain' => $domain]));
    $add = $t->last('POST', 'api/' . $path . '/add.json');
    [$key, $planId] = explode(':', $plan);
    check("$family: add.json on $path with plan/months/customer", $r === 'success' && $add && $add['query']['plan-id'] === $planId && $add['query']['months'] === '12' && $add['query']['customer-id'] === '555' && $add['query']['domain-name'] === $domain);
    check("$family: auto-renew param " . ($family === 'cloud' ? 'omitted (Cloud)' : 'sent'), $family === 'cloud' ? !isset($add['query']['auto-renew']) : ($add['query']['auto-renew'] ?? '') === 'false');
    $row = (new ServiceRepository())->get(10);
    check("$family: service linked, details applied", (int) $row->order_id === 9001 && $row->product_key === $key && $row->ip_address === '203.0.113.9' && $row->panel_username === 'exampl' && $row->provider_status === 'Active' && $row->expires_at !== null);
    check("$family: stored details contain no password", strpos((string) $row->details_json, 'pw-should-not-store') === false);
}

// --- idempotency / adoption
freshDb('lbhsingledomain', 'singledomainhostinglinuxin:1001');
$t = transportWithCustomer();
$t->on('GET', 'api/singledomainhosting/linux/in/orderid.json', 200, '7777');
$t->on('GET', 'api/singledomainhosting/linux/in/details.json', 200, ['entityid' => '7777', 'customerid' => '555', 'currentstatus' => 'Active']);
prov('singledomain', $t)->create(params('singledomainhostinglinuxin:1001', ['configoptions' => ['Dedicated IP' => 1]]));
check('existing order for the domain is adopted, nothing ordered', $t->count('POST', 'api/singledomainhosting/linux/in/add.json') === 0 && (int) (new ServiceRepository())->get(10)->order_id === 7777);
check('adopted order: extras recorded as ordered (no API calls)', AddonManager::recorded(10, 'ssl') === 1 && $t->count('POST', 'api/singledomainhosting/linux/in/enable-ssl.json') === 0);
$t->calls = [];
prov('singledomain', $t)->create(params('singledomainhostinglinuxin:1001', ['configoptions' => ['Dedicated IP' => 1]]));
check('Create re-run on a linked service only verifies', $t->trail() === ['GET api/singledomainhosting/linux/in/details.json']);

freshDb('lbhsingledomain', 'singledomainhostinglinuxin:1001');
$t = transportWithCustomer();
$t->on('GET', 'api/singledomainhosting/linux/in/orderid.json', 200, '7777');
$t->on('GET', 'api/singledomainhosting/linux/in/details.json', 200, ['entityid' => '7777', 'customerid' => '999']);
throwsLike('order of another customer is never adopted', fn () => prov('singledomain', $t)->create(params('singledomainhostinglinuxin:1001')), 'different customer');
$t->script['GET api/singledomainhosting/linux/in/details.json'] = [[200, json_encode(['entityid' => '7777', 'customerid' => '555', 'currentstatus' => 'Deleted'])]];
throwsLike('deleted order is never adopted', fn () => prov('singledomain', $t)->create(params('singledomainhostinglinuxin:1001')), 'deleted');

freshDb('lbhmultidomain', 'multidomainhosting:2001', [], ['billingcycle' => 'One Time']);
throwsLike('one-time billing cycle refused', fn () => prov('multidomain', transportWithCustomer())->create(params('multidomainhosting:2001')), 'not supported');
freshDb('lbhmultidomain', 'multidomainhosting:2001');
throwsLike('no plan selected', fn () => prov('multidomain', transportWithCustomer())->create(params('')), 'No Multi Domain Hosting plan');
throwsLike('invalid domain refused', fn () => prov('multidomain', transportWithCustomer())->create(params('multidomainhosting:2001', ['domain' => 'not a domain'])), 'valid domain');
freshDb('lbhvps', 'virtualserverlinuxus:10', [], ['domain' => 'srv1']);
throwsLike('server without FQDN hostname refused', fn () => prov('vps', transportWithCustomer())->create(params('virtualserverlinuxus:10', ['domain' => 'srv1'])), 'hostname');

// ------------------------------------------------------------------------------
section('Hosting extras: dedicated IP (SSL), reseller IPs / WHMCS / cPanel blocks');
freshDb('lbhsingledomain', 'singledomainhostinglinuxin:1001');
$t = transportWithCustomer();
addOk($t, 'singledomainhosting/linux/in');
$t->on('GET', 'api/singledomainhosting/linux/in/details.json', 200, ['entityid' => '9001', 'currentstatus' => 'Active', 'planid' => '1001']);
prov('singledomain', $t)->create(params('singledomainhostinglinuxin:1001', ['configoptions' => ['Dedicated IP' => 1]]));
check('SDH: enable-ssl=true sent with the order, not separately', ($t->last('POST', 'api/singledomainhosting/linux/in/add.json')['query']['enable-ssl'] ?? '') === 'true' && $t->count('POST', 'api/singledomainhosting/linux/in/enable-ssl.json') === 0);
$t->on('POST', 'api/singledomainhosting/linux/in/disable-ssl.json', 200, ['actionstatus' => 'Success']);
prov('singledomain', $t)->changePackage(params('singledomainhostinglinuxin:1001', ['configoptions' => ['Dedicated IP' => 0]]));
check('SDH: dedicated IP removed -> disable-ssl.json', $t->count('POST', 'api/singledomainhosting/linux/in/disable-ssl.json') === 1 && AddonManager::recorded(10, 'ssl') === 0);

freshDb('lbhreseller', 'resellerhosting:3001');
$t = transportWithCustomer();
addOk($t, 'resellerhosting/linux/us');
$t->on('GET', 'api/resellerhosting/linux/us/details.json', 200, ['entityid' => '9001', 'currentstatus' => 'Active', 'planid' => '3001', 'endtime' => (string) (time() + 200 * 86400)]);
$t->on('POST', 'api/resellerhosting/linux/us/add-dedicated-ip.json', 200, ['actionstatus' => 'Success']);
$t->on('POST', 'api/resellerhosting/linux/us/generate-license-key.json', 200, ['actionstatus' => 'Success']);
$t->on('POST', 'api/resellerhosting/linux/us/modify.json', 200, ['actionstatus' => 'Success']);
$opts = ['Dedicated IPs' => 3, 'WHMCS Licence' => 'on', 'cPanel Blocks' => 2];
prov('reseller', $t)->create(params('resellerhosting:3001', ['configoptions' => $opts]));
check('Reseller: first IP with the order, 2 more via add-dedicated-ip.json', ($t->last('POST', 'api/resellerhosting/linux/us/add.json')['query']['add-dedicated-ip'] ?? '') === 'true' && $t->count('POST', 'api/resellerhosting/linux/us/add-dedicated-ip.json') === 2);
check('Reseller: WHMCS licence generated', $t->count('POST', 'api/resellerhosting/linux/us/generate-license-key.json') === 1);
$mod = $t->last('POST', 'api/resellerhosting/linux/us/modify.json');
check('Reseller: cPanel blocks via same-plan modify (cpanel-blocks=2, remaining months)', $mod && $mod['query']['new-plan-id'] === '3001' && $mod['query']['cpanel-blocks'] === '2' && (int) $mod['query']['months'] >= 6 && (int) $mod['query']['months'] <= 7);
$t->calls = [];
prov('reseller', $t)->changePackage(params('resellerhosting:3001', ['configoptions' => $opts]));
check('Reseller: unchanged options -> only a details call', $t->trail() === ['GET api/resellerhosting/linux/us/details.json']);
$t->on('GET', 'api/resellerhosting/linux/us/details.json', 200, ['entityid' => '9001', 'planid' => '3001', 'dedicatedips' => ['198.51.100.1', '198.51.100.2', '198.51.100.3'], 'whmcs_license_key' => 'WHMCS-RES123456789']);
$t->script['GET api/resellerhosting/linux/us/details.json'] = [[200, json_encode(['entityid' => '9001', 'planid' => '3001', 'dedicatedips' => ['198.51.100.1', '198.51.100.2', '198.51.100.3'], 'whmcslicensekey' => 'WHMCS-RES123456789'])]];
$t->on('POST', 'api/resellerhosting/linux/us/delete-dedicated-ip.json', 200, ['actionstatus' => 'Success']);
$t->on('POST', 'api/resellerhosting/linux/us/revoke-license-key.json', 200, ['actionstatus' => 'Success']);
prov('reseller', $t)->changePackage(params('resellerhosting:3001', ['configoptions' => ['Dedicated IPs' => 1, 'WHMCS Licence' => '', 'cPanel Blocks' => 2]]));
$del = $t->last('POST', 'api/resellerhosting/linux/us/delete-dedicated-ip.json');
check('Reseller: 3 -> 1 IPs deletes the last two IPs', $del && $del['query']['ips'] === ['198.51.100.2', '198.51.100.3'] && AddonManager::recorded(10, 'dedicated_ip') === 1);
check('Reseller: WHMCS licence revoked by key', ($t->last('POST', 'api/resellerhosting/linux/us/revoke-license-key.json')['query']['license-key'] ?? '') === 'WHMCS-RES123456789');
throwsLike('Reseller: cPanel blocks cannot be lowered', fn () => prov('reseller', $t)->changePackage(params('resellerhosting:3001', ['configoptions' => ['Dedicated IPs' => 1, 'WHMCS Licence' => '', 'cPanel Blocks' => 1]])), 'cannot be removed');
Capsule::table('mod_lbh_services')->where('service_id', 10)->update(['created_at' => date('Y-m-d H:i:s', time() - 30 * 86400)]);
AddonManager::record(10, 'cpanel_blocks', 2);
$t->on('POST', 'api/resellerhosting/linux/us/renew.json', 200, ['actionstatus' => 'Success']);
prov('reseller', $t)->renew(params('resellerhosting:3001'), 'module');
check('Reseller renew carries cpanel-blocks', ($t->last('POST', 'api/resellerhosting/linux/us/renew.json')['query']['cpanel-blocks'] ?? '') === '2');

// ------------------------------------------------------------------------------
section('Cloud: CPU / RAM add-ons, manual dedicated IP');
freshDb('lbhcloud', 'cloudsiteslinuxus:300109');
$t = transportWithCustomer();
addOk($t, 'cloudsites/linux/us');
$t->on('GET', 'api/cloudsites/linux/us/details.json', 200, ['entityid' => '9001', 'currentstatus' => 'Active']);
$t->on('POST', 'api/cloudsites/linux/us/addon/add.json', 200, ['actionstatus' => 'Success']);
$opts = ['CPU' => 2, 'RAM' => 1, 'Dedicated IP' => 1];
prov('cloud', $t)->create(params('cloudsiteslinuxus:300109', ['configoptions' => $opts]));
$adds = array_map(static fn ($c) => $c['query']['addon'], array_values(array_filter($t->calls, static fn ($c) => $c['path'] === 'api/cloudsites/linux/us/addon/add.json')));
check('Cloud: cpu_1 x2 + ram_1 x1 via addon/add.json', $adds === ['cpu_1', 'cpu_1', 'ram_1']);
$task = Capsule::table('mod_lbh_manual_tasks')->where('service_id', 10)->first();
check('Cloud dedicated IP -> manual task, To-Do item and client ticket', $task && $task->item === 'dedicated_ip' && $task->target === '1' && $task->todo_id && $task->ticket_id && Capsule::table('tbltodolist')->count() === 1 && Capsule::table('tbltickets')->count() === 1);
$ticket = Capsule::table('tbltickets')->first();
check('ticket is in the configured/first department and linked to the service', (int) $ticket->did === 4 && (int) $ticket->service === 10 && (int) $ticket->userid === 1);
$t->calls = [];
prov('cloud', $t)->create(params('cloudsiteslinuxus:300109', ['configoptions' => $opts]));
check('Cloud Create re-run: no new add-ons, no duplicate task/ticket', $t->count('POST', 'api/cloudsites/linux/us/addon/add.json') === 0 && Capsule::table('mod_lbh_manual_tasks')->count() === 1 && Capsule::table('tbltickets')->count() === 1);
prov('cloud', $t)->changePackage(params('cloudsiteslinuxus:300109', ['configoptions' => ['CPU' => 2, 'RAM' => 1, 'Dedicated IP' => 0]]));
$tasks = Capsule::table('mod_lbh_manual_tasks')->orderBy('id')->get()->all();
check('Cloud: changing the request again cancels the open task and opens a new one', count($tasks) === 2 && $tasks[0]->status === 'cancelled' && $tasks[1]->target === '0' && $tasks[1]->previous === '1' && $tasks[1]->status === 'open');
check('superseded To-Do item completed', Capsule::table('tbltodolist')->where('id', $tasks[0]->todo_id)->value('status') === 'Completed');
(new \LbHosting\Service\ManualFulfilment())->complete((int) $tasks[1]->id);
check('Mark done closes task and To-Do', Capsule::table('mod_lbh_manual_tasks')->where('id', $tasks[1]->id)->value('status') === 'done' && Capsule::table('tbltodolist')->where('id', $tasks[1]->todo_id)->value('status') === 'Completed');
throwsLike('Cloud plan change refused (no API)', fn () => prov('cloud', $t)->changePackage(params('cloudsiteslinuxus:300110', ['configoptions' => ['CPU' => 2, 'RAM' => 1, 'Dedicated IP' => 0]])), 'cannot change the plan');

// partial failure + resume
freshDb('lbhcloud', 'cloudsiteslinuxus:300109');
$t = transportWithCustomer();
addOk($t, 'cloudsites/linux/us');
$t->on('GET', 'api/cloudsites/linux/us/details.json', 200, ['entityid' => '9001']);
$t->on('POST', 'api/cloudsites/linux/us/addon/add.json', 200, ['actionstatus' => 'Success']);
$t->on('POST', 'api/cloudsites/linux/us/addon/add.json', 200, ['status' => 'ERROR', 'message' => 'Insufficient funds']);
$t->on('POST', 'api/cloudsites/linux/us/addon/add.json', 200, ['actionstatus' => 'Success']);
$res = ServerModule::run('cloud', 'CreateAccount', params('cloudsiteslinuxus:300109', ['configoptions' => ['CPU' => 3]]), static fn (Provisioner $p, array $x) => $p->create($x));
// ServerModule::run builds its own Provisioner without our transport - use the seam
ServerModule::$transport = $t;
freshDb('lbhcloud', 'cloudsiteslinuxus:300109');
$t->calls = [];
$t->script['POST api/cloudsites/linux/us/addon/add.json'] = [[200, json_encode(['actionstatus' => 'Success'])], [200, json_encode(['status' => 'ERROR', 'message' => 'Insufficient funds'])], [200, json_encode(['actionstatus' => 'Success'])]];
$res = ServerModule::run('cloud', 'CreateAccount', params('cloudsiteslinuxus:300109', ['configoptions' => ['CPU' => 3]]), static fn (Provisioner $p, array $x) => $p->create($x));
check('add-on failure halfway -> "Error: ..." returned to WHMCS', str_starts_with($res, 'Error: ') && stripos($res, 'Insufficient funds') !== false && stripos($res, 'after 1 of 3') !== false);
check('record keeps the one that succeeded', AddonManager::recorded(10, 'addon:cpu_1') === 1);
$t->calls = [];
$res = ServerModule::run('cloud', 'CreateAccount', params('cloudsiteslinuxus:300109', ['configoptions' => ['CPU' => 3]]), static fn (Provisioner $p, array $x) => $p->create($x));
check('Create re-run sends only the 2 missing units, no new order', $res === 'success' && $t->count('POST', 'api/cloudsites/linux/us/addon/add.json') === 2 && $t->count('POST', 'api/cloudsites/linux/us/add.json') === 0 && AddonManager::recorded(10, 'addon:cpu_1') === 3);

// ------------------------------------------------------------------------------
section('VPS: OS, add-ons, upgrade-only plan change, safe removal');
freshDb('lbhvps', 'virtualserverlinuxus:10', [], ['domain' => 'srv1.example.com']);
$t = transportWithCustomer();
ServerModule::$transport = $t;
addOk($t, 'virtualserver/linux/us');
$t->on('GET', 'api/virtualserver/linux/us/details.json', 200, ['entityid' => '9001', 'planid' => '10', 'endtime' => (string) (time() + 95 * 86400)]);
$t->on('POST', 'api/virtualserver/linux/us/addon/add.json', 200, ['actionstatus' => 'Success']);
$vp = params('virtualserverlinuxus:10', ['domain' => 'srv1.example.com', 'configoption5' => 'whmcs', 'configoptions' => ['Operating System' => 'alma_9|AlmaLinux 9', 'Control Panel' => 'cpanel|cPanel', 'Dedicated IP' => 2]]);
prov('vps', $t)->create($vp);
$add = $t->last('POST', 'api/virtualserver/linux/us/add.json');
check('VPS: os + one of each add-on in the order', $add['query']['os'] === 'alma_9' && sorted($add['query']['addon']) === ['cpanel', 'ipaddress', 'whmcs']);
check('VPS: second IP via addon/add.json', $t->count('POST', 'api/virtualserver/linux/us/addon/add.json') === 1 && ($t->last('POST', 'api/virtualserver/linux/us/addon/add.json')['query']['addon'] ?? '') === 'ipaddress' && AddonManager::recorded(10, 'addon:ipaddress') === 2);
$t->on('POST', 'api/virtualserver/linux/us/modify.json', 200, ['actionstatus' => 'Success']);
$up = $vp;
$up['configoption2'] = 'virtualserverlinuxus:11';
$r = prov('vps', $t)->changePackage($up);
$m = $t->last('POST', 'api/virtualserver/linux/us/modify.json');
check('VPS plan upgrade -> modify.json with remaining months (~3)', $r === 'plan_changed' && $m['query']['new-plan-id'] === '11' && in_array($m['query']['months'], ['3', '4'], true));
$t->script['POST api/virtualserver/linux/us/modify.json'] = [[200, json_encode(['status' => 'ERROR', 'message' => 'Downgrade not allowed'])]];
$t->script['GET api/virtualserver/linux/us/details.json'] = [[200, json_encode(['entityid' => '9001', 'planid' => '11'])]];
Capsule::table('mod_lbh_services')->where('service_id', 10)->update(['plan_changed_at' => null]);
throwsLike('VPS downgrade refused with explanation', fn () => prov('vps', $t)->changePackage($vp), 'upgrades only');
// removal
$t->script['GET api/virtualserver/linux/us/details.json'] = [[200, json_encode(['entityid' => '9001', 'planid' => '10', 'addons' => [
    ['addOnID' => 31, 'name' => 'ipaddress', 'is_paid' => false, 'creationTime' => '1700000000'],
    ['addOnID' => 41, 'name' => 'ipaddress', 'is_paid' => true, 'creationTime' => '1750000000'],
    ['addOnID' => 42, 'name' => 'ipaddress', 'is_paid' => true, 'creationTime' => '1760000000'],
    ['addOnID' => 50, 'name' => 'cpanel', 'is_paid' => true],
]])]];
$t->on('POST', 'api/virtualserver/linux/us/addon/delete.json', 200, ['actionstatus' => 'Success']);
$less = $vp;
$less['configoptions']['Dedicated IP'] = 1;
prov('vps', $t)->changePackage($less);
$del = $t->last('POST', 'api/virtualserver/linux/us/addon/delete.json');
check('IP 2 -> 1 deletes the NEWEST PAID ipaddress add-on, never the free one', $t->count('POST', 'api/virtualserver/linux/us/addon/delete.json') === 1 && $del['query']['addon-id'] === '42' && AddonManager::recorded(10, 'addon:ipaddress') === 1);
$t->script['GET api/virtualserver/linux/us/details.json'] = [[200, json_encode(['entityid' => '9001', 'planid' => '10', 'addons' => [['addOnID' => 50, 'name' => 'cpanel']]])]];
$noPanel = $less;
$noPanel['configoptions']['Control Panel'] = 'none';
throwsLike('panel removal refused when LogicBoxes does not say it is paid', fn () => prov('vps', $t)->changePackage($noPanel), 'does not say which');
check('...and nothing was deleted', $t->count('POST', 'api/virtualserver/linux/us/addon/delete.json') === 1 && AddonManager::recorded(10, 'addon:cpanel') === 1);
Settings::override(array_merge(Settings::DEFAULTS, ['addon_removal' => '0']));
prov('vps', $t)->changePackage($noPanel);
check('with removal disabled: record lowered, no API call', AddonManager::recorded(10, 'addon:cpanel') === 0 && $t->count('POST', 'api/virtualserver/linux/us/addon/delete.json') === 1);
Settings::override(Settings::DEFAULTS);

// ------------------------------------------------------------------------------
section('Dedicated / Managed servers');
freshDb('lbhdedicated', 'dedicatedserverwindowsin:205', [], ['domain' => 'win.example.com']);
$t = transportWithCustomer();
addOk($t, 'dedicatedserver/windows/in');
throwsLike('Dedicated Windows without OS refused before ordering', fn () => prov('dedicated', $t)->create(params('dedicatedserverwindowsin:205', ['domain' => 'win.example.com'])), 'operating system');
check('...no order placed', $t->count('POST', 'api/dedicatedserver/windows/in/add.json') === 0);
$t->on('GET', 'api/dedicatedserver/windows/in/details.json', 200, ['entityid' => '9001']);
prov('dedicated', $t)->create(params('dedicatedserverwindowsin:205', ['domain' => 'win.example.com', 'configoption4' => 'win2k19', 'configoptions' => ['Bandwidth' => '10TB|10 TB']]));
check('Default OS from module settings used', ($t->last('POST', 'api/dedicatedserver/windows/in/add.json')['query']['os'] ?? '') === 'win2k19');
check('Dedicated bandwidth -> manual task', Capsule::table('mod_lbh_manual_tasks')->where('item', 'bandwidth')->value('target') === '10TB');
freshDb('lbhdedicated', 'managedserverlinuxus:501', [], ['domain' => 'm.example.com']);
$t = transportWithCustomer();
addOk($t, 'managedserver/linux/us');
$t->on('GET', 'api/managedserver/linux/us/details.json', 200, ['entityid' => '9001']);
prov('dedicated', $t)->create(params('managedserverlinuxus:501', ['domain' => 'm.example.com', 'configoption4' => 'centos_7', 'configoptions' => ['Dedicated IP' => 1, 'Storage' => 'storage_2']]));
$add = $t->last('POST', 'api/managedserver/linux/us/add.json');
check('Managed server: managedserver path, no os param, its add-ons', $add && !isset($add['query']['os']) && $add['query']['addon'] === ['ipaddress', 'storage_2']);
throwsLike('Dedicated plan change refused (no API)', fn () => prov('dedicated', $t)->changePackage(params('managedserverlinuxus:502', ['domain' => 'm.example.com'])), 'cannot change the plan');

// ------------------------------------------------------------------------------
section('WordPress Hosting (REST API)');
const WP = 'restapi/product/wordpresshostingusa';
freshDb('lbhwordpress', 'wordpresshostingusa:300109');
$t = transportWithCustomer();
ServerModule::$transport = $t;
$t->on('GET', WP, 404, ['status' => 'ERROR', 'message' => 'No order found for domain']);
$t->on('POST', WP . '/order', 200, ['entityid' => '9001', 'actionstatus' => 'Success', 'error' => 'NoError', 'status' => 'Success']);
$t->on('POST', WP . '/order/9001/addon', 200, ['actionstatus' => 'Success']);
$wpOpts = ['CPU' => 2, 'RAM' => 1, 'Dedicated IP' => 1];
$wpp = params('wordpresshostingusa:300109', ['configoptions' => $wpOpts]);
$res = ServerModule::run('wordpress', 'CreateAccount', $wpp, static fn (Provisioner $p, array $x) => $p->create($x));
$add = $t->last('POST', WP . '/order');
check('WP create: POST restapi .../order, params in the query string, noOfMonths', $res === 'success' && $add && $add['body'] === '' && $add['query']['noOfMonths'] === '12' && !isset($add['query']['months']) && $add['query']['plan-id'] === '300109' && $add['query']['customer-id'] === '555' && !isset($add['query']['auto-renew']));
$adds = array_map(static fn ($c) => $c['query']['addon'], array_values(array_filter($t->calls, static fn ($c) => $c['path'] === WP . '/order/9001/addon')));
check('WP add-ons: cpu_1 x2, ram_1, ipaddress through the API', $adds === ['cpu_1', 'cpu_1', 'ram_1', 'ipaddress'] && Capsule::table('mod_lbh_manual_tasks')->count() === 0);
check('WP legacy endpoints are never called', !array_filter($t->calls, static fn ($c) => str_starts_with($c['path'], 'api/wordpress')));
$t->script['GET ' . WP] = [[200, json_encode(['orderid' => '9001', 'domainname' => 'example.com', 'currentstatus' => 'Active', 'customerid' => '555', 'planid' => '300109', 'endtime' => '1850000000',
    'addons' => [['addon_id' => 71, 'addon_name' => 'cpu_1', 'is_paid' => true, 'creation_time' => '1750000000'], ['addon_id' => 72, 'addon_name' => 'cpu_1', 'is_paid' => true, 'creation_time' => '1760000000']]])]];
$t->on('PATCH', WP . '/order/9001/suspend', 200, ['status' => 'Success', 'error' => 'NoError']);
$t->on('PATCH', WP . '/order/9001/unsuspend', 200, ['status' => 'Success']);
prov('wordpress', $t)->suspend($wpp + ['suspendreason' => 'Overdue']);
check('WP suspend: PATCH .../order/{id}/suspend with reason (not orders/suspend.json)', ($t->last('PATCH', WP . '/order/9001/suspend')['query']['reason'] ?? '') === 'Overdue' && $t->count('POST', 'api/orders/suspend.json') === 0);
prov('wordpress', $t)->unsuspend($wpp);
check('WP unsuspend: PATCH .../unsuspend', $t->count('PATCH', WP . '/order/9001/unsuspend') === 1);
Capsule::table('mod_lbh_services')->where('service_id', 10)->update(['created_at' => date('Y-m-d H:i:s', time() - 30 * 86400)]);
$t->on('PATCH', WP . '/order/9001/tenure/12', 200, ['status' => 'Success']);
check('WP renew: PATCH .../tenure/12 with auto-renew', prov('wordpress', $t)->renew($wpp, 'module') === 'renewed' && ($t->last('PATCH', WP . '/order/9001/tenure/12')['query']['auto-renew'] ?? '') === 'false');
$t->on('DELETE', WP . '/order/9001/addon/72', 200, ['status' => 'Success']);
$less = $wpp;
$less['configoptions']['CPU'] = 1;
prov('wordpress', $t)->changePackage($less);
check('WP CPU 2 -> 1: DELETE .../addon/{newest paid id}', $t->count('DELETE', WP . '/order/9001/addon/72') === 1 && AddonManager::recorded(10, 'addon:cpu_1') === 1);
throwsLike('WP plan change refused (no API)', fn () => prov('wordpress', $t)->changePackage(params('wordpresshostingusa:300110', ['configoptions' => $less['configoptions']])), 'cannot change the plan');
Settings::override(array_merge(Settings::DEFAULTS, ['terminate_mode' => 'delete']));
$t->on('DELETE', WP . '/order/9001', 200, ['status' => 'Success']);
prov('wordpress', $t)->terminate($wpp);
check('WP terminate (delete mode): DELETE .../order/{id}', $t->count('DELETE', WP . '/order/9001') === 1);
Settings::override(Settings::DEFAULTS);
// adoption by domain with a list-shaped answer
freshDb('lbhwordpress', 'wordpresshostingusa:300109');
$t = transportWithCustomer();
$t->on('GET', WP, 200, [['orderid' => '5', 'domainname' => 'other.com', 'currentstatus' => 'Active'], ['orderid' => '6', 'domainname' => 'example.com', 'currentstatus' => 'Deleted'], ['orderid' => '7', 'domainname' => 'example.com', 'currentstatus' => 'Active', 'customerid' => '555']]);
prov('wordpress', $t)->create(params('wordpresshostingusa:300109'));
check('WP existing live order adopted from a list answer; deleted one skipped', (int) (new ServiceRepository())->get(10)->order_id === 7 && $t->count('POST', WP . '/order') === 0);
$srv = dirname(__DIR__, 3) . '/servers/';
check('WP module file: wordpress family, no ChangePassword entry point', str_contains((string) file_get_contents($srv . 'lbhwordpress/lbhwordpress.php'), "ServerModule::metaData('wordpress')") && !str_contains((string) file_get_contents($srv . 'lbhwordpress/lbhwordpress.php'), 'ChangePassword'));

// ------------------------------------------------------------------------------
section('Business Hosting');
const BH = 'api/businesshosting/linux/in';
freshDb('lbhbusiness', 'businesshostinglinuxin:41');
$t = transportWithCustomer();
$t->on('GET', BH . '/orderid.json', 500, ['status' => 'ERROR', 'message' => 'No Entity found']);
$t->on('POST', BH . '/add.json', 200, ['entityid' => '9001', 'actionstatus' => 'Success']);
$t->on('GET', BH . '/details.json', 200, ['entityid' => '9001', 'currentstatus' => 'Active', 'planid' => '41', 'endtime' => (string) (time() + 200 * 86400)]);
$bp = params('businesshostinglinuxin:41', ['configoptions' => ['Dedicated IP' => 1]]);
prov('business', $t)->create($bp);
$add = $t->last('POST', BH . '/add.json');
check('Business create: businesshosting/linux/in/add.json with enable-ssl + auto-renew', $add && $add['query']['enable-ssl'] === 'true' && $add['query']['auto-renew'] === 'false' && $add['query']['months'] === '12' && AddonManager::recorded(10, 'ssl') === 1);
check('...no manual task when the IP came with the order', Capsule::table('mod_lbh_manual_tasks')->count() === 0);
Capsule::table('mod_lbh_services')->where('service_id', 10)->update(['created_at' => date('Y-m-d H:i:s', time() - 30 * 86400)]);
$t->on('POST', BH . '/renew.json', 200, ['actionstatus' => 'Success']);
prov('business', $t)->renew($bp, 'module');
check('Business renew carries enable-ssl=true', ($t->last('POST', BH . '/renew.json')['query']['enable-ssl'] ?? '') === 'true');
$t->calls = [];
prov('business', $t)->changePackage(params('businesshostinglinuxin:41', ['configoptions' => ['Dedicated IP' => 0]]));
$task = Capsule::table('mod_lbh_manual_tasks')->first();
check('Business: removing the IP on a running order -> manual task, no SSL API call', $task && $task->item === 'dedicated_ip' && $task->target === '0' && $task->previous === '1' && !array_filter($t->calls, static fn ($c) => str_contains($c['path'], 'ssl')));
$t->on('GET', BH . '/modify-pricing.json', 200, ['upgradecost' => 5, 'remainingmonths' => 7]);
$t->on('POST', BH . '/modify.json', 200, ['actionstatus' => 'Success']);
prov('business', $t)->changePackage(params('businesshostinglinuxin:42', ['configoptions' => ['Dedicated IP' => 0]]));
check('Business plan change: modify.json with remaining months', ($t->last('POST', BH . '/modify.json')['query']['new-plan-id'] ?? '') === '42' && $t->last('POST', BH . '/modify.json')['query']['months'] === '7');
$t->on('POST', BH . '/change-password.json', 200, ['status' => 'Success']);
prov('business', $t)->changePassword($bp + ['password' => 'N3w-Passw0rd']);
check('Business panel password: change-password.json', $t->count('POST', BH . '/change-password.json') === 1 && str_contains((string) file_get_contents($srv . 'lbhbusiness/lbhbusiness.php'), 'function lbhbusiness_ChangePassword'));
$t->on('POST', 'api/orders/suspend.json', 200, ['actionstatus' => 'Success']);
prov('business', $t)->suspend($bp);
check('Business suspend: generic orders/suspend.json', $t->count('POST', 'api/orders/suspend.json') === 1);

// ------------------------------------------------------------------------------
section('Suspend / unsuspend / terminate / renew / password / sync / link');
freshDb('lbhmultidomain', 'multidomainhostinglinuxin:812');
$t = transportWithCustomer();
Capsule::table('mod_lbh_services')->insert(['service_id' => 10, 'family' => 'multidomain', 'account_id' => 1, 'product_key' => 'multidomainhostinglinuxin', 'order_id' => 9001, 'plan_id' => 812, 'created_at' => date('Y-m-d H:i:s', time() - 40 * 86400), 'expires_at' => date('Y-m-d H:i:s', time() + 300 * 86400)]);
$mp = params('multidomainhostinglinuxin:812', ['suspendreason' => 'Overdue on Invoice']);
$t->on('POST', 'api/orders/suspend.json', 200, ['actionstatus' => 'Success']);
$t->on('POST', 'api/orders/unsuspend.json', 200, ['actionstatus' => 'Success']);
prov('multidomain', $t)->suspend($mp);
check('suspend -> orders/suspend.json with reason', ($t->last('POST', 'api/orders/suspend.json')['query']['reason'] ?? '') === 'Overdue on Invoice' && (new ServiceRepository())->get(10)->provider_status === 'Suspended');
prov('multidomain', $t)->unsuspend($mp);
check('unsuspend -> orders/unsuspend.json', $t->count('POST', 'api/orders/unsuspend.json') === 1);
prov('multidomain', $t)->terminate($mp);
check('terminate default = suspend at provider', $t->count('POST', 'api/orders/suspend.json') === 2 && $t->count('POST', 'api/multidomainhosting/linux/in/delete.json') === 0);
Settings::override(array_merge(Settings::DEFAULTS, ['terminate_mode' => 'delete']));
$t->on('POST', 'api/multidomainhosting/linux/in/delete.json', 200, ['actionstatus' => 'Success']);
prov('multidomain', $t)->terminate($mp);
check('terminate delete mode -> delete.json', $t->count('POST', 'api/multidomainhosting/linux/in/delete.json') === 1);
Settings::override(array_merge(Settings::DEFAULTS, ['terminate_mode' => 'local']));
$t->calls = [];
prov('multidomain', $t)->terminate($mp);
check('terminate local mode -> no API call', $t->calls === []);
Settings::override(Settings::DEFAULTS);

$t->on('POST', 'api/multidomainhosting/linux/in/renew.json', 200, ['actionstatus' => 'Success']);
$t->on('GET', 'api/multidomainhosting/linux/in/details.json', 200, ['entityid' => '9001', 'endtime' => '1850000000']);
check('renew (module)', prov('multidomain', $t)->renew($mp, 'module') === 'renewed');
$rn = $t->last('POST', 'api/multidomainhosting/linux/in/renew.json');
check('renew sends months=12, auto-renew=false, invoice option', $rn['query']['months'] === '12' && $rn['query']['auto-renew'] === 'false' && $rn['query']['invoice-option'] === 'NoInvoice');
check('second renew (hook) for the same period is skipped', prov('multidomain', $t)->renew($mp, 'hook') === 'skipped' && $t->count('POST', 'api/multidomainhosting/linux/in/renew.json') === 1);
check('admin forced renew goes through', prov('multidomain', $t)->renew($mp, 'admin', true) === 'renewed' && $t->count('POST', 'api/multidomainhosting/linux/in/renew.json') === 2);
$t->script['POST api/multidomainhosting/linux/in/renew.json'] = [[200, json_encode(['status' => 'ERROR', 'message' => 'Insufficient funds'])]];
Capsule::table('mod_lbh_renewals')->delete();
throwsLike('failed renewal throws', fn () => prov('multidomain', $t)->renew($mp, 'module'), 'Insufficient funds');
check('failed renewal recorded as failed (retry allowed)', Capsule::table('mod_lbh_renewals')->where('status', 'failed')->count() === 1);
$t->script['POST api/multidomainhosting/linux/in/renew.json'] = [[200, json_encode(['actionstatus' => 'Success'])]];
check('retry after failure renews', prov('multidomain', $t)->renew($mp, 'module') === 'renewed');

$t->on('POST', 'api/multidomainhosting/linux/in/change-password.json', 200, ['status' => 'Success']);
$GLOBALS['moduleLog'] = [];
prov('multidomain', $t)->changePassword($mp + ['password' => 'N3w-Passw0rd']);
$cp = $t->last('POST', 'api/multidomainhosting/linux/in/change-password.json');
check('change password -> change-password.json new-passwd', ($cp['query']['new-passwd'] ?? '') === 'N3w-Passw0rd');
check('new password redacted in module log', strpos(json_encode($GLOBALS['moduleLog']), 'N3w-Passw0rd') === false);
throwsLike('short password refused', fn () => prov('multidomain', $t)->changePassword($mp + ['password' => 'short']), 'at least 8');
throwsLike('VPS has no panel password API', fn () => (new HostingApi(new LogicBoxesClient('1', 'k', true, null, $t)))->changePanelPassword('vps', 'virtualserverlinuxus', 1, 'x'), 'cannot change the panel password');

// plan change (MDH, up or down) with modify-pricing months
$t->on('GET', 'api/multidomainhosting/linux/in/modify-pricing.json', 200, ['upgradecost' => 12.5, 'remainingmonths' => 9, 'endtime' => '1850000000']);
$t->on('POST', 'api/multidomainhosting/linux/in/modify.json', 200, ['actionstatus' => 'Success']);
$t->script['GET api/multidomainhosting/linux/in/details.json'] = [[200, json_encode(['entityid' => '9001', 'planid' => '812'])]];
prov('multidomain', $t)->changePackage(params('multidomainhostinglinuxin:811'));
$m = $t->last('POST', 'api/multidomainhosting/linux/in/modify.json');
check('MDH plan change uses modify-pricing remainingmonths', $m['query']['new-plan-id'] === '811' && $m['query']['months'] === '9');
Capsule::table('mod_lbh_services')->where('service_id', 10)->update(['plan_changed_at' => null, 'plan_id' => 811]);
throwsLike('moving between locations refused', fn () => prov('multidomain', $t)->changePackage(params('multidomainhostinglinuxuk:900')), 'same location and OS');

// sync + link
$t->script['GET api/multidomainhosting/linux/in/details.json'] = [[200, json_encode(['entityid' => '9001', 'currentstatus' => 'Suspended', 'planid' => '811', 'plan_name' => 'MD Pro', 'ipaddress' => '203.0.113.50', 'siteadminusername' => 'mdpro', 'siteadminpassword' => 'xx-secret-xx', 'endtime' => '1861920000000'])]];
prov('multidomain', $t)->sync($mp);
$row = (new ServiceRepository())->get(10);
check('sync: status, plan, ip, user, expiry (ms timestamp)', $row->provider_status === 'Suspended' && $row->plan_name === 'MD Pro' && $row->ip_address === '203.0.113.50' && $row->panel_username === 'mdpro' && substr((string) $row->expires_at, 0, 4) === '2029');
check('sync: password never stored', strpos((string) $row->details_json, 'xx-secret-xx') === false && strpos((string) $row->details_json, '***') !== false);

freshDb('lbhmultidomain', 'multidomainhostinglinuxin:812');
$t = new ScriptedTransport();
$t->on('GET', 'api/multidomainhosting/linux/in/orderid.json', 500, ['status' => 'ERROR', 'message' => 'No Entity found']);
$t->on('GET', 'api/multidomainhosting/linux/us/orderid.json', 500, ['status' => 'ERROR', 'message' => 'No Entity found']);
// linux/usca left unscripted: an API error on one location must not stop the search
$t->on('GET', 'api/multidomainhosting/linux/uk/orderid.json', 200, '6060');
$t->on('GET', 'api/multidomainhosting/linux/uk/details.json', 200, ['entityid' => '6060', 'productkey' => 'multidomainhostinglinuxuk']);
check('Link Existing Order searches other locations', prov('multidomain', $t)->linkByDomain(params('multidomainhostinglinuxin:812')) === 6060 && (new ServiceRepository())->get(10)->product_key === 'multidomainhostinglinuxuk');
Capsule::table('mod_lbh_services')->insert(['service_id' => 11, 'family' => 'multidomain', 'account_id' => 1, 'order_id' => 6060]);
Capsule::table('mod_lbh_services')->where('service_id', 10)->update(['order_id' => null]);
throwsLike('Link refuses an order linked to another service', fn () => prov('multidomain', $t)->linkByDomain(params('multidomainhostinglinuxin:812')), 'already linked to service #11');

// ------------------------------------------------------------------------------
section('ServerModule contract, admin tab, renewal hook, client area');
freshDb('lbhsingledomain', 'singledomainhostinglinuxin:1001');
$t = transportWithCustomer();
ServerModule::$transport = $t;
$res = ServerModule::run('singledomain', 'SuspendAccount', params('singledomainhostinglinuxin:1001'), static fn (Provisioner $p, array $x) => $p->suspend($x));
check('unlinked service -> "Error: ..." string, no exception', str_starts_with($res, 'Error: ') && stripos($res, 'not linked') !== false);
check('error recorded in module log table', Capsule::table('mod_lbh_logs')->where('level', 'error')->count() === 1);
$opts = ServerModule::configOptions('vps');
check('server config options: 5 fields in order', array_keys($opts) === ['API Account', 'Plan', 'Invoice Option', 'Default OS', 'Included Add-ons']);
check('hosting config options: 3 fields', count(ServerModule::configOptions('singledomain')) === 3);
$t->on('GET', 'api/products/plan-details.json', 200, ['singledomainhostinglinuxin' => ['1001' => ['plan_name' => 'Starter']], 'singledomainhostingwindowstr' => ['77' => ['name' => 'Win TR']]]);
$loaded = ServerModule::loaderPlans('singledomain', params(''));
check('plan loader lists every location of the family', ($loaded['singledomainhostinglinuxin:1001'] ?? '') === 'Linux - India - Starter (singledomainhostinglinuxin:1001)' && isset($loaded['singledomainhostingwindowstr:77']));

Capsule::table('mod_lbh_services')->insert(['service_id' => 10, 'family' => 'singledomain', 'account_id' => 1, 'product_key' => 'singledomainhostinglinuxtr', 'order_id' => 9001, 'provider_status' => 'Active']);
\LbHosting\Service\AddonManager::record(10, 'ssl', 1);
$fields = ServerModule::adminServicesTabFields('singledomain', params('singledomainhostinglinuxin:1001'));
check('admin tab: order id, location with "path unverified" badge, add-ons', isset($fields['LogicBoxes Order ID']) && str_contains($fields['API Account / Location'], 'path unverified') && str_contains($fields['Add-ons applied by module'], 'ssl'));
$_POST['lbh_order_id'] = '1234';
$t->on('GET', 'api/singledomainhosting/linux/tr/details.json', 500, ['status' => 'ERROR', 'message' => 'No Entity found']);
$t->on('GET', 'api/singledomainhosting/linux/in/details.json', 200, ['entityid' => '1234', 'productkey' => 'singledomainhostinglinuxin']);
ServerModule::adminServicesTabFieldsSave('singledomain', params('singledomainhostinglinuxin:1001'));
check('admin re-link verified at LogicBoxes and saved', (int) (new ServiceRepository())->get(10)->order_id === 1234 && (new ServiceRepository())->get(10)->product_key === 'singledomainhostinglinuxin');
$_POST['lbh_order_id'] = '12ab';
ServerModule::adminServicesTabFieldsSave('singledomain', params('singledomainhostinglinuxin:1001'));
check('non-numeric order id rejected', (int) (new ServiceRepository())->get(10)->order_id === 1234 && ($_SESSION['lbh_admin_msg'][10][0] ?? '') === 'danger');
unset($_POST['lbh_order_id'], $_SESSION['lbh_admin_msg']);
$t->on('GET', 'api/singledomainhosting/linux/in/details.json', 200, ['entityid' => '1234', 'siteadminpassword' => 'leak-me-not', 'currentstatus' => 'Active']);
$t->script['GET api/singledomainhosting/linux/in/details.json'] = [[200, json_encode(['entityid' => '1234', 'siteadminpassword' => 'leak-me-not', 'currentstatus' => 'Active'])]];
check('Show Provider Details returns success', ServerModule::adminDetails('singledomain', params('singledomainhostinglinuxin:1001')) === 'success');
$msg = $_SESSION['lbh_admin_msg'][10][1] ?? '';
check('...and shows the payload without the password', str_contains($msg, '"currentstatus": "Active"') && !str_contains($msg, 'leak-me-not'));

// renewal hook
Capsule::table('mod_lbh_services')->where('service_id', 10)->update(['created_at' => date('Y-m-d H:i:s', time() - 30 * 86400)]);
Capsule::table('tblinvoiceitems')->insert(['invoiceid' => 71, 'type' => 'Hosting', 'relid' => 10, 'amount' => 1200]);
Capsule::table('tblinvoiceitems')->insert(['invoiceid' => 70, 'type' => 'Hosting', 'relid' => 10, 'amount' => 1200]);
$t->on('POST', 'api/singledomainhosting/linux/in/renew.json', 200, ['actionstatus' => 'Success']);
\LbHosting\Hooks\RenewalHook::onInvoicePaid(70);
check('InvoicePaid for the ORDER invoice does not renew', $t->count('POST', 'api/singledomainhosting/linux/in/renew.json') === 0);
\LbHosting\Hooks\RenewalHook::onInvoicePaid(71);
check('InvoicePaid for a renewal invoice renews once', $t->count('POST', 'api/singledomainhosting/linux/in/renew.json') === 1);
\LbHosting\Hooks\RenewalHook::onInvoicePaid(71);
check('...and is idempotent', $t->count('POST', 'api/singledomainhosting/linux/in/renew.json') === 1);

// client area
Capsule::table('mod_lbh_services')->where('service_id', 10)->update(['ip_address' => '203.0.113.7', 'panel_username' => 'site1', 'plan_name' => 'Starter', 'expires_at' => '2027-10-01 00:00:00']);
$ca = \LbHosting\WHMCS\ClientAreaController::render('singledomain', params('singledomainhostinglinuxin:1001'));
check('client area: template + vars from the service row', $ca['templatefile'] === 'templates/clientarea' && $ca['vars']['lbh_linked'] && $ca['vars']['lbh_ip'] === '203.0.113.7' && $ca['vars']['lbh_username'] === 'site1' && $ca['vars']['lbh_expires'] === '2027-10-01');
\LbHosting\I18n\Lang::reset();
$caEs = \LbHosting\WHMCS\ClientAreaController::render('singledomain', params('singledomainhostinglinuxin:1001', ['clientsdetails' => ['language' => 'spanish']]));
check('client area: Spanish strings with English fallback', $caEs['vars']['lbh_lang']['overview'] === 'Resumen del servicio');
$en = \LbHosting\I18n\Lang::strings('english');
$es = \LbHosting\I18n\Lang::strings('spanish');
check('every English key exists in Spanish', array_diff_key($en, include __DIR__ . '/../lang/spanish.php') === []);
Settings::override(array_merge(Settings::DEFAULTS, ['show_credentials' => '0']));
check('show_credentials off hides the username', \LbHosting\WHMCS\ClientAreaController::render('singledomain', params('singledomainhostinglinuxin:1001'))['vars']['lbh_username'] === '');

done();
