<?php
/**
 * Offline test suite, Phase 5: client dashboard data, client actions (refresh,
 * renew now, panel login, order panel login, password, SSH, name servers), DNS
 * record parsing / live check and the name server automation.
 * Run: php tests/run_phase5.php
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use LbHosting\Service\AutoLogin;
use LbHosting\Service\DnsInspector;
use LbHosting\Service\DnsRecords;
use LbHosting\Service\NameserverAutomation;
use LbHosting\Service\ServiceRepository;
use LbHosting\Support\Csrf;
use LbHosting\Support\OutputSignal;
use LbHosting\Support\RedirectSignal;
use LbHosting\Support\Settings;
use LbHosting\WHMCS\ClientAreaController;
use LbHosting\Catalog\ProductFamily;
use LbHosting\WHMCS\ServerModule;

const SDH = 'api/singledomainhosting/linux/in';

require __DIR__ . '/run_phase5_helpers.php';

// ------------------------------------------------------------------------------
section('DNS record parsing (shape is not documented - several accepted)');
$list = DnsRecords::normalize([['type' => 'A', 'host' => '@', 'value' => '203.0.113.9'], ['type' => 'mx', 'host' => '@', 'value' => 'mail.example.com.', 'priority' => '10'], ['type' => 'SRV', 'host' => '_x', 'value' => 'y']]);
check('list of {type, host, value}', count($list) === 2 && $list[1] === ['type' => 'MX', 'host' => '@', 'value' => 'mail.example.com', 'priority' => 10]);
$byType = DnsRecords::normalize(['A' => [['hostname' => 'www', 'address' => '203.0.113.9']], 'NS' => [['name' => '@', 'value' => 'ns1.host.net'], ['name' => '@', 'value' => 'ns2.host.net']], 'status' => 'Success']);
check('map keyed by record type, alternative key names', count($byType) === 3 && $byType[0] === ['type' => 'A', 'host' => 'www', 'value' => '203.0.113.9', 'priority' => null]);
$numbered = DnsRecords::normalize(['recsonpage' => '2', '1' => ['type' => 'A', 'host' => '@', 'value' => '1.2.3.4'], '2' => ['type' => 'A', 'host' => '@', 'value' => '1.2.3.4'], '3' => ['recordtype' => 'TXT', 'host' => '@', 'content' => 'v=spf1 a ~all']]);
check('numbered map; duplicates removed', count($numbered) === 2 && $numbered[1]['type'] === 'TXT');
check('garbage in -> nothing out', DnsRecords::normalize('Success') === [] && DnsRecords::normalize(['a' => ['b' => ['c' => 1]]]) === []);
check('name servers from NS records', DnsRecords::nameservers($byType) === ['ns1.host.net', 'ns2.host.net']);
check('name servers from details keys when no NS records', DnsRecords::nameservers($list, ['ns1' => 'NS1.Srv.Net.', 'nameserver2' => 'ns2.srv.net', 'ns' => 'not a host']) === ['ns1.srv.net', 'ns2.srv.net']);

section('Live DNS check');
$resolver = static function (string $host, int $type): array {
    $zone = [
        'example.com' => [DNS_NS => [['target' => 'ns2.host.net'], ['target' => 'NS1.host.net.']], DNS_A => [['ip' => '203.0.113.9']], DNS_MX => [['pri' => 5, 'target' => 'other.mail.com']], DNS_TXT => [['txt' => 'v=spf1 include:_spf.google.com ~all']]],
    ];

    return $zone[$host][$type] ?? [];
};
$live = (new DnsInspector($resolver))->inspect('Example.com.', [['type' => 'A', 'host' => '@', 'value' => '203.0.113.9'], ['type' => 'MX', 'host' => '@', 'value' => 'mail.example.com', 'priority' => 10], ['type' => 'CNAME', 'host' => 'www', 'value' => 'example.com'], ['type' => 'TXT', 'host' => '@', 'value' => 'v=spf1 include:spf.host.net ~all']], false);
$states = array_column($live['records'], 'state');
check('ok / missing / missing / warning; name servers sorted', $states === ['ok', 'missing', 'missing', 'warning'] && $live['nameservers'] === ['ns1.host.net', 'ns2.host.net'] && !$live['all_ok'] && $live['records'][2]['display_host'] === 'www');

// ------------------------------------------------------------------------------
section('Auto login building blocks');
check('panel per product: cPanel, WHM, Plesk, none for servers / WordPress', AutoLogin::panelType('singledomain', 'singledomainhostinglinuxin') === 'cpanel' && AutoLogin::panelType('reseller', 'resellerhosting') === 'whm' && AutoLogin::panelType('reseller', 'resellerwindowshosting') === 'plesk'
    && AutoLogin::panelType('multidomain', 'multidomainwindowshostinguk') === 'plesk' && AutoLogin::panelType('cloud', 'cloudsiteslinuxus') === 'cpanel' && AutoLogin::panelType('vps', 'virtualserverlinuxus') === null && AutoLogin::panelType('wordpress', 'wordpresshostingusa') === null);
Settings::override(Settings::DEFAULTS);
$d = ['siteadminusername' => 'exampl', 'siteadminpassword' => 'P"w<1>', 'ipaddress' => '203.0.113.9'];
$f = AutoLogin::panelForm('cpanel', $d, 'example.com');
check('cPanel form: IP host, port 2083, user / pass', $f['action'] === 'https://203.0.113.9:2083/login/' && $f['fields'] === ['user' => 'exampl', 'pass' => 'P"w<1>']);
$f = AutoLogin::panelForm('plesk', $d + ['serverhostname' => 'Sg1.Host.Net'], 'example.com');
check('Plesk form: server host name preferred, port 8443, login_name / passwd', $f['action'] === 'https://sg1.host.net:8443/login_up.php' && array_keys($f['fields']) === ['login_name', 'passwd']);
Settings::override(array_merge(Settings::DEFAULTS, ['panel_host' => 'domain']));
check('panel host = domain', AutoLogin::panelForm('whm', $d, 'example.com')['action'] === 'https://example.com:2087/login/');
Settings::override(array_merge(Settings::DEFAULTS, ['panel_host' => 'ip']));
check('panel host = ip ignores the host name', AutoLogin::panelForm('cpanel', $d + ['serverhostname' => 'sg1.host.net'], 'example.com')['action'] === 'https://203.0.113.9:2083/login/');
Settings::override(Settings::DEFAULTS);
throwsLike('no server address -> never posts the password to the client\'s own domain', fn () => AutoLogin::panelForm('cpanel', ['siteadminusername' => 'x', 'siteadminpassword' => 'y'], 'example.com'), 'address of the hosting server is unknown');
throwsLike('no password in details -> no form', fn () => AutoLogin::panelForm('cpanel', ['siteadminusername' => 'x', 'ipaddress' => '1.2.3.4'], 'example.com'), 'did not return the panel login');
$html = AutoLogin::page(AutoLogin::panelForm('cpanel', $d, 'example.com'), 'Continue to');
check('login page: escaped values, auto submit, no referrer', str_contains($html, 'value="P&quot;w&lt;1&gt;"') && !str_contains($html, 'P"w<1>') && str_contains($html, '.submit()') && str_contains($html, 'no-referrer') && str_contains($html, 'method="post"'));

// ------------------------------------------------------------------------------
section('Client actions: guard');
$plan = 'singledomainhostinglinuxin:1001';
$t = linked('lbhsingledomain', $plan);
$r = act('singledomain', 'ClientRefresh', $plan, [], [], null, 'GET');
check('GET is refused', flash()['type'] === 'danger' && $t->calls === [] && str_contains($r['redirect'], 'action=productdetails&id=10'));
act('singledomain', 'ClientRefresh', $plan, [], [], 'forged');
check('bad token is refused', flash()['message'] === 'Your session expired. Please try again.' && $t->calls === []);
act('singledomain', 'ClientRefresh', $plan, [], [], Csrf::token('clientact11'));
check('another service\'s token is refused', flash()['type'] === 'danger' && $t->calls === []);
$_SESSION['uid'] = 2;
act('singledomain', 'ClientRefresh', $plan);
check('a different logged-in client is refused', flash()['type'] === 'danger' && $t->calls === []);
$_SESSION['uid'] = 1;
act('singledomain', 'ClientBogus', $plan);
check('unknown action refused', flash()['message'] === 'This action is not available for your service.' && $t->calls === []);

section('Refresh');
$t->on('GET', SDH . '/details.json', 200, ['entityid' => '9001', 'currentstatus' => 'Active', 'planid' => '1001', 'ipaddress' => '203.0.113.9', 'siteadminusername' => 'exampl', 'siteadminpassword' => 'S3cret!Pass', 'endtime' => (string) (time() + 100 * 86400)]);
$t->on('GET', SDH . '/dns-record.json', 200, [['type' => 'A', 'host' => '@', 'value' => '203.0.113.9'], ['type' => 'NS', 'host' => '@', 'value' => 'ns1.host.net'], ['type' => 'NS', 'host' => '@', 'value' => 'ns2.host.net']]);
$_SESSION['lbh_dns_abc'] = ['at' => time(), 'data' => []];
act('singledomain', 'ClientRefresh', $plan);
$row = (new ServiceRepository())->get(10);
check('refresh: details + DNS records re-read, DNS cache cleared, flash', $t->count('GET', SDH . '/details.json') === 1 && $t->count('GET', SDH . '/dns-record.json') === 1 && $row->ip_address === '203.0.113.9' && count(json_decode($row->dns_json, true)) === 3 && !isset($_SESSION['lbh_dns_abc']) && flash()['type'] === 'success');

section('Panel login');
$r = act('singledomain', 'ClientLogin', $plan);
check('cPanel login page posted from the browser with the live password', isset($r['html']) && str_contains($r['html'], 'action="https://203.0.113.9:2083/login/"') && str_contains($r['html'], 'name="user" value="exampl"') && str_contains($r['html'], 'name="pass" value="S3cret!Pass"'));
check('the password is never stored or logged', !str_contains(logText(), 'S3cret!Pass') && Capsule::table('mod_lbh_logs')->where('action', 'login')->count() === 1);
Settings::override(array_merge(Settings::DEFAULTS, ['allow_panel_login' => '0']));
$n = count($t->calls);
$r = act('singledomain', 'ClientLogin', $plan);
check('switched off in Settings -> refused, no API call', !isset($r['html']) && count($t->calls) === $n && flash()['type'] === 'danger');
Settings::override(Settings::DEFAULTS);
$r = act('singledomain', 'ClientLogin', $plan, [], ['status' => 'Suspended']);
check('suspended service -> refused', !isset($r['html']) && flash()['message'] === 'This is only available while your service is active.');
$t->script['GET ' . SDH . '/details.json'] = [[200, json_encode(['entityid' => '9001', 'currentstatus' => 'Active'])]];
$r = act('singledomain', 'ClientLogin', $plan);
check('LogicBoxes returns no password -> friendly error, logged', !isset($r['html']) && flash()['message'] === 'We could not log you in automatically. Please try again or contact support.' && Capsule::table('mod_lbh_logs')->where('action', 'clientlogin')->where('level', 'error')->count() === 1);

$t = linked('lbhreseller', 'resellerhosting:3001');
$t->on('GET', 'api/resellerhosting/linux/us/details.json', 200, ['entityid' => '9001', 'siteadminusername' => 'res1', 'siteadminpassword' => 'Wh0!secret', 'ipaddress' => '198.51.100.4']);
$r = act('reseller', 'ClientLogin', 'resellerhosting:3001');
check('Reseller Linux -> WHM on 2087', str_contains($r['html'] ?? '', 'https://198.51.100.4:2087/login/'));
$t = linked('lbhvps', 'virtualserverlinuxus:10');
$r = act('vps', 'ClientLogin', 'virtualserverlinuxus:10', [], ['domain' => 'srv1.example.com']);
check('VPS has no hosting panel login', !isset($r['html']) && $t->calls === [] && flash()['type'] === 'danger');

section('Order panel login (Sitebuilder, server panel, ...)');
Settings::override(array_merge(Settings::DEFAULTS, ['panel_order_page' => '0']));
$t->on('GET', 'api/customers/generate-login-token.json', 200, '"TOKEN-abc123def456ghi789"');
$r = act('vps', 'ClientPanel', 'virtualserverlinuxus:10', [], ['domain' => 'srv1.example.com']);
$call = $t->last('GET', 'api/customers/generate-login-token.json');
check('token for the service\'s customer and the client\'s IP', $call['query']['customer-id'] === '555' && $call['query']['ip'] === '203.0.113.50');
check('redirect to the branded AutoLoginServlet over https', ($r['redirect'] ?? '') === 'https://manage.example.com/servlet/AutoLoginServlet?userLoginId=TOKEN-abc123def456ghi789&role=customer');
check('the token is not written to the module log table', !str_contains(logText(), 'TOKEN-abc123'));
Settings::override(Settings::DEFAULTS);
$r = act('vps', 'ClientPanel', 'virtualserverlinuxus:10', [], ['domain' => 'srv1.example.com']);
$oid = (string) Capsule::table('mod_lbh_services')->where('service_id', 10)->value('order_id');
$h = $r['html'] ?? '';
check('order page on: sign-in page logs in first, then hands the order page to the dashboard', str_contains($h, 'window.location.replace(login)') && str_contains($h, 'https://manage.example.com/servlet/AutoLoginServlet?userLoginId=TOKEN-abc123def456ghi789\\u0026role=customer')
    && str_contains($h, 'https://manage.example.com/servlet/ManageServiceServlet?orderid=' . $oid . '\\u0026productkey=virtualserverlinuxus\\u0026servicename=virtualserverlinuxus') && str_contains($h, 'lbhPanelPage(window.name,page,6000)') && !str_contains($h, 'redirecturl'));
Settings::override(array_merge(Settings::DEFAULTS, ['panel_order_delay' => '99']));
$r = act('vps', 'ClientPanel', 'virtualserverlinuxus:10', [], ['domain' => 'srv1.example.com']);
check('delay is capped at 30 seconds', str_contains($r['html'] ?? '', ',30000)'));
Settings::override(array_merge(Settings::DEFAULTS, ['panel_order_page' => '0']));
$t->script['GET api/customers/generate-login-token.json'] = [[200, 'UNQUOTEDtoken1234567890']];
$r = act('vps', 'ClientPanel', 'virtualserverlinuxus:10', [], ['domain' => 'srv1.example.com']);
check('an unquoted token body works too', str_contains($r['redirect'] ?? '', 'userLoginId=UNQUOTEDtoken1234567890'));
$t->script['GET api/customers/generate-login-token.json'] = [[200, '"TOKEN-abc123def456ghi789"']];
Capsule::table('mod_lbh_accounts')->where('id', 1)->update(['branding_url' => 'javascript:alert(1)']);
$r = act('vps', 'ClientPanel', 'virtualserverlinuxus:10', [], ['domain' => 'srv1.example.com']);
check('a bad branding URL never becomes a redirect', str_contains($r['redirect'], 'clientarea.php?action=productdetails') && flash()['type'] === 'danger');
Capsule::table('mod_lbh_accounts')->where('id', 1)->update(['branding_url' => '']);
$t->on('GET', 'api/resellers/details.json', 200, ['brandingurl' => 'http://cp.brand.net/']);
$r = act('vps', 'ClientPanel', 'virtualserverlinuxus:10', [], ['domain' => 'srv1.example.com']);
check('branding URL falls back to resellers/details.json', str_starts_with($r['redirect'], 'https://cp.brand.net/servlet/AutoLoginServlet?'));
(new ServiceRepository())->upsert(10, ['customer_id' => null]);
$before = $t->count('GET', 'api/customers/generate-login-token.json');
$r = act('vps', 'ClientPanel', 'virtualserverlinuxus:10', [], ['domain' => 'srv1.example.com']);
check('unknown customer -> error, no token requested for id 0', flash()['type'] === 'danger' && $t->count('GET', 'api/customers/generate-login-token.json') === $before);

// ------------------------------------------------------------------------------
section('Server power (Reboot / Shut down / Start)');
$t = linked('lbhvps', 'virtualserverlinuxus:10', [], ['panel_order_page' => '0']);
$t->on('GET', 'api/customers/generate-login-token.json', 200, '"TOKEN-pow123def456ghi789"');
$v = ClientAreaController::render('vps', params('virtualserverlinuxus:10', ['domain' => 'srv1.example.com']))['vars'];
check('VPS: power buttons offered, all sections shown by default', $v['lbh_can']['power'] === true && $v['lbh_show'] === ['actions' => true, 'changes' => true, 'power' => true]);
$r = act('vps', 'ClientPower', 'virtualserverlinuxus:10', ['lbh_power' => 'reboot'], ['domain' => 'srv1.example.com']);
check('Reboot -> server panel one-click login', ($r['redirect'] ?? '') === 'https://manage.example.com/servlet/AutoLoginServlet?userLoginId=TOKEN-pow123def456ghi789&role=customer');
check('the request is logged, not the token', count(array_filter(Capsule::table('mod_lbh_logs')->where('action', 'power')->get()->all(), static fn ($l) => str_contains($l->message, 'reboot'))) === 1 && !str_contains(logText(), 'TOKEN-pow123'));
foreach (['shutdown', 'start'] as $p) {
    $r = act('vps', 'ClientPower', 'virtualserverlinuxus:10', ['lbh_power' => $p], ['domain' => 'srv1.example.com']);
    check("$p -> server panel", str_contains($r['redirect'] ?? '', 'AutoLoginServlet'));
}
$n = $t->count('GET', 'api/customers/generate-login-token.json');
$r = act('vps', 'ClientPower', 'virtualserverlinuxus:10', ['lbh_power' => 'format'], ['domain' => 'srv1.example.com']);
check('unknown power request refused, no token', flash()['type'] === 'danger' && $t->count('GET', 'api/customers/generate-login-token.json') === $n);
$r = act('vps', 'ClientPower', 'virtualserverlinuxus:10', ['lbh_power' => 'reboot'], ['domain' => 'srv1.example.com'], 'bad-token');
check('bad CSRF token refused', flash()['type'] === 'danger' && $t->count('GET', 'api/customers/generate-login-token.json') === $n);
$r = (function () { Settings::override(Settings::DEFAULTS); return act('vps', 'ClientPower', 'virtualserverlinuxus:10', ['lbh_power' => 'reboot'], ['domain' => 'srv1.example.com']); })();
check('power with order page on -> sign-in page then order page', str_contains($r['html'] ?? '', 'ManageServiceServlet?orderid='));
$n = $t->count('GET', 'api/customers/generate-login-token.json');
Settings::override(array_merge(Settings::DEFAULTS, ['allow_power' => '0']));
$r = act('vps', 'ClientPower', 'virtualserverlinuxus:10', ['lbh_power' => 'reboot'], ['domain' => 'srv1.example.com']);
$refused = flash()['type'] ?? '';
$v = ClientAreaController::render('vps', params('virtualserverlinuxus:10', ['domain' => 'srv1.example.com']))['vars'];
check('power switched off: no buttons, request refused', !$v['lbh_can']['power'] && $refused === 'danger' && $t->count('GET', 'api/customers/generate-login-token.json') === $n);
Settings::override(array_merge(Settings::DEFAULTS, ['power_buttons' => 'reboot', 'allow_refresh' => '0']));
$r = act('vps', 'ClientPower', 'virtualserverlinuxus:10', ['lbh_power' => 'shutdown'], ['domain' => 'srv1.example.com']);
$refused = flash()['type'] ?? '';
$r2 = act('vps', 'ClientRefresh', 'virtualserverlinuxus:10', [], ['domain' => 'srv1.example.com']);
$refused2 = flash()['type'] ?? '';
$v = ClientAreaController::render('vps', params('virtualserverlinuxus:10', ['domain' => 'srv1.example.com']))['vars'];
check('only ticked power buttons shown; hidden ones and Refresh refused', $v['lbh_power_buttons'] === ['reboot'] && $v['lbh_can']['power'] && !$v['lbh_can']['refresh'] && $refused === 'danger' && $refused2 === 'danger' && $t->count('GET', 'api/customers/generate-login-token.json') === $n);
Settings::override(Settings::DEFAULTS);
(new ServiceRepository())->upsert(10, ['expires_at' => '2027-10-31 00:00:00']);
$v = ClientAreaController::render('vps', params('virtualserverlinuxus:10', ['domain' => 'srv1.example.com']))['vars'];
$shown = $v['lbh_expires'];
Settings::override(array_merge(Settings::DEFAULTS, ['dash_expires' => 'cloud,dedicated']));
$v = ClientAreaController::render('vps', params('virtualserverlinuxus:10', ['domain' => 'srv1.example.com']))['vars'];
check('Expires date can be hidden per product type', $v['lbh_expires'] === '' && $shown === '2027-10-31');
Settings::override(array_merge(Settings::DEFAULTS, ['power_buttons' => '']));
$v = ClientAreaController::render('vps', params('virtualserverlinuxus:10', ['domain' => 'srv1.example.com']))['vars'];
check('no power buttons ticked -> Server power box hidden', !$v['lbh_can']['power']);
Settings::override(array_merge(Settings::DEFAULTS, ['dash_power' => 'dedicated', 'dash_actions' => 'cloud', 'dash_changes' => '']));
$r = act('vps', 'ClientPower', 'virtualserverlinuxus:10', ['lbh_power' => 'reboot'], ['domain' => 'srv1.example.com']);
$refused = flash()['type'] ?? '';
$v = ClientAreaController::render('vps', params('virtualserverlinuxus:10', ['domain' => 'srv1.example.com']))['vars'];
check('sections hidden per product type (VPS not ticked)', $v['lbh_show'] === ['actions' => false, 'changes' => false, 'power' => false] && $v['lbh_upgrade'] === null && $refused === 'danger' && $t->count('GET', 'api/customers/generate-login-token.json') === $n);
Settings::override(Settings::DEFAULTS);
(new ServiceRepository())->upsert(10, ['provider_status' => 'Suspended']);
$v = ClientAreaController::render('vps', params('virtualserverlinuxus:10', ['domain' => 'srv1.example.com']))['vars'];
$r = act('vps', 'ClientPower', 'virtualserverlinuxus:10', ['lbh_power' => 'start'], ['domain' => 'srv1.example.com']);
check('suspended server: no power buttons, request refused', !$v['lbh_can']['power'] && flash()['type'] === 'danger' && $t->count('GET', 'api/customers/generate-login-token.json') === $n);
$t = linked('lbhcloud', 'cloudsiteslinuxus:300109');
$v = ClientAreaController::render('cloud', params('cloudsiteslinuxus:300109'))['vars'];
$r = act('cloud', 'ClientPower', 'cloudsiteslinuxus:300109', ['lbh_power' => 'reboot']);
check('hosting products never get power buttons', !$v['lbh_can']['power'] && flash()['type'] === 'danger' && $t->count('GET', 'api/customers/generate-login-token.json') === 0);

// ------------------------------------------------------------------------------
section('Password change');
$t = linked('lbhsingledomain', $plan);
$t->on('POST', SDH . '/change-password.json', 200, ['actionstatus' => 'Success']);
act('singledomain', 'ClientPassword', $plan, ['lbh_password' => 'Abcdef1!x', 'lbh_password2' => 'Abcdef1!y']);
check('mismatch refused', flash()['message'] === 'The two passwords do not match.' && $t->calls === []);
foreach (['Short1!a', 'alllowercase1!', 'NOLOWER123!', 'NoDigits!!aa', 'NoSymbol123a', 'Has space1!A', 'WayTooLong1!abcdefg'] as $weak) {
    act('singledomain', 'ClientPassword', $plan, ['lbh_password' => $weak, 'lbh_password2' => $weak]);
}
check('weak passwords refused before any API call', $t->calls === [] && str_contains(flash()['message'], '9 to 16 characters'));
act('singledomain', 'ClientPassword', $plan, ['lbh_password' => 'Gr8!Passw0rd', 'lbh_password2' => 'Gr8!Passw0rd']);
$c = $t->last('POST', SDH . '/change-password.json');
check('strong password -> change-password.json', $c && $c['query']['new-passwd'] === 'Gr8!Passw0rd' && $c['query']['order-id'] === '9001' && flash()['type'] === 'success');
check('the new password is not in the logs', !str_contains(logText(), 'Gr8!Passw0rd'));
$t->script['POST ' . SDH . '/change-password.json'] = [[200, json_encode(['status' => 'ERROR', 'message' => 'Password policy <x>'])]];
act('singledomain', 'ClientPassword', $plan, ['lbh_password' => 'Gr8!Passw0rd', 'lbh_password2' => 'Gr8!Passw0rd']);
check('provider error -> generic message to the client, detail in the log', flash()['message'] === 'The password could not be changed. Please try again or contact support.' && Capsule::table('mod_lbh_logs')->where('level', 'error')->count() === 1);
$t = linked('lbhsingledomain', $plan);
$t->on('POST', SDH . '/change-password.json', 200, ['actionstatus' => 'Success']);
act('singledomain', 'ClientPassword', $plan, ['lbh_password' => 'Tom&amp;Jerry1&lt;', 'lbh_password2' => 'Tom&amp;Jerry1&lt;']);
check('WHMCS-encoded input is decoded: the password is set exactly as typed', ($t->last('POST', SDH . '/change-password.json')['query']['new-passwd'] ?? '') === 'Tom&Jerry1<');
$GLOBALS['keepRate'] = true;
$n = count($t->calls);
act('singledomain', 'ClientPassword', $plan, ['lbh_password' => 'Gr8!Passw0rd', 'lbh_password2' => 'Gr8!Passw0rd']);
check('repeating an action at once is throttled', count($t->calls) === $n && flash()['message'] === 'Please wait a moment before trying that again.');
$GLOBALS['keepRate'] = false;
act('singledomain', 'ClientRefresh', $plan, [], ['status' => 'Terminated']);
check('a terminated service cannot trigger API calls', count($t->calls) === $n && flash()['type'] === 'danger');
$t = linked('lbhvps', 'virtualserverlinuxus:10');
act('vps', 'ClientPassword', 'virtualserverlinuxus:10', ['lbh_password' => 'Gr8!Passw0rd', 'lbh_password2' => 'Gr8!Passw0rd'], ['domain' => 'srv1.example.com']);
check('VPS: no panel password API -> refused', $t->calls === [] && flash()['type'] === 'danger');

section('Cloud SSH');
$t = linked('lbhcloud', 'cloudsiteslinuxus:300109');
$t->on('POST', 'api/cloudsites/linux/us/enable-shell-access.json', 200, ['actionstatus' => 'Success']);
$t->on('POST', 'api/cloudsites/linux/us/disable-shell-access.json', 200, ['actionstatus' => 'Success']);
act('cloud', 'ClientShell', 'cloudsiteslinuxus:300109', ['lbh_enable' => '1']);
check('enable -> enable-shell-access.json', $t->count('POST', 'api/cloudsites/linux/us/enable-shell-access.json') === 1 && flash()['message'] === 'SSH access has been enabled.');
act('cloud', 'ClientShell', 'cloudsiteslinuxus:300109', ['lbh_enable' => '0']);
check('disable -> disable-shell-access.json', $t->count('POST', 'api/cloudsites/linux/us/disable-shell-access.json') === 1 && flash()['message'] === 'SSH access has been disabled.');
$t = linked('lbhsingledomain', $plan);
act('singledomain', 'ClientShell', $plan, ['lbh_enable' => '1']);
check('not offered outside Cloud Hosting', $t->calls === [] && flash()['type'] === 'danger');

// ------------------------------------------------------------------------------
section('Renew now');
$t = linked('lbhsingledomain', $plan);
$r = act('singledomain', 'ClientRenew', $plan);
$inv = Capsule::table('tblinvoices')->first();
$item = Capsule::table('tblinvoiceitems')->first();
check('due on the service\'s own due date, never earlier', Capsule::table('tblinvoices')->count() === 1 && in_array(['CreateInvoice', '2027-10-01'], array_map(static fn ($c) => [$c[0], $c[1]['duedate'] ?? ''], $GLOBALS['localApi']), true));
check('raises an invoice for the recurring amount and opens it', $inv && (float) $inv->total === 1200.0 && $r['redirect'] === 'viewinvoice.php?id=' . $inv->id);
check('the item is a Hosting renewal of this service at its due date', $item->type === 'Hosting' && (int) $item->relid === 10 && $item->duedate === '2027-10-01');
check('nothing is renewed at LogicBoxes until it is paid', $t->calls === [] && Capsule::table('mod_lbh_renewals')->where('status', 'invoiced')->count() === 1);
$r2 = act('singledomain', 'ClientRenew', $plan);
check('second click: the same unpaid invoice, no duplicate', $r2['redirect'] === $r['redirect'] && Capsule::table('tblinvoices')->count() === 1);
$t->on('POST', SDH . '/renew.json', 200, ['actionstatus' => 'Success']);
\LbHosting\Hooks\RenewalHook::onInvoicePaid((int) $inv->id);
$rn = $t->last('POST', SDH . '/renew.json');
check('paying it renews the order for the billing term', $rn && $rn['query']['months'] === '12' && $rn['query']['order-id'] === '9001');
Settings::override(array_merge(Settings::DEFAULTS, ['allow_client_renew' => '0']));
Capsule::table('tblinvoices')->update(['status' => 'Paid']);
act('singledomain', 'ClientRenew', $plan);
check('switched off -> refused', Capsule::table('tblinvoices')->count() === 1 && flash()['type'] === 'danger');
Settings::override(Settings::DEFAULTS);
Capsule::table('tblhosting')->where('id', 10)->update(['billingcycle' => 'Free Account', 'amount' => 0]);
act('singledomain', 'ClientRenew', $plan);
check('free / one-time services cannot be renewed', Capsule::table('tblinvoices')->count() === 1 && flash()['message'] === 'This service cannot be renewed online.');

// ------------------------------------------------------------------------------
section('Name server automation');
$dnsBody = [['type' => 'A', 'host' => '@', 'value' => '203.0.113.9'], ['type' => 'CNAME', 'host' => 'www.example.com', 'value' => 'example.com'], ['type' => 'MX', 'host' => '@', 'value' => 'mail.example.com', 'priority' => 10], ['type' => 'NS', 'host' => '@', 'value' => 'ns1.host.net'], ['type' => 'NS', 'host' => '@', 'value' => 'ns2.host.net']];
$t = linked('lbhsingledomain', $plan);
$t->on('GET', SDH . '/dns-record.json', 200, $dnsBody);
Capsule::table('tbldomains')->insert(['id' => 3, 'userid' => 1, 'domain' => 'example.com', 'status' => 'Active', 'ns' => 'ns1.old.com,ns2.old.com']);
$auto = new NameserverAutomation($t);
$r = $auto->apply('singledomain', params($plan), 'create');
check('mode off: nothing happens, nothing recorded', $r['status'] === 'skipped' && $t->calls === [] && NameserverAutomation::job(10) === null);

Settings::override(array_merge(Settings::DEFAULTS, ['ns_mode' => 'server']));
$GLOBALS['localApi'] = [];
$r = $auto->apply('singledomain', params($plan), 'create');
check('server mode: the hosting server\'s NS set through the WHMCS registrar', $r['status'] === 'done' && $r['nameservers'] === ['ns1.host.net', 'ns2.host.net'] && Capsule::table('tbldomains')->where('id', 3)->value('ns') === 'ns1.host.net,ns2.host.net');
check('server mode never touches LogicBoxes DNS', $t->count('POST', 'api/dns/activate.json') === 0 && $t->count('GET', 'api/domains/orderid.json') === 0);
$n = count(array_filter($GLOBALS['localApi'], static fn ($c) => $c[0] === 'DomainUpdateNameservers'));
$auto->apply('singledomain', params($plan), 'create');
check('the automatic run happens once per service', count(array_filter($GLOBALS['localApi'], static fn ($c) => $c[0] === 'DomainUpdateNameservers')) === $n && $n === 1);
$auto->apply('singledomain', params($plan), 'admin', true);
check('forced run with the name servers already right: no registrar call', count(array_filter($GLOBALS['localApi'], static fn ($c) => $c[0] === 'DomainUpdateNameservers')) === 1 && NameserverAutomation::job(10)->source === 'admin');

Capsule::table('tbldomains')->where('id', 3)->update(['userid' => 2, 'ns' => 'ns1.old.com,ns2.old.com']);
$r = $auto->apply('singledomain', params($plan), 'client', true);
check('a domain of ANOTHER client is never touched', $r['status'] === 'skipped' && $r['message'] === 'domain_not_in_whmcs' && Capsule::table('tbldomains')->where('id', 3)->value('ns') === 'ns1.old.com,ns2.old.com');
Capsule::table('tbldomains')->where('id', 3)->update(['userid' => 1, 'status' => 'Expired']);
check('an expired domain is never touched', $auto->apply('singledomain', params($plan), 'client', true)['status'] === 'skipped');
Capsule::table('tbldomains')->where('id', 3)->update(['status' => 'Pending']);
Capsule::table('mod_lbh_dns_jobs')->delete();
$auto->apply('singledomain', params($plan), 'create');
Capsule::table('tbldomains')->where('id', 3)->update(['status' => 'Active']);
check('domain still Pending at Create: skipped, then finished by the daily retry once it is Active', $auto->retryPending() === 1 && NameserverAutomation::job(10)->status === 'done' && Capsule::table('tbldomains')->where('id', 3)->value('ns') === 'ns1.host.net,ns2.host.net');
check('nothing left to retry afterwards', $auto->retryPending() === 0);
Capsule::table('tbldomains')->where('id', 3)->update(['ns' => 'ns1.old.com,ns2.old.com']);
Capsule::table('tbldomains')->where('id', 3)->update(['status' => 'Active']);
$GLOBALS['failRegistrar'] = true;
$r = $auto->apply('singledomain', params($plan), 'client', true);
check('registrar refuses -> failed, logged', $r['status'] === 'failed' && $r['message'] === 'registrar_failed' && Capsule::table('mod_lbh_logs')->where('action', 'nameservers')->where('level', 'error')->count() === 1);
$GLOBALS['failRegistrar'] = false;
$t->script['GET ' . SDH . '/dns-record.json'] = [[200, json_encode([['type' => 'A', 'host' => '@', 'value' => '203.0.113.9']])]];
$r = $auto->apply('singledomain', params($plan), 'client', true);
check('server NS unknown -> failed without guessing', $r['status'] === 'failed' && $r['message'] === 'no_server_ns' && Capsule::table('tbldomains')->where('id', 3)->value('ns') === 'ns1.old.com,ns2.old.com');

// branded
$t = linked('lbhsingledomain', $plan, [], ['ns_mode' => 'branded']);
$t->on('GET', SDH . '/dns-record.json', 200, $dnsBody);
Capsule::table('tbldomains')->insert(['id' => 3, 'userid' => 1, 'domain' => 'example.com', 'status' => 'Active', 'ns' => 'ns1.old.com,ns2.old.com']);
$t->on('GET', 'api/domains/orderid.json', 200, '777');
$t->on('POST', 'api/dns/activate.json', 200, ['status' => 'Success']);
$t->on('GET', 'api/dns/manage/search-records.json', 200, ['recsonpage' => '1', '1' => ['type' => 'A', 'host' => '@', 'value' => '203.0.113.9']]);
$t->on('GET', 'api/dns/manage/search-records.json', 200, ['recsonpage' => '0']);
foreach (['add-ipv4-record', 'add-cname-record', 'add-mx-record'] as $c) {
    $t->on('POST', 'api/dns/manage/' . $c . '.json', 200, ['status' => 'Success']);
}
$auto = new NameserverAutomation($t);
$r = $auto->apply('singledomain', params($plan), 'create');
check('branded mode: the API account\'s name servers', $r['status'] === 'done' && $r['nameservers'] === ['ns1.example.net', 'ns2.example.net'] && Capsule::table('tbldomains')->where('id', 3)->value('ns') === 'ns1.example.net,ns2.example.net');
check('zone activated on the domain order', ($t->last('POST', 'api/dns/activate.json')['query']['order-id'] ?? '') === '777');
$mx = $t->last('POST', 'api/dns/manage/add-mx-record.json');
$cn = $t->last('POST', 'api/dns/manage/add-cname-record.json');
check('existing A record skipped; CNAME (relative host) and MX (priority) added; NS records not copied', $t->count('POST', 'api/dns/manage/add-ipv4-record.json') === 0 && $cn['query']['host'] === 'www' && $cn['query']['value'] === 'example.com' && $mx['query']['priority'] === '10' && !isset($mx['query']['host']) && $r['records_added'] === 2 && $t->count('POST', 'api/dns/manage/add-ns-record.json') === 0);
$t->script['GET api/domains/orderid.json'] = [[500, json_encode(['status' => 'ERROR', 'message' => 'Website doesn\'t exist for example.com'])]];
Capsule::table('tbldomains')->where('id', 3)->update(['ns' => 'ns1.old.com,ns2.old.com']);
$r = $auto->apply('singledomain', params($plan), 'admin', true);
check('domain not in this LogicBoxes account: no zone possible, so the name servers are NOT switched', $r['status'] === 'failed' && $r['message'] === 'zone_elsewhere' && $t->count('POST', 'api/dns/activate.json') === 1 && Capsule::table('tbldomains')->where('id', 3)->value('ns') === 'ns1.old.com,ns2.old.com');
$t->script['GET api/domains/orderid.json'] = [[200, '777']];
$t->script['GET ' . SDH . '/dns-record.json'] = [[200, json_encode([])]];
$r = $auto->apply('singledomain', params($plan), 'admin', true);
check('no DNS records from LogicBoxes yet: name servers NOT switched to an empty zone', $r['status'] === 'failed' && $r['message'] === 'no_records' && Capsule::table('tbldomains')->where('id', 3)->value('ns') === 'ns1.old.com,ns2.old.com');
$t->script['GET ' . SDH . '/dns-record.json'] = [[200, json_encode($dnsBody)]];
$t->script['POST api/dns/manage/add-mx-record.json'] = [[200, json_encode(['status' => 'ERROR', 'message' => 'Invalid value'])]];
$t->script['GET api/dns/manage/search-records.json'] = [[200, json_encode(['recsonpage' => '0'])]];
$r = $auto->apply('singledomain', params($plan), 'admin', true);
check('a record cannot be added: name servers NOT switched to a half-built zone', $r['status'] === 'failed' && $r['message'] === 'zone_partial' && Capsule::table('tbldomains')->where('id', 3)->value('ns') === 'ns1.old.com,ns2.old.com');
$t->script['POST api/dns/manage/add-mx-record.json'] = [[200, json_encode(['status' => 'Success'])]];
$t->script['POST api/dns/activate.json'] = [[200, json_encode(['status' => 'ERROR', 'message' => 'Insufficient permissions'])]];
$r = $auto->apply('singledomain', params($plan), 'admin', true);
check('zone cannot be activated: name servers NOT switched', $r['status'] === 'failed' && Capsule::table('tbldomains')->where('id', 3)->value('ns') === 'ns1.old.com,ns2.old.com');
$t->script['POST api/dns/activate.json'] = [[200, json_encode(['status' => 'Success'])]];
Capsule::table('mod_lbh_accounts')->where('id', 1)->update(['nameservers' => 'ns1.example.net']);
$r = $auto->apply('singledomain', params($plan), 'admin', true);
check('fewer than two branded name servers -> failed', $r['status'] === 'failed' && $r['message'] === 'no_branded_ns');
check('admin button reports it', str_contains(ServerModule::adminNameservers('singledomain', params($plan)), 'at least two branded name servers'));

section('Name servers: on Create and from the client button');
freshDb('lbhsingledomain', $plan);
Settings::override(array_merge(Settings::DEFAULTS, ['ns_mode' => 'server']));
$t = transportWithCustomer();
ServerModule::$transport = $t;
$t->on('POST', SDH . '/add.json', 200, ['entityid' => '9001', 'actionstatus' => 'Success']);
$t->on('GET', SDH . '/orderid.json', 500, ['status' => 'ERROR', 'message' => 'Order Id not found for domain']);
$t->on('GET', SDH . '/details.json', 200, ['entityid' => '9001', 'currentstatus' => 'Active', 'planid' => '1001']);
$t->on('GET', SDH . '/dns-record.json', 200, $dnsBody);
Capsule::table('tbldomains')->insert(['id' => 3, 'userid' => 1, 'domain' => 'example.com', 'status' => 'Active', 'ns' => 'ns1.old.com,ns2.old.com']);
$res = ServerModule::run('singledomain', 'CreateAccount', params($plan), static fn ($p, $x) => $p->create($x));
check('Create also points the client\'s WHMCS domain at the hosting', $res === 'success' && Capsule::table('tbldomains')->where('id', 3)->value('ns') === 'ns1.host.net,ns2.host.net' && NameserverAutomation::job(10)->source === 'create');
$t->script['GET ' . SDH . '/dns-record.json'] = [[500, json_encode(['status' => 'ERROR', 'message' => 'boom'])]];
Capsule::table('mod_lbh_dns_jobs')->delete();
Capsule::table('mod_lbh_services')->where('service_id', 10)->update(['dns_json' => null]);
check('a name server failure never fails Create', ServerModule::run('singledomain', 'CreateAccount', params($plan), static fn ($p, $x) => $p->create($x)) === 'success' && NameserverAutomation::job(10)->status === 'failed');
$t->script['GET ' . SDH . '/dns-record.json'] = [[200, json_encode($dnsBody)]];
Capsule::table('tbldomains')->where('id', 3)->update(['ns' => 'ns1.old.com,ns2.old.com']);
$_SESSION = ['uid' => 1];
act('singledomain', 'ClientNameservers', $plan);
check('client button applies them and says so', Capsule::table('tbldomains')->where('id', 3)->value('ns') === 'ns1.host.net,ns2.host.net' && flash()['type'] === 'success');
Settings::override(array_merge(Settings::DEFAULTS, ['ns_mode' => 'server', 'ns_client_button' => '0']));
Capsule::table('tbldomains')->where('id', 3)->update(['ns' => 'ns1.old.com,ns2.old.com']);
act('singledomain', 'ClientNameservers', $plan);
check('client button switched off -> refused', Capsule::table('tbldomains')->where('id', 3)->value('ns') === 'ns1.old.com,ns2.old.com' && flash()['type'] === 'danger');

// ------------------------------------------------------------------------------
section('Dashboard data');
$t = linked('lbhsingledomain', $plan, ['last_synced_at' => date('Y-m-d H:i:s', time() - 7200), 'ip_address' => '203.0.113.9'], ['ns_mode' => 'server']);
$t->on('GET', SDH . '/details.json', 200, ['entityid' => '9001', 'currentstatus' => 'Active', 'planid' => '1001', 'siteadminusername' => 'exampl', 'siteadminpassword' => 'S3cret!Pass', 'dedicatedips' => ['198.51.100.1']]);
$t->on('GET', SDH . '/dns-record.json', 200, $dnsBody);
Capsule::table('tbldomains')->insert(['id' => 3, 'userid' => 1, 'domain' => 'example.com', 'status' => 'Active', 'ns' => 'ns1.old.com,ns2.old.com']);
Capsule::table('mod_lbh_addons')->insert(['service_id' => 10, 'addon' => 'ssl', 'quantity' => 1]);
ClientAreaController::$dnsResolver = $resolver;
$_SERVER['REQUEST_METHOD'] = 'GET';
$v = ClientAreaController::render('singledomain', params($plan))['vars'];
check('stale data is refreshed in the background (one details call, one DNS call)', $t->count('GET', SDH . '/details.json') === 1 && $t->count('GET', SDH . '/dns-record.json') === 1 && $v['lbh_username'] === 'exampl');
check('actions offered: cPanel login, order panel, renew, refresh, password; no SSH', $v['lbh_can'] === ['refresh' => true, 'renew' => true, 'login' => true, 'panel' => true, 'password' => true, 'shell' => false, 'power' => false] && $v['lbh_panel_label'] === 'Log in to cPanel');
check('extras: add-on with a readable label, dedicated IPs', $v['lbh_extras'][0] === ['label' => 'Dedicated IP address', 'value' => 'Yes'] && $v['lbh_extras'][1]['value'] === '198.51.100.1');
$dns = $v['lbh_dns'];
check('DNS: live NS equal the server NS -> ok, no apply button; records with states', $dns['ns_ok'] && !$dns['can_apply'] && $dns['target'] === ['ns1.host.net', 'ns2.host.net'] && array_column($dns['records'], 'state') === ['ok', 'missing', 'missing'] && $dns['records'][0]['state_class'] === 'success');
check('no password reaches the template', !str_contains(json_encode($v), 'S3cret!Pass'));
$v = ClientAreaController::render('singledomain', params($plan))['vars'];
check('second view: no API call (fresh for 30 minutes, DNS cached)', $t->count('GET', SDH . '/details.json') === 1 && $t->count('GET', SDH . '/dns-record.json') === 1);
ClientAreaController::$dnsResolver = static fn (string $h, int $type): array => $type === DNS_NS ? [['target' => 'ns1.old.com'], ['target' => 'ns2.old.com']] : [];
$_SESSION = ['uid' => 1];
$v = ClientAreaController::render('singledomain', params($plan))['vars'];
check('domain on other name servers -> apply button offered', !$v['lbh_dns']['ns_ok'] && $v['lbh_dns']['can_apply']);
Settings::override(array_merge(Settings::DEFAULTS, ['show_dns' => '0', 'allow_panel_login' => '0', 'allow_order_panel' => '0', 'allow_password_change' => '0', 'allow_client_renew' => '0', 'show_credentials' => '0', 'dashboard_notice' => 'Hello']));
$v = ClientAreaController::render('singledomain', params($plan))['vars'];
check('every section can be switched off in Settings', $v['lbh_dns'] === null && $v['lbh_can'] === ['refresh' => true, 'renew' => false, 'login' => false, 'panel' => false, 'password' => false, 'shell' => false, 'power' => false] && $v['lbh_username'] === '' && $v['lbh_notice_lines'] === ['Hello']);
Settings::override(Settings::DEFAULTS);
(new ServiceRepository())->upsert(10, ['provider_status' => 'Suspended']);
$v = ClientAreaController::render('singledomain', params($plan))['vars'];
check('suspended at LogicBoxes: no logins / password, renew still offered', !$v['lbh_can']['login'] && !$v['lbh_can']['panel'] && !$v['lbh_can']['password'] && $v['lbh_can']['renew'] && $v['lbh_status_class'] === 'warning');

$t = linked('lbhsingledomain', $plan, ['last_synced_at' => date('Y-m-d H:i:s', time() - 7200)]);
$t->on('GET', SDH . '/details.json', 500, 'Bad gateway');
$t->on('GET', SDH . '/dns-record.json', 500, 'Bad gateway');
$v = ClientAreaController::render('singledomain', params($plan))['vars'];
$calls = count($t->calls);
$v2 = ClientAreaController::render('singledomain', params($plan))['vars'];
check('LogicBoxes down: the page still renders and does not retry on every view', $v['lbh_ok'] && $v['lbh_linked'] && count($t->calls) === $calls && $calls === 2);
$t = linked('lbhcloud', 'cloudsiteslinuxus:300109');
$v = ClientAreaController::render('cloud', params('cloudsiteslinuxus:300109'))['vars'];
check('Cloud: SSH switch offered', $v['lbh_can']['shell'] === true);
$t = linked('lbhdedicated', 'dedicatedserverlinuxus:1');
$v = ClientAreaController::render('dedicated', params('dedicatedserverlinuxus:1', ['domain' => 'srv1.example.com']))['vars'];
check('Dedicated: order panel only, no DNS section, no API call', $v['lbh_can']['panel'] && !$v['lbh_can']['login'] && !$v['lbh_can']['password'] && $v['lbh_dns'] === null && $t->calls === []);

section('Template refresh after an update');
$tmpd = sys_get_temp_dir() . '/lbh-tpl-' . getmypid();
foreach (ProductFamily::all() as $f) { @mkdir($tmpd . '/' . ProductFamily::module($f) . '/templates', 0777, true); touch($tmpd . '/' . ProductFamily::module($f) . '/templates/clientarea.tpl', time() - 86400); }
$n1 = ClientAreaController::refreshTemplates($tmpd);
clearstatcache();
$fresh = filemtime($tmpd . '/lbhvps/templates/clientarea.tpl') > time() - 60;
$n2 = ClientAreaController::refreshTemplates($tmpd);
check('new version: all 8 templates re-dated once, not again on the next view', $n1 === 8 && $fresh && $n2 === 0 && Settings::get('tpl_version') === LBH_VERSION);

done();
