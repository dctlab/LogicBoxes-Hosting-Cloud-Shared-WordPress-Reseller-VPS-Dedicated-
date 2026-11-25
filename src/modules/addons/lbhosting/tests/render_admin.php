<?php
/**
 * Renders every admin page and exercises the POST flows (CSRF, validation,
 * account save, manual task completion). Run: php tests/render_admin.php
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use LbHosting\Admin\AddonController;
use LbHosting\Support\Csrf;

session_id('lbhtest');
$_SESSION = [];
freshDb('lbhcloud', 'cloudsiteslinuxus:300109');
Capsule::table('mod_lbh_manual_tasks')->insert(['id' => 1, 'service_id' => 10, 'item' => 'dedicated_ip', 'target' => '1', 'previous' => '0', 'status' => 'open', 'todo_id' => 5, 'ticket_id' => 9, 'created_at' => date('Y-m-d H:i:s')]);
Capsule::table('tbltodolist')->insert(['id' => 5, 'title' => 'x', 'status' => 'Pending']);
Capsule::table('mod_lbh_logs')->insert(['service_id' => 10, 'action' => 'create', 'level' => 'error', 'message' => '<script>alert(1)</script>', 'created_at' => date('Y-m-d H:i:s')]);

function page(string $p, array $get = []): string
{
    $_GET = ['page' => $p] + $get;
    $_SERVER['REQUEST_METHOD'] = 'GET';

    return (new AddonController(['modulelink' => 'addonmodules.php?module=lbhosting']))->dispatch();
}

function post(string $p, array $data, bool $token = true): string
{
    $_GET = ['page' => $p];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = $data + ($token ? ['lbh_token' => Csrf::token('admin')] : []);
    $out = (new AddonController(['modulelink' => 'addonmodules.php?module=lbhosting']))->dispatch();
    $_POST = [];

    return $out;
}

section('pages render');
foreach (['dashboard' => 'What each product supports', 'accounts' => 'RC Test', 'account' => 'Branded name servers', 'tasks' => 'Mark done', 'settings' => 'Manual fulfilment', 'languages' => 'spanish', 'logs' => 'create'] as $p => $needle) {
    $html = page($p);
    check("page $p renders", str_contains($html, $needle), substr(strip_tags($html), 0, 200));
}
check('dashboard shows the open manual task', str_contains(page('dashboard'), '1 change(s) need doing by hand'));
check('log messages are escaped', !str_contains(page('logs'), '<script>alert(1)</script>') && str_contains(page('logs'), '&lt;script&gt;'));
check('account form never prints the API key', !str_contains(page('account', ['id' => 1]), 'SECRETKEY123'));

section('POST flows');
$out = post('tasks', ['do' => 'task_done', 'id' => 1], false);
check('POST without token rejected, nothing changed', str_contains($out, 'Security token expired') && Capsule::table('mod_lbh_manual_tasks')->where('id', 1)->value('status') === 'open');
$out = post('tasks', ['do' => 'task_done', 'id' => 1, 'note' => 'IP 203.0.113.5 added']);
check('task marked done, To-Do completed', Capsule::table('mod_lbh_manual_tasks')->where('id', 1)->value('status') === 'done' && Capsule::table('tbltodolist')->where('id', 5)->value('status') === 'Completed');
$out = post('account', ['do' => 'save_account', 'id' => 0, 'name' => 'NE1', 'brand' => 'netearthone', 'reseller_id' => 'abc', 'api_key' => 'KEY12345']);
check('validation: non-numeric reseller id', str_contains($out, 'Reseller ID must be numeric'));
$out = post('account', ['do' => 'save_account', 'id' => 0, 'name' => 'NE1', 'brand' => 'netearthone', 'reseller_id' => '999', 'api_key' => 'KEY12345', 'nameservers' => 'ns1.bad_host']);
check('validation: bad name server', str_contains($out, 'not a valid host name'));
$out = post('account', ['do' => 'save_account', 'id' => 0, 'name' => 'NE1', 'brand' => 'netearthone', 'reseller_id' => '999', 'api_key' => 'KEY12345', 'nameservers' => "ns1.brand.com\nNS2.brand.com."]);
$row = Capsule::table('mod_lbh_accounts')->where('name', 'NE1')->first();
check('account saved with encrypted key and normalised NS', $row && $row->api_key_enc === encrypt('KEY12345') && $row->nameservers === "ns1.brand.com\nns2.brand.com");
check('connection test result shown (no network in tests -> failed)', str_contains($out, 'Connection test failed'));
$out = post('settings', ['do' => 'save_settings', 'terminate_mode' => 'nuke', 'manual_ticket_dept' => '7', 'manual_todo' => '1']);
check('settings: invalid terminate mode falls back to suspend; dept saved', Capsule::table('mod_lbh_settings')->where('setting', 'terminate_mode')->value('value') === 'suspend' && Capsule::table('mod_lbh_settings')->where('setting', 'manual_ticket_dept')->value('value') === '7');
Capsule::table('mod_lbh_services')->insert(['service_id' => 10, 'family' => 'cloud', 'account_id' => 1, 'order_id' => 9]);
$out = post('accounts', ['do' => 'delete_account', 'id' => 1]);
check('account with linked services cannot be deleted', str_contains($out, 'still has linked services') && Capsule::table('mod_lbh_accounts')->where('id', 1)->exists());
$id = (int) Capsule::table('mod_lbh_accounts')->where('name', 'NE1')->value('id');
post('accounts', ['do' => 'delete_account', 'id' => $id]);
check('unused account deleted', !Capsule::table('mod_lbh_accounts')->where('id', $id)->exists());

section('pricing pages');
$now = date('Y-m-d H:i:s');
foreach ([['plan', 'renew', 12, 99], ['plan', 'renew', 1, 129], ['ssl', '', 0, 150]] as [$item, $action, $months, $price]) {
    Capsule::table('mod_lbh_prices')->insert(['account_id' => 1, 'product_key' => 'singledomainhostinglinuxin', 'plan_id' => 1001, 'kind' => 'sell', 'item' => $item, 'action' => $action, 'months' => $months, 'price' => $price, 'fetched_at' => $now]);
}
Capsule::table('mod_lbh_prices')->insert(['account_id' => 1, 'product_key' => 'singledomainhostinglinuxtr', 'plan_id' => 77, 'kind' => 'sell', 'item' => 'plan', 'action' => 'renew', 'months' => 12, 'price' => 50, 'fetched_at' => $now]);
Capsule::table('mod_lbh_plans')->insert(['account_id' => 1, 'product_key' => 'singledomainhostinglinuxin', 'plan_id' => 1001, 'plan_name' => '<b>Starter</b>', 'details_json' => '{}', 'fetched_at' => $now]);
Capsule::table('mod_lbh_price_snapshots')->insert(['account_id' => 1, 'kind' => 'sell', 'payload' => '{"singledomainhostinglinuxin":{}}', 'fetched_at' => $now]);
$html = page('catalog', ['family' => 'singledomain', 'kind' => 'sell']);
check('catalog page: plan, per-month column, ssl extra, raw JSON', str_contains($html, '1,188.00') && str_contains($html, '129.00') && str_contains($html, 'ssl 150.00') && str_contains($html, 'Raw price JSON'));
check('catalog page: Clear catalog form (this type / all, fetch again)', str_contains($html, 'value="clear_catalog"') && str_contains($html, '<option value="all">All product types</option>') && str_contains($html, 'name="refetch"'));
check('catalog escapes plan names from LogicBoxes', !str_contains($html, '<b>Starter</b>') && str_contains($html, '&lt;b&gt;Starter'));
$html = page('generator', ['family' => 'singledomain']);
$snapRows = Capsule::table('mod_lbh_price_snapshots')->count();

check('generator page: plans listed, unverified location badge, options section', str_contains($html, 'singledomainhostinglinuxin:1001') && str_contains($html, 'path unverified') && str_contains($html, 'name="price_source"') && !str_contains($html, 'Dedicated IP price (fallback)'));
check('generator page for a family without prices points to the catalogue', str_contains(page('generator', ['family' => 'vps']), 'fetch prices first'));
$out = post('generator', ['do' => 'generate', 'family' => 'singledomain', 'account_id' => 1, 'plans' => ['singledomainhostinglinuxin:1001', 'bogus:1'], 'new_group' => 'SDH', 'create_options' => '1', 'price_source' => 'sell', 'markup_pct' => '0', 'rounding' => '2dp', 'source_currency_id' => '1', 'sync_prices' => '1']);
$flash = $out;
check('POST generate creates the product (unknown plan values ignored)', Capsule::table('mod_lbh_product_map')->count() === 1 && str_contains($flash, '1 product(s) created'));
$mapId = (int) Capsule::table('mod_lbh_product_map')->value('id');
$html = page('products');
check('products page lists the mapping, in sync', str_contains($html, 'Single Domain Hosting') && str_contains($html, 'Everything is in sync'));
$html = page('map', ['id' => $mapId]);
check('map page shows option price source', str_contains($html, 'catalogue: ssl') && str_contains($html, 'Dedicated IP'));
Capsule::table('mod_lbh_option_map')->where('map_id', $mapId)->update(['price_item' => 'manual', 'manual_price' => 10]);
$optId = (int) Capsule::table('mod_lbh_option_map')->where('map_id', $mapId)->value('id');
$out = post('map', ['do' => 'save_option_prices', 'id' => $mapId, 'manual_price' => [$optId => 'abc']]);
check('manual price validation', str_contains($out, 'positive numbers') && (float) Capsule::table('mod_lbh_option_map')->where('id', $optId)->value('manual_price') === 10.0);
post('map', ['do' => 'save_option_prices', 'id' => $mapId, 'manual_price' => [$optId => '12.5']]);
check('manual price saved', (float) Capsule::table('mod_lbh_option_map')->where('id', $optId)->value('manual_price') === 12.5);
$html = page('products');
check('changed manual price shows as pending option change', str_contains($html, 'Option: Dedicated IP') && str_contains($html, 'Apply'));
post('products', ['do' => 'sync_apply']);
check('apply from the page', str_contains(page('products'), 'Everything is in sync'));
post('products', ['do' => 'unmap', 'id' => $mapId]);
check('unmap keeps the WHMCS product', Capsule::table('mod_lbh_product_map')->count() === 0 && Capsule::table('tblproducts')->where('servertype', 'lbhsingledomain')->count() >= 1);
post('settings', ['do' => 'save_settings', 'price_basis' => 'per_term', 'extras_basis' => 'per_year', 'price_action' => 'nonsense', 'price_sync_enabled' => '1']);
check('pricing settings saved and validated', Capsule::table('mod_lbh_settings')->where('setting', 'price_basis')->value('value') === 'per_term' && Capsule::table('mod_lbh_settings')->where('setting', 'extras_basis')->value('value') === 'per_year' && Capsule::table('mod_lbh_settings')->where('setting', 'price_action')->value('value') === 'renew');

section('upgrades queue');
$now = date('Y-m-d H:i:s');
$mk = static fn (int $id, string $status, array $o = []) => Capsule::table('mod_lbh_upgrades')->insert($o + ['id' => $id, 'service_id' => 10, 'client_id' => 1, 'kind' => 'options', 'payload' => '{"options":{}}', 'summary' => '<b>CPU</b>: 1 -> 2', 'months' => 5, 'provider_cost' => 20, 'amount' => 22, 'currency_id' => 1, 'invoice_id' => 70 + $id, 'status' => $status, 'error' => $status === 'failed' ? 'Insufficient <funds>' : null, 'created_at' => $now, 'updated_at' => $now]);
$mk(1, 'failed');
$mk(2, 'invoiced');
$mk(3, 'applied');
$html = page('upgrades');
check('queue: default view shows only what needs attention', str_contains($html, 'Insufficient &lt;funds&gt;') && str_contains($html, 'Retry') && !str_contains($html, '#73'));
check('queue escapes the summary', !str_contains($html, '<b>CPU</b>') && str_contains($html, '&lt;b&gt;CPU'));
check('queue: "all" lists every change, Retry only where it applies', substr_count(page('upgrades', ['show' => 'all']), 'value="upgrade_retry"') === 1 && substr_count(page('upgrades', ['show' => 'all']), 'value="upgrade_cancel"') === 2);
check('dashboard warns about paid-but-unapplied changes', str_contains(page('dashboard'), '1 PAID change(s) have not been applied'));
$out = post('upgrades', ['do' => 'upgrade_retry', 'id' => 1], false);
check('retry without a token is rejected', str_contains($out, 'Security token expired') && Capsule::table('mod_lbh_upgrades')->where('id', 1)->value('status') === 'failed');
$out = post('upgrades', ['do' => 'upgrade_retry', 'id' => 1]);
check('retry that fails again reports the reason', str_contains($out, 'was not applied') && Capsule::table('mod_lbh_upgrades')->where('id', 1)->value('status') === 'failed');
$out = post('upgrades', ['do' => 'upgrade_retry', 'id' => 3]);
check('an applied change cannot be retried', str_contains($out, 'was not applied') && Capsule::table('mod_lbh_upgrades')->where('id', 3)->value('status') === 'applied');
post('upgrades', ['do' => 'upgrade_cancel', 'id' => 2]);
check('cancel an invoiced change', Capsule::table('mod_lbh_upgrades')->where('id', 2)->value('status') === 'cancelled');
$out = post('upgrades', ['do' => 'upgrade_cancel', 'id' => 3]);
check('an applied change cannot be cancelled', str_contains($out, 'Only invoiced or failed'));
check('settings page has the upgrade mode and Block Storage plan', str_contains(page('settings'), 'name="upgrade_mode"') && str_contains(page('settings'), 'name="block_storage_plan_id"'));
post('settings', ['do' => 'save_settings', 'upgrade_mode' => 'bogus', 'block_storage_plan_id' => '12a']);
check('upgrade settings validated', Capsule::table('mod_lbh_settings')->where('setting', 'upgrade_mode')->value('value') === 'provider' && Capsule::table('mod_lbh_settings')->where('setting', 'block_storage_plan_id')->value('value') === '');
post('settings', ['do' => 'save_settings', 'upgrade_mode' => 'whmcs', 'block_storage_plan_id' => '77']);
check('whmcs mode saved; queue page says the flow is off', Capsule::table('mod_lbh_settings')->where('setting', 'upgrade_mode')->value('value') === 'whmcs' && str_contains(page('upgrades'), 'switched off'));
Capsule::table('mod_lbh_block_volumes')->insert(['service_id' => 10, 'order_id' => 9500, 'size_gb' => 50, 'status' => 'Active']);
$tab = \LbHosting\WHMCS\ServerModule::adminServicesTabFields('cloud', ['serviceid' => 10]);
(new \LbHosting\Service\ServiceRepository())->upsert(10, ['details_json' => json_encode(['entityid' => '9001', 'ipaddress' => '<b>1.2.3.4</b>', 'rootpassword' => '***'])]);
$tab2 = \LbHosting\WHMCS\ServerModule::adminServicesTabFields('cloud', ['serviceid' => 10]);
check('admin service tab: LogicBoxes details shown, escaped', str_contains($tab2['LogicBoxes details'] ?? '', '&lt;b&gt;1.2.3.4') && !str_contains($tab2['LogicBoxes details'], '<b>1.2.3.4'));
check('admin service tab: volume and pending change', str_contains($tab['Block Storage'] ?? '', '50 GB') && str_contains($tab['Pending change'] ?? '', 'failed') && !str_contains($tab['Pending change'], '<b>CPU</b>'));


section('client area and name server settings');
$html = page('settings');
check('settings page: client area switches and name server mode', str_contains($html, 'name="allow_panel_login"') && str_contains($html, 'name="panel_host"') && str_contains($html, 'name="ns_mode"') && str_contains($html, 'name="dashboard_notice"'));
post('settings', ['do' => 'save_settings', 'ns_mode' => 'everything', 'panel_host' => 'evil', 'renew_invoice_days' => '999', 'dashboard_notice' => "<script>x</script>Back soon", 'allow_shell' => '1']);
$get = static fn (string $k) => Capsule::table('mod_lbh_settings')->where('setting', $k)->value('value');
check('validated: bad mode -> off, bad host -> auto, days capped, tags stripped, unchecked boxes off', $get('ns_mode') === 'off' && $get('panel_host') === 'auto' && $get('renew_invoice_days') === '60' && $get('dashboard_notice') === 'xBack soon' && $get('allow_shell') === '1' && $get('allow_panel_login') === '0');
check('settings page: per product type display boxes for Quick actions, plan changes and server power', substr_count($html, 'name="dash_actions[]"') === 8 && substr_count($html, 'name="dash_changes[]"') === 8 && substr_count($html, 'name="dash_power[]"') === 2 && str_contains($html, 'name="allow_power"'));
post('settings', ['do' => 'save_settings', 'dash_actions' => ['vps', 'cloud', 'evil<x>'], 'dash_changes' => ['business'], 'dash_power' => ['dedicated', 'cloud'], 'allow_power' => '1']);
check('Expires display boxes on the settings page', substr_count($html, 'name="dash_expires[]"') === 8);
post('settings', ['do' => 'save_settings', 'dash_expires' => ['cloud', 'x<y>']]);
check('Expires choice saved, junk dropped', $get('dash_expires') === 'cloud');
check('hide-panel switch and sign-in IP on the settings page', str_contains($html, 'name="panel_hide"') && str_contains($html, 'name="panel_sso_ip"'));
post('settings', ['do' => 'save_settings', 'panel_hide' => '1', 'panel_sso_ip' => 'not-an-ip']);
check('sign-in IP validated', $get('panel_hide') === '1' && $get('panel_sso_ip') === '');
check('order page switch and delay on the settings page', str_contains($html, 'name="panel_order_page"') && str_contains($html, 'name="panel_order_delay"'));
post('settings', ['do' => 'save_settings', 'panel_order_delay' => '500']);
check('order page delay capped', $get('panel_order_delay') === '30');
check('power button and Refresh switches on the settings page', substr_count($html, 'name="power_buttons[]"') === 3 && str_contains($html, 'name="allow_refresh"'));
post('settings', ['do' => 'save_settings', 'power_buttons' => ['start', 'evil', 'reboot'], 'allow_refresh' => '1']);
check('power buttons saved in display order, junk dropped', $get('power_buttons') === 'reboot,start' && $get('allow_refresh') === '1');
post('settings', ['do' => 'save_settings', 'dash_actions' => ['vps', 'cloud', 'evil<x>'], 'dash_changes' => ['business'], 'dash_power' => ['dedicated', 'cloud'], 'allow_power' => '1']);
check('display choices saved, junk and non-server types dropped', $get('dash_actions') === 'vps,cloud' && $get('dash_changes') === 'business' && $get('dash_power') === 'dedicated' && $get('allow_power') === '1');
post('settings', ['do' => 'save_settings', 'ns_mode' => 'branded', 'ns_on_create' => '1']);
check('branded mode saved', $get('ns_mode') === 'branded' && $get('ns_on_create') === '1' && $get('ns_client_button') === '0');
Capsule::table('mod_lbh_dns_jobs')->insert(['service_id' => 10, 'mode' => 'branded', 'status' => 'failed', 'source' => 'create', 'nameservers' => '["ns1.<b>.net"]', 'records_added' => 0, 'message' => 'registrar_failed', 'created_at' => $now, 'updated_at' => $now]);
$tab = \LbHosting\WHMCS\ServerModule::adminServicesTabFields('cloud', ['serviceid' => 10]);
check('admin service tab shows the name server job, escaped', str_contains($tab['Name servers'] ?? '', 'registrar_failed') && !str_contains($tab['Name servers'], '<b>'));
check('"Apply Name Servers" button only for products with DNS', isset(\LbHosting\WHMCS\ServerModule::adminButtons('singledomain')['Apply Name Servers']) && !isset(\LbHosting\WHMCS\ServerModule::adminButtons('vps')['Apply Name Servers']));


section('Import Manager and Updates pages');
Capsule::table('tblpaymentgateways')->insert(['gateway' => 'banktransfer', 'setting' => 'name', 'value' => 'Bank Transfer', 'order' => 1]);
$html = page('import');
check('import page: scan form with all eight products and the status boxes', substr_count($html, 'name="families[]"') === 8 && str_contains($html, 'value="Suspended" checked') && str_contains($html, 'Scan LogicBoxes'));
Capsule::table('mod_lbh_import_orders')->insert(['id' => 1, 'account_id' => 1, 'family' => 'vps', 'product_key' => 'virtualserverlinuxus', 'order_id' => 201, 'domain' => '<i>srv</i>.example.net', 'customer_id' => 800, 'customer_email' => 'carol@example.net', 'lb_status' => 'Active', 'plan_id' => 10, 'plan_name' => 'VPS <S>', 'extras_json' => json_encode(['os' => 'rocky_9', 'addons' => ['cpanel' => 1, 'ipaddress' => 2]]), 'expires_at' => '2027-03-01 00:00:00', 'match_client_id' => 1, 'state' => 'new', 'scanned_at' => $now]);
Capsule::table('mod_lbh_import_orders')->insert(['id' => 2, 'account_id' => 1, 'family' => 'cloud', 'product_key' => 'cloudsiteslinuxus', 'order_id' => 202, 'domain' => 'done.example.net', 'lb_status' => 'Active', 'state' => 'imported', 'match_service_id' => 10, 'scanned_at' => $now]);
$html = page('import');
check('import list: order row, what is on the order, escaped', str_contains($html, '&lt;i&gt;srv&lt;/i&gt;.example.net') && !str_contains($html, '<i>srv</i>') && str_contains($html, 'rocky_9, cpanel, ipaddress x2') && str_contains($html, 'VPS &lt;S&gt;') && str_contains($html, 'To do (1)') && str_contains($html, 'Imported (1)') && !str_contains($html, 'done.example.net'));
check('import list: bulk form with product, cycle and gateway', str_contains($html, 'name="ids[]"') && str_contains($html, 'name="billingcycle"') && str_contains($html, 'Bank Transfer') && str_contains($html, 'name="create_client"'));
check('dashboard mentions orders waiting to be imported', str_contains(page('dashboard'), '1 LogicBoxes order(s) found by the last scan'));
$out = post('import', ['do' => 'import_ignore', 'id' => 1], false);
check('import action without a token is rejected', str_contains($out, 'Security token expired') && Capsule::table('mod_lbh_import_orders')->where('id', 1)->value('state') === 'new');
$out = post('import', ['do' => 'import_scan', 'account_id' => 1, 'families' => ['bogus'], 'statuses' => ['Active']]);
check('scan needs a real product', str_contains($out, 'at least one product'));
$out = post('import', ['do' => 'import_bulk', 'ids' => [1], 'billingcycle' => 'Monthly', 'paymentmethod' => 'banktransfer']);
check('bulk import without a product reports the failure on the row', str_contains($out, '1 failed') && Capsule::table('mod_lbh_import_orders')->where('id', 1)->value('state') === 'error' && str_contains(page('import', ['state' => 'error']), 'Product Generator'));
post('import', ['do' => 'import_ignore', 'id' => 1]);
check('ignore, then restore', Capsule::table('mod_lbh_import_orders')->where('id', 1)->value('state') === 'ignored');
post('import', ['do' => 'import_restore', 'id' => 1]);
check('restored to the to-do list', Capsule::table('mod_lbh_import_orders')->where('id', 1)->value('state') === 'new');

$html = page('updates');
check('updates page: version, source form', str_contains($html, LBH_VERSION) && str_contains($html, 'name="update_url"') && str_contains($html, 'name="update_pubkey"') && !str_contains($html, 'value="update_install"'));
$out = post('updates', ['do' => 'update_settings', 'update_url' => 'http://insecure.example.com/m.json', 'update_pubkey' => '']);
check('update source must be https', str_contains($out, 'https://') && $get('update_url') !== 'http://insecure.example.com/m.json');
$out = post('updates', ['do' => 'update_settings', 'update_url' => 'https://u.example.com/m.json', 'update_pubkey' => 'not-a-key']);
check('public key is validated', str_contains($out, 'Public key must be'));
$pk = base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()));
post('updates', ['do' => 'update_settings', 'update_url' => 'https://u.example.com/m.json', 'update_pubkey' => $pk, 'update_check' => '1']);
check('update settings saved; automatic install stays off unless ticked', $get('update_url') === 'https://u.example.com/m.json' && $get('update_pubkey') === $pk && $get('update_auto') === '0');
\LbHosting\Support\Settings::set('update_state', json_encode(['checked_at' => $now, 'current' => LBH_VERSION, 'ok' => true, 'available' => true, 'latest' => '9.9.9', 'php_ok' => true, 'notes' => 'Fixes <script>x</script>']));
$html = page('updates');
check('available update: notes escaped, install button shown', str_contains($html, '9.9.9') && str_contains($html, 'value="update_install"') && !str_contains($html, '<script>x</script>') && str_contains(page('dashboard'), 'Version 9.9.9 is available'));

section('clear price catalog');
Capsule::table('mod_lbh_prices')->insert(['account_id' => 1, 'product_key' => 'virtualserverlinuxus', 'plan_id' => 10, 'kind' => 'sell', 'item' => 'plan', 'action' => 'renew', 'months' => 1, 'price' => 9, 'fetched_at' => date('Y-m-d H:i:s')]);
$out = post('catalog', ['do' => 'clear_catalog', 'account_id' => 1, 'family' => 'singledomain', 'scope' => 'family']);
check('clear one type via POST: Single Domain rows gone, VPS kept', !array_filter(Capsule::table('mod_lbh_prices')->get()->all(), static fn ($r) => str_starts_with($r->product_key, 'singledomain'))
    && Capsule::table('mod_lbh_prices')->where('product_key', 'virtualserverlinuxus')->count() === 1 && str_contains($out, 'Cleared'));
post('catalog', ['do' => 'clear_catalog', 'account_id' => 1, 'family' => 'singledomain', 'scope' => 'all']);
check('clear all via POST', Capsule::table('mod_lbh_prices')->where('account_id', 1)->count() === 0 && Capsule::table('mod_lbh_price_snapshots')->where('account_id', 1)->count() === 0);
$out = post('catalog', ['do' => 'clear_catalog', 'account_id' => 999, 'scope' => 'all']);
check('unknown account refused', str_contains($out, 'Choose an API account'));
$out = post('catalog', ['do' => 'clear_catalog', 'account_id' => 1, 'scope' => 'all'], false);
check('clear needs the CSRF token', !str_contains($out, 'Cleared'));

done();
