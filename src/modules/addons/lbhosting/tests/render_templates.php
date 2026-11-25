<?php
/**
 * Compiles and renders every server module's clientarea.tpl with real Smarty 4
 * (security policy on), against vars produced by ClientAreaController.
 * Run: SMARTY=/path/to/smarty/libs php tests/render_templates.php
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use LbHosting\Catalog\ProductFamily;
use LbHosting\WHMCS\ClientAreaController;

$libs = getenv('SMARTY') ?: '';
if ($libs === '' || !is_file($libs . '/Smarty.class.php')) {
    echo "Set SMARTY=/path/to/smarty/libs\n";
    exit(2);
}
require $libs . '/Smarty.class.php';

$tmp = sys_get_temp_dir() . '/lbh-smarty-' . getmypid();
@mkdir($tmp);
foreach (ProductFamily::all() as $family) {
    $module = ProductFamily::module($family);
    $key = ProductFamily::defaultKey($family);
    freshDb($module, $key . ':1');
    Capsule::table('mod_lbh_services')->insert(['service_id' => 10, 'family' => $family, 'account_id' => 1, 'product_key' => $key, 'order_id' => 9001,
        'provider_status' => 'Active', 'plan_name' => '<b>Pro</b>', 'ip_address' => '203.0.113.9', 'panel_username' => '"><script>x()</script>', 'os' => 'alma_9', 'expires_at' => '2027-10-01 00:00:00']);
    Capsule::table('mod_lbh_manual_tasks')->insert(['service_id' => 10, 'item' => $family === 'dedicated' ? 'bandwidth' : 'dedicated_ip', 'target' => '<i>1</i>', 'status' => 'open', 'created_at' => date('Y-m-d H:i:s')]);
    // Phase 5: the page refreshes from LogicBoxes in the background and checks DNS live - both scripted here.
    $t = new ScriptedTransport();
    \LbHosting\WHMCS\ServerModule::$transport = $t;
    ClientAreaController::$dnsResolver = static fn (string $host, int $type): array => $type === DNS_NS ? [['target' => 'ns1.old-host.com'], ['target' => 'ns2.old-host.com']] : ($type === DNS_A ? [['ip' => '198.51.100.7']] : []);
    Capsule::table('mod_lbh_services')->where('service_id', 10)->update(['last_synced_at' => date('Y-m-d H:i:s'), 'dns_fetched_at' => date('Y-m-d H:i:s'),
        'dns_json' => json_encode([['type' => 'A', 'host' => '@', 'value' => '203.0.113.9', 'priority' => null], ['type' => 'MX', 'host' => '@', 'value' => 'mail.<x>.example.com', 'priority' => 10], ['type' => 'NS', 'host' => '@', 'value' => 'ns1.server.net', 'priority' => null], ['type' => 'NS', 'host' => '@', 'value' => 'ns2.server.net', 'priority' => null]])]);
    Capsule::table('mod_lbh_addons')->insert(['service_id' => 10, 'addon' => 'addon:cpanel', 'quantity' => 1]);
    Capsule::table('tbldomains')->insert(['id' => 3, 'userid' => 1, 'domain' => 'example.com', 'status' => 'Active']);
    \LbHosting\Support\Settings::override(array_merge(\LbHosting\Support\Settings::DEFAULTS, ['ns_mode' => 'server', 'dashboard_notice' => "Maintenance <b>Sunday</b>\nline two"]));
    $_SESSION = [];
    $res = ClientAreaController::render($family, params($key . ':1'));
    $v = $res['vars'];

    $s = new Smarty();
    $s->setCompileDir($tmp);
    $s->enableSecurity();
    $s->setTemplateDir(__DIR__ . '/../../../servers/' . $module);
    $s->assign($res['vars']);
    try {
        $html = $s->fetch($res['templatefile'] . '.tpl');
        check("$module template compiles and renders", str_contains($html, 'Service overview') && str_contains($html, '203.0.113.9'));
        check("$module escapes provider/admin data", !str_contains($html, '<script>x()') && !str_contains($html, '<b>Pro</b>') && !str_contains($html, '<i>1</i>') && str_contains($html, '&lt;b&gt;Pro'));
        check("$module shows the pending manual change", str_contains($html, 'Changes in progress'));
        check("$module dashboard: notice escaped, action forms carry the token", str_contains($html, 'Maintenance &lt;b&gt;Sunday') && str_contains($html, 'a=ClientRefresh') && str_contains($html, 'a=ClientRenew') && str_contains($html, 'a=ClientPanel') && substr_count($html, 'value="' . $v['lbh_action_token'] . '"') >= 3);
        $hasPanel = \LbHosting\Service\AutoLogin::panelType($family, $key) !== null;
        check("$module panel login button only where a hosting panel exists", str_contains($html, 'a=ClientLogin') === $hasPanel && str_contains($html, 'a=ClientPassword') === ProductFamily::can($family, 'panel_password') && str_contains($html, 'a=ClientShell') === ProductFamily::can($family, 'shell'));
        check("$module panel forms marked for the order-page step, script present", str_contains($html, 'ClientPanel" target="_blank" data-lbh-panel="1"') && str_contains($html, 'window.lbhPanelPage') && !str_contains($html, 'ClientPanel" target="_blank" rel="noopener"'));
        $isServer = ProductFamily::isServer($family);
        check("$module server power card only on VPS / Dedicated (3 buttons, token)", str_contains($html, 'Server Management Panel') === $isServer && substr_count($html, 'a=ClientPower') === ($isServer ? 3 : 0) && (!$isServer || (str_contains($html, 'name="lbh_power" value="reboot"') && str_contains($html, 'value="shutdown"') && str_contains($html, 'value="start"'))));
        if ($isServer) {
            $s->assign(['lbh_power_buttons' => ['reboot', 'start'], 'lbh_can' => ['refresh' => false] + $v['lbh_can']]);
            $part = $s->fetch($res['templatefile'] . '.tpl');
            check("$module only ticked power buttons and no Refresh", substr_count($part, 'a=ClientPower') === 2 && !str_contains($part, 'value="shutdown"') && str_contains($part, '>Start<') && !str_contains($part, 'a=ClientRefresh'));
            $s->assign(['lbh_power_buttons' => $v['lbh_power_buttons'], 'lbh_can' => $v['lbh_can']]);
        }
        if ($isServer) {
            $box = substr($html, strpos($html, 'lbh-power'), 6000);
            check("$module Server Management Panel: intro, login button, login details (escaped), credentials note", str_contains($box, 'perform crucial operations') && str_contains($box, 'Log in to Server Management Panel') && str_contains($box, 'a=ClientPanel') && str_contains($box, 'Login Details') && str_contains($box, '203.0.113.9') && str_contains($box, '&quot;&gt;&lt;script&gt;') && str_contains($box, 'change the credentials'));
            $s->assign(['lbh_can' => ['power' => false] + $v['lbh_can']]);
            $only = $s->fetch($res['templatefile'] . '.tpl');
            check("$module power off: box still offers the panel login", str_contains($only, 'Log in to Server Management Panel') && !str_contains($only, 'a=ClientPower'));
            $s->assign(['lbh_can' => ['power' => false, 'panel' => false] + $v['lbh_can']]);
            $info = $s->fetch($res['templatefile'] . '.tpl');
            $ibox = substr($info, strpos($info, 'lbh-power'), 6000);
            check("$module no login and no power: box still shows description and login details, no buttons", str_contains($ibox, 'perform crucial operations') && str_contains($ibox, 'Login Details') && str_contains($ibox, '203.0.113.9') && !str_contains($ibox, 'a=ClientPanel') && !str_contains($ibox, 'a=ClientPower'));
            $s->assign('lbh_show', ['power' => false] + $v['lbh_show']);
            check("$module box hidden when its section is unticked", !str_contains($s->fetch($res['templatefile'] . '.tpl'), 'Log in to Server Management Panel') && !str_contains($s->fetch($res['templatefile'] . '.tpl'), 'perform crucial operations'));
            $s->assign('lbh_show', $v['lbh_show']);
            $s->assign('lbh_can', $v['lbh_can']);
        }
        $s->assign('lbh_show', ['actions' => false, 'changes' => true, 'power' => false]);
        $hidden = $s->fetch($res['templatefile'] . '.tpl');
        check("$module Quick actions / power can be hidden", !str_contains($hidden, 'a=ClientRefresh') && !str_contains($hidden, 'a=ClientPower') && str_contains($hidden, 'Service overview'));
        $s->assign('lbh_show', $v['lbh_show']);
        check("$module add-ons listed", str_contains($html, 'Included with your service') && str_contains($html, 'cPanel'));
        if (ProductFamily::can($family, 'dns')) {
            check("$module DNS section: live state, target name servers, apply button, escaped", str_contains($html, 'ns1.old-host.com') && str_contains($html, 'ns1.server.net') && str_contains($html, 'a=ClientNameservers') && str_contains($html, 'Different') && str_contains($html, 'Missing') && str_contains($html, 'mail.&lt;x&gt;') && !str_contains($html, 'mail.<x>'));
        } else {
            check("$module has no DNS section", !str_contains($html, 'Name servers and DNS'));
        }
        check("$module makes no API call when everything is fresh", $t->calls === []);
        // Phase 4: change form, quote and pending states
        $up = ['plans' => [['product_id' => 7, 'name' => '<u>Big</u>', 'price' => '99.00']], 'currency' => 'USD', 'pending' => null, 'options' => [
            ['config_id' => 4, 'logical' => 'control_panel', 'name' => 'Panel', 'type' => 1, 'max' => 0, 'current' => 12, 'subs' => [['sub_id' => 11, 'label' => 'None'], ['sub_id' => 12, 'label' => 'cPanel']]],
            ['config_id' => 5, 'logical' => 'dedicated_ip', 'name' => 'Dedicated IP', 'type' => 3, 'max' => 0, 'current' => 1, 'subs' => []],
            ['config_id' => 6, 'logical' => 'block_storage', 'name' => 'Block Storage', 'type' => 4, 'max' => 500, 'current' => 50, 'subs' => []],
        ]];
        $s->assign(['lbh_upgrade' => $up, 'lbh_token' => 'tok"en', 'lbh_form_url' => 'clientarea.php?action=productdetails&id=10', 'lbh_quote' => null, 'lbh_error' => '', 'lbh_notice' => '']);
        $html = $s->fetch($res['templatefile'] . '.tpl');
        check("$module change form: plan list, three option types, escaped", str_contains($html, 'name="lbh_product"') && str_contains($html, '&lt;u&gt;Big') && !str_contains($html, '<u>Big</u>') && str_contains($html, 'value="12" selected') && str_contains($html, 'name="lbh_opt[6]"') && str_contains($html, 'max="500"') && substr_count($html, 'value="tok&quot;en"') === 2);
        $s->assign('lbh_quote', ['kind' => 'options', 'lines' => ['Block Storage: 50 -> <80>'], 'months' => 7, 'months_text' => 'for the 7 month(s) left on your term', 'amount' => '21.00', 'free' => false, 'currency' => 'USD', 'recurring' => '120.00', 'product_id' => 0, 'options' => [6 => 80]]);
        $html = $s->fetch($res['templatefile'] . '.tpl');
        check("$module quote: amount, term, confirm form carries the choice", str_contains($html, '21.00 USD') && str_contains($html, 'for the 7 month(s) left') && str_contains($html, 'name="lbh_opt[6]" value="80"') && str_contains($html, 'value="upgrade_confirm"') && str_contains($html, '50 -&gt; &lt;80&gt;'));
        $s->assign('lbh_show', ['actions' => true, 'changes' => false, 'power' => true]);
        check("$module change section can be hidden", !str_contains($s->fetch($res['templatefile'] . '.tpl'), 'name="lbh_product"'));
        $s->assign('lbh_show', $v['lbh_show']);
        $up['pending'] = ['status' => 'invoiced', 'summary' => 'Starter -> Pro', 'invoice_id' => 88];
        $s->assign(['lbh_upgrade' => $up, 'lbh_quote' => null, 'lbh_error' => 'Oops <x>']);
        $html = $s->fetch($res['templatefile'] . '.tpl');
        check("$module pending: pay link, no form, error escaped", str_contains($html, 'viewinvoice.php?id=88') && !str_contains($html, 'name="lbh_product"') && str_contains($html, 'Oops &lt;x&gt;'));
    } catch (\Throwable $e) {
        check("$module template", false, $e->getMessage());
    }
}
$s->assign(['lbh_ok' => false, 'lbh_lang' => \LbHosting\I18n\Lang::strings('english'), 'lbh_tasks' => []]);
check('error state renders', str_contains($s->fetch('templates/clientarea.tpl'), 'temporarily unavailable'));

done();
