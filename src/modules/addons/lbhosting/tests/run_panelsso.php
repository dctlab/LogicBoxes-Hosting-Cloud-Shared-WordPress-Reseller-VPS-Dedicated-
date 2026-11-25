<?php
/** Server-side sign-in to the branded panel, straight to the Server Management Panel. Run: php tests/run_panelsso.php */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/run_phase5_helpers.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use LbHosting\Service\PanelSso;
use LbHosting\Support\Settings;
use LbHosting\WHMCS\ClientAreaController;
use LbHosting\WHMCS\ServerModule;

const BRAND = 'manage.example.com';
$log = [];
function panel(array $opts = []): void
{
    global $log;
    $log = [];
    PanelSso::$http = static function (string $m, string $url, array $form, string $cookie) use ($opts): array {
        global $log;
        $log[] = [$m, $url, $form, $cookie];
        $host = parse_url($url, PHP_URL_HOST);
        $path = parse_url($url, PHP_URL_PATH);
        $r = ['status' => 200, 'location' => '', 'cookies' => [], 'body' => ''];
        if ($host === BRAND && $path === '/servlet/AutoLoginServlet') {
            $r['body'] = '<HTML><body onLoad="javascript:document.MetaRefreshForm.submit()"><form name="MetaRefreshForm" action="https://www.foundationapi.com/servlet/ProcessAutoLoginOnSSLServlet" method="POST"><input type="hidden" name="userLoginId" value="' . ($opts['token'] ?? 'TOKEN-sso1234567890abc') . '"><input type="hidden" name="url" value="enc+/=x"><input type="hidden" name="role" value="customer"></form></body></HTML>';
        } elseif ($host === 'www.foundationapi.com') {
            $r = ['status' => 302, 'location' => 'https://' . BRAND . '/servlet/SessionServlet?k=1', 'cookies' => ['FA=1; Path=/'], 'body' => ''];
        } elseif ($host === BRAND && $path === '/servlet/SessionServlet') {
            $r = ['status' => 302, 'location' => '/servlet/CustomerHome', 'cookies' => empty($opts['nocookie']) ? ['JSESSIONID=sess42; Path=/; Secure; HttpOnly'] : [], 'body' => ''];
        } elseif ($host === BRAND && $path === '/servlet/CustomerHome') {
            $r['body'] = '<html>home</html>';
        } elseif ($host === BRAND && $path === '/servlet/ManageServiceServlet') {
            if (!str_contains($cookie, 'JSESSIONID=sess42')) {
                $r['body'] = 'You need to be logged in to perform this action.';
            } elseif (!empty($opts['post'])) {
                $r['body'] = '<body onload="document.f.submit()"><form name="f" method="post" action="https://panel.webhostbox.net/sso"><input type="hidden" name="t" value="abc&quot;1"></form></body>';
            } else {
                $r = ['status' => 302, 'location' => 'https://panel.webhostbox.net/?token=SMP-777', 'cookies' => [], 'body' => ''];
            }
        } elseif ($host === 'panel.webhostbox.net') {
            throw new RuntimeException('the hand-off target must never be requested by the server');
        }

        return $r;
    };
}

section('Direct Server Management Panel login (VPS)');
$t = linked('lbhvps', 'virtualserverlinuxus:10', [], ['panel_hide' => '1']);
$t->on('GET', 'api/customers/generate-login-token.json', 200, '"TOKEN-sso1234567890abc"');
$_SERVER['SERVER_ADDR'] = '198.51.100.20';
panel();
$r = act('vps', 'ClientPanel', 'virtualserverlinuxus:10', [], ['domain' => 'srv1.example.com']);
check('client goes straight to panel.webhostbox.net, never to the order panel', ($r['redirect'] ?? '') === 'https://panel.webhostbox.net/?token=SMP-777');
check('sign-in chain: AutoLoginServlet -> LogicBoxes SSL login (POST, fields kept) -> session -> order page', count($log) === 5 && $log[1][0] === 'POST' && $log[1][2]['url'] === 'enc+/=x' && $log[1][2]['userLoginId'] === 'TOKEN-sso1234567890abc'
    && str_contains($log[4][1], 'ManageServiceServlet?orderid=') && str_contains($log[4][3], 'JSESSIONID=sess42'));
check('token asked for the WHMCS server IP', $t->last('GET', 'api/customers/generate-login-token.json')['query']['ip'] === '198.51.100.20');
check('logged without token or panel link', str_contains(logText(), 'Server Management Panel opened directly (panel.webhostbox.net)') && !str_contains(logText(), 'SMP-777') && !str_contains(logText(), 'TOKEN-sso'));
Settings::override(array_merge(Settings::DEFAULTS, ['panel_sso_ip' => '203.0.113.99']));
panel();
act('vps', 'ClientPanel', 'virtualserverlinuxus:10', [], ['domain' => 'srv1.example.com']);
check('the IP setting wins', $t->last('GET', 'api/customers/generate-login-token.json')['query']['ip'] === '203.0.113.99');
Settings::override(Settings::DEFAULTS);

panel();
$r = act('vps', 'ClientPower', 'virtualserverlinuxus:10', ['lbh_power' => 'reboot'], ['domain' => 'srv1.example.com']);
check('power buttons go straight to the panel too', ($r['redirect'] ?? '') === 'https://panel.webhostbox.net/?token=SMP-777');

panel(['post' => true]);
$r = act('vps', 'ClientPanel', 'virtualserverlinuxus:10', [], ['domain' => 'srv1.example.com']);
$h = $r['html'] ?? '';
check('a POST hand-off is re-posted from the client browser, escaped', str_contains($h, 'action="https://panel.webhostbox.net/sso"') && str_contains($h, 'name="t" value="abc&quot;1"') && str_contains($h, 'document.forms[0].submit()'));

panel(['nocookie' => true]);
$r = act('vps', 'ClientPanel', 'virtualserverlinuxus:10', [], ['domain' => 'srv1.example.com']);
check('no session -> normal panel login (sign-in page), reason logged', str_contains($r['html'] ?? '', 'AutoLoginServlet') && str_contains(logText(), 'login_no_session'));

PanelSso::$http = static function (): array { return ['status' => 403, 'location' => '', 'cookies' => [], 'body' => 'Forbidden']; };
$r = act('vps', 'ClientPanel', 'virtualserverlinuxus:10', [], ['domain' => 'srv1.example.com']);
check('blocked (e.g. firewall 403) -> normal panel login', str_contains($r['html'] ?? '', 'AutoLoginServlet') && str_contains(logText(), 'http_403_at_manage.example.com'));

Settings::override(array_merge(Settings::DEFAULTS, ['panel_hide' => '0']));
panel();
$r = act('vps', 'ClientPanel', 'virtualserverlinuxus:10', [], ['domain' => 'srv1.example.com']);
check('switched off -> normal panel login, no server-side sign-in', str_contains($r['html'] ?? '', 'AutoLoginServlet') && $log === []);
Settings::override(Settings::DEFAULTS);

$t2 = linked('lbhcloud', 'cloudsiteslinuxus:300109');
$t2->on('GET', 'api/customers/generate-login-token.json', 200, '"TOKEN-cloud1234567890"');
panel();
$r = act('cloud', 'ClientPanel', 'cloudsiteslinuxus:300109');
check('hosting products keep the normal panel login', $log === [] && (isset($r['html']) || isset($r['redirect'])));

section('Admin test button');
$t = linked('lbhvps', 'virtualserverlinuxus:10', [], ['panel_hide' => '1']);
$t->on('GET', 'api/customers/generate-login-token.json', 200, '"TOKEN-sso1234567890abc"');
panel();
$_SESSION['lbh_admin_msg'] = [];
$res = ServerModule::adminPanelTest('vps', params('virtualserverlinuxus:10', ['domain' => 'srv1.example.com']));
$msg = $_SESSION['lbh_admin_msg'][10] ?? ['', ''];
check('admin test: success with steps, panel host only (no token)', $res === 'success' && $msg[0] === 'success' && str_contains($msg[1], 'https://panel.webhostbox.net/') && str_contains($msg[1], 'POST www.foundationapi.com/servlet/ProcessAutoLoginOnSSLServlet') && !str_contains($msg[1], 'SMP-777'));
panel(['nocookie' => true]);
ServerModule::adminPanelTest('vps', params('virtualserverlinuxus:10', ['domain' => 'srv1.example.com']));
$msg = $_SESSION['lbh_admin_msg'][10] ?? ['', ''];
check('admin test: failure explained', $msg[0] === 'warning' && str_contains($msg[1], 'login_no_session'));
check('admin button only on servers', isset(ServerModule::adminButtons('vps')['Test Server Panel Login']) && !isset(ServerModule::adminButtons('cloud')['Test Server Panel Login']));

section('Live VPS details shape (installed_os)');
$live = json_decode('{"domainname":"valuepassionshop.com","siteadminusername":"root","ipaddress":"66.116.235.199","planid":1813,"installed_os":{"os_name":"ubuntu_22","addons":[{"creation_time":"2026-10-31 01:01:33.126","addon_name":"ipaddress","addon_id":282948,"is_paid":false,"ipaddress":"66.116.197.104"}]},"productkey":"virtualserverlinuxin","customerid":"5537226","endtime":"1824940867","orderid":"127485590","entityid":"127485590","siteadminpassword":"x","currentstatus":"Active","paused":"false"}', true);
check('OS read from installed_os', \LbHosting\Service\ServiceRepository::osName($live) === 'ubuntu_22');
$ad = \LbHosting\Service\ServiceRepository::providerAddons($live);
check('add-ons read from installed_os, with their IP', count($ad) === 1 && $ad[0]['name'] === 'ipaddress' && $ad[0]['ip'] === '66.116.197.104' && $ad[0]['id'] === 282948);
check('extra IP listed, main IP not', \LbHosting\Service\ServiceRepository::addonIps($live) === ['66.116.197.104']);
$x = \LbHosting\Import\ProviderExtras::read('vps', $live);
check('import sees the OS and the IP add-on', $x['os'] === 'ubuntu_22' && ($x['addons']['ipaddress'] ?? 0) === 1);
$t = linked('lbhvps', 'virtualserverlinuxus:10', ['details_json' => json_encode(\LbHosting\Service\ServiceRepository::redactDetails($live)), 'ip_address' => '66.116.235.199']);
$v = ClientAreaController::render('vps', params('virtualserverlinuxus:10', ['domain' => 'srv1.example.com']))['vars'];
$labels = array_column($v['lbh_extras'], 'value', 'label');
check('client page: Additional IP addresses 66.116.197.104', ($labels['Additional IP addresses'] ?? '') === '66.116.197.104');

section('Page parsing');
check('meta refresh followed', PanelSso::forward('<meta http-equiv="refresh" content="0; url=/x/y">', 'https://a.b/c/d')[1] === 'https://a.b/x/y');
check('script redirect followed', PanelSso::forward('<script>window.location.href = "https://panel.webhostbox.net/#/vm";</script>', 'https://a.b/')[1] === 'https://panel.webhostbox.net/#/vm');
check('plain page settles', PanelSso::forward('<html><form action="/search"><input name="q"></form></html>', 'https://a.b/') === null);

done();
