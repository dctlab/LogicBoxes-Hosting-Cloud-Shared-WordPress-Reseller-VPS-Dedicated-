<?php

declare(strict_types=1);

namespace LbHosting\Admin;

use Illuminate\Database\Capsule\Manager as Capsule;
use LbHosting\Accounts\Account;
use LbHosting\Accounts\AccountRepository;
use LbHosting\Catalog\ProductFamily;
use LbHosting\WHMCS\ClientAreaController;
use LbHosting\I18n\Lang;
use LbHosting\Service\ManualFulfilment;
use LbHosting\Service\UpgradeService;
use LbHosting\Support\Csrf;
use LbHosting\Support\Logger;
use LbHosting\Support\Settings;
use LbHosting\WHMCS\Database;
use LbHosting\WHMCS\ServerModule;

/**
 * Addons > LogicBoxes Hosting admin pages. Access is governed by the addon's
 * WHMCS "Access Control" role setting. Every POST is checked against the
 * module CSRF token before anything changes; all output is escaped.
 *
 * Pages: Dashboard, API Accounts, Price Catalog, Product Generator, Products &
 * Price Sync, Manual Tasks, Settings, Languages, Logs (Phase 6 adds Import + Updates).
 */
final class AddonController
{
    private string $base;

    private AccountRepository $accounts;

    /** @var array<int,array{0:string,1:string}> */
    private array $messages = [];

    /** @param array<string,mixed> $vars WHMCS addon $vars */
    public function __construct(array $vars)
    {
        $this->base = (string) ($vars['modulelink'] ?? 'addonmodules.php?module=lbhosting');
        $this->accounts = new AccountRepository();
    }

    public function dispatch(): string
    {
        Database::ensure();
        $page = preg_replace('/[^a-z_]/', '', (string) ($_GET['page'] ?? 'dashboard'));

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (!Csrf::verify($_POST['lbh_token'] ?? null, 'admin')) {
                $this->messages[] = ['danger', 'Security token expired. Please try again.'];
            } else {
                $this->handlePost();
            }
        }

        switch ($page) {
            case 'accounts':
                $body = $this->pageAccounts();
                break;
            case 'account':
                $body = $this->pageAccountForm((int) ($_GET['id'] ?? 0));
                break;
            case 'tasks':
                $body = $this->pageTasks();
                break;
            case 'upgrades':
                $body = $this->pageUpgrades();
                break;
            case 'import':
                $body = (new OperationsPages($this))->pageImport();
                break;
            case 'updates':
                $body = (new OperationsPages($this))->pageUpdates();
                break;
            case 'catalog':
                $body = $this->pricing()->pageCatalog();
                break;
            case 'generator':
                $body = $this->pricing()->pageGenerator();
                break;
            case 'products':
                $body = $this->pricing()->pageProducts();
                break;
            case 'map':
                $body = $this->pricing()->pageMapForm((int) ($_GET['id'] ?? 0));
                break;
            case 'settings':
                $body = $this->pageSettings();
                break;
            case 'languages':
                $body = $this->pageLanguages();
                break;
            case 'logs':
                $body = $this->pageLogs();
                break;
            default:
                $page = 'dashboard';
                $body = $this->pageDashboard();
        }

        return $this->nav($page) . $this->flashHtml() . $body;
    }

    // ------------------------------------------------------------------ POST

    private function handlePost(): void
    {
        $do = (string) ($_POST['do'] ?? '');
        try {
            switch ($do) {
                case 'save_account':
                    $id = (int) ($_POST['id'] ?? 0);
                    $data = $this->validateAccount($_POST, $id);
                    $id = $this->accounts->save($id ?: null, $data);
                    Logger::log('admin', 'API account #' . $id . ' saved');
                    $this->messages[] = ['success', 'Account saved.'];
                    $this->testAccount($id);
                    $this->redirect('accounts');
                    break;
                case 'delete_account':
                    $this->accounts->delete((int) $_POST['id']);
                    $this->messages[] = ['success', 'Account deleted.'];
                    break;
                case 'test_account':
                    $this->testAccount((int) $_POST['id']);
                    break;
                case 'task_done':
                case 'task_cancel':
                    (new ManualFulfilment())->complete((int) ($_POST['id'] ?? 0), $do === 'task_cancel' ? 'cancelled' : 'done', trim((string) ($_POST['note'] ?? '')));
                    $this->messages[] = ['success', 'Task updated.'];
                    break;
                case 'upgrade_retry':
                    $svc = new UpgradeService(ServerModule::$transport);
                    $id = (int) ($_POST['id'] ?? 0);
                    $before = (string) Capsule::table('mod_lbh_upgrades')->where('id', $id)->value('status');
                    if (!in_array($before, ['paid', 'failed', 'applying'], true)) {
                        throw new \RuntimeException('Change #' . $id . ' was not applied: only paid or failed changes can be retried.');
                    }
                    $svc->apply($id, true);
                    $u = Capsule::table('mod_lbh_upgrades')->where('id', $id)->first();
                    if ($u && $u->status === 'applied') {
                        $this->messages[] = ['success', 'Change #' . $id . ' applied.'];
                    } else {
                        $this->messages[] = ['danger', 'Change #' . $id . ' was not applied' . ($u && !empty($u->error) ? ': ' . $u->error : ' (it is being applied by another request - try again in 10 minutes).')];
                    }
                    break;
                case 'upgrade_cancel':
                    (new UpgradeService(ServerModule::$transport))->cancel((int) ($_POST['id'] ?? 0));
                    Logger::log('admin', 'Change #' . (int) ($_POST['id'] ?? 0) . ' cancelled by admin');
                    $this->messages[] = ['success', 'Change cancelled. The WHMCS invoice (if any) was left as it is - cancel or refund it yourself.'];
                    break;
                case 'save_settings':
                    $this->saveSettings($_POST);
                    $this->messages[] = ['success', 'Settings saved.'];
                    break;
                default:
                    if (in_array($do, OperationsPages::DOS, true)) {
                        (new OperationsPages($this))->handle($do);
                        break;
                    }
                    if (!in_array($do, PricingPages::DOS, true)) {
                        throw new \InvalidArgumentException('Unknown action.');
                    }
                    $this->pricing()->handle($do);
            }
        } catch (\Throwable $e) {
            $this->messages[] = ['danger', $e->getMessage()];
        }
    }

    private ?PricingPages $pricingPages = null;

    private function pricing(): PricingPages
    {
        return $this->pricingPages ??= new PricingPages($this);
    }

    public function accounts(): AccountRepository
    {
        return $this->accounts;
    }

    public function addMessage(string $type, string $message): void
    {
        $this->messages[] = [$type, $message];
    }

    private function testAccount(int $id): void
    {
        $acc = $this->accounts->find($id);
        if (!$acc) {
            throw new \RuntimeException('Account not found.');
        }
        try {
            $api = $acc->api();
            $details = $api->resellerDetails();
            $branding = (string) ($details['brandingurl'] ?? '');
            $this->accounts->recordTest($id, true, '', $branding);
            $counts = [];
            try {
                $byFamily = [];
                foreach (ProductFamily::all() as $family) {
                    $byFamily[$family] = array_keys(ProductFamily::keys($family));
                }
                $plans = $api->plansForKeys(array_merge(...array_values($byFamily)));
                foreach ($byFamily as $family => $keys) {
                    $n = 0;
                    foreach ($keys as $k) {
                        $n += count($plans[$k] ?? []);
                    }
                    $counts[] = ProductFamily::label($family) . ': ' . $n;
                }
            } catch (\Throwable $e) {
                $counts[] = 'plan list unavailable (' . $e->getMessage() . ')';
            }
            $this->messages[] = ['success', sprintf('Connected to "%s"%s. Plans found - %s.', $acc->name, $branding ? ' (branding URL ' . $branding . ')' : '', implode(', ', $counts))];
        } catch (\Throwable $e) {
            $this->accounts->recordTest($id, false, $e->getMessage());
            $this->messages[] = ['danger', 'Connection test failed for "' . $acc->name . '": ' . $e->getMessage() . ' - also check that this server\'s IP is whitelisted in the reseller panel (Settings > API).'];
        }
    }

    /**
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     */
    private function validateAccount(array $in, int $id): array
    {
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 100) {
            throw new \InvalidArgumentException('Name is required (max. 100 characters).');
        }
        $brand = (string) ($in['brand'] ?? '');
        if (!isset(Account::BRANDS[$brand])) {
            throw new \InvalidArgumentException('Invalid brand.');
        }
        $reseller = trim((string) ($in['reseller_id'] ?? ''));
        if (!ctype_digit($reseller)) {
            throw new \InvalidArgumentException('Reseller ID must be numeric.');
        }
        $apiKey = trim((string) ($in['api_key'] ?? ''));
        if ($id === 0 && $apiKey === '') {
            throw new \InvalidArgumentException('API key is required.');
        }
        if ($apiKey !== '' && !preg_match('/^[A-Za-z0-9]{8,128}$/', $apiKey)) {
            throw new \InvalidArgumentException('The API key format looks wrong (letters and digits only).');
        }
        $apiUrl = trim((string) ($in['api_url'] ?? ''));
        if ($apiUrl !== '' && !preg_match('#^https://[a-z0-9.-]+(:\d+)?/?[a-z0-9/_-]*$#i', $apiUrl)) {
            throw new \InvalidArgumentException('Custom API host must be an https:// URL, e.g. https://httpapi.com/ (leave blank for the standard LogicBoxes endpoint).');
        }
        $branding = strtolower(trim((string) preg_replace('#^https?://#i', '', trim((string) ($in['branding_url'] ?? ''))), '/'));
        if ($branding !== '' && !preg_match('/^[a-z0-9.-]+(:\d+)?$/', $branding)) {
            throw new \InvalidArgumentException('Branding URL must be a host name, e.g. manage.example.com.');
        }
        $ns = [];
        foreach (preg_split('/[\s,;]+/', strtolower(trim((string) ($in['nameservers'] ?? '')))) ?: [] as $n) {
            $n = rtrim($n, '.');
            if ($n === '') {
                continue;
            }
            if (!preg_match('/^([a-z0-9-]+\.)+[a-z]{2,}$/', $n)) {
                throw new \InvalidArgumentException('Name server "' . $n . '" is not a valid host name.');
            }
            $ns[] = $n;
        }
        if (count($ns) > 13) {
            throw new \InvalidArgumentException('At most 13 name servers.');
        }

        return [
            'name' => $name,
            'brand' => $brand,
            'reseller_id' => $reseller,
            'api_key' => $apiKey,
            'test_mode' => !empty($in['test_mode']),
            'api_url' => $apiUrl,
            'branding_url' => $branding,
            'nameservers' => implode("\n", array_unique($ns)),
            'is_default' => !empty($in['is_default']),
            'status' => ($in['status'] ?? 'active') === 'disabled' ? 'disabled' : 'active',
        ];
    }

    /** @param array<string,mixed> $in */
    private function saveSettings(array $in): void
    {
        $terminate = (string) ($in['terminate_mode'] ?? 'suspend');
        $lang = strtolower((string) ($in['default_language'] ?? 'english'));
        $dept = trim((string) ($in['manual_ticket_dept'] ?? ''));
        $values = [
            'terminate_mode' => in_array($terminate, ['suspend', 'delete', 'local'], true) ? $terminate : 'suspend',
            'renew_dedupe' => !empty($in['renew_dedupe']) ? '1' : '0',
            'provider_auto_renew' => !empty($in['provider_auto_renew']) ? '1' : '0',
            'upgrade_months' => ($in['upgrade_months'] ?? 'remaining') === 'tenure' ? 'tenure' : 'remaining',
            'addon_removal' => !empty($in['addon_removal']) ? '1' : '0',
            'manual_todo' => !empty($in['manual_todo']) ? '1' : '0',
            'manual_ticket' => !empty($in['manual_ticket']) ? '1' : '0',
            'manual_ticket_dept' => ctype_digit($dept) ? $dept : '',
            'allow_client_renew' => !empty($in['allow_client_renew']) ? '1' : '0',
            'dash_expires' => implode(',', array_values(array_intersect((array) ($in['dash_expires'] ?? []), ProductFamily::all()))),
            'show_credentials' => !empty($in['show_credentials']) ? '1' : '0',
            'default_language' => in_array($lang, Lang::available(), true) ? $lang : 'english',
            'price_basis' => in_array($in['price_basis'] ?? '', ['auto', 'per_month', 'per_term'], true) ? (string) $in['price_basis'] : 'auto',
            'price_action' => ($in['price_action'] ?? 'renew') === 'add' ? 'add' : 'renew',
            'extras_basis' => ($in['extras_basis'] ?? 'per_month') === 'per_year' ? 'per_year' : 'per_month',
            'price_sync_enabled' => !empty($in['price_sync_enabled']) ? '1' : '0',
            'price_sync_refresh' => !empty($in['price_sync_refresh']) ? '1' : '0',
            'price_sync_interval' => in_array($iv = (string) ($in['price_sync_interval'] ?? '6'), ['1', '3', '6', '12', '24'], true) ? $iv : '6',
            'price_sync_options' => !empty($in['price_sync_options']) ? '1' : '0',
            'allow_panel_login' => !empty($in['allow_panel_login']) ? '1' : '0',
            'allow_order_panel' => !empty($in['allow_order_panel']) ? '1' : '0',
            'allow_password_change' => !empty($in['allow_password_change']) ? '1' : '0',
            'allow_shell' => !empty($in['allow_shell']) ? '1' : '0',
            'show_dns' => !empty($in['show_dns']) ? '1' : '0',
            'panel_host' => in_array($in['panel_host'] ?? '', ['auto', 'ip', 'domain'], true) ? (string) $in['panel_host'] : 'auto',
            'renew_invoice_days' => (string) max(0, min(60, (int) ($in['renew_invoice_days'] ?? 7))),
            'allow_power' => !empty($in['allow_power']) ? '1' : '0',
            'allow_refresh' => !empty($in['allow_refresh']) ? '1' : '0',
            'panel_order_page' => !empty($in['panel_order_page']) ? '1' : '0',
            'panel_hide' => !empty($in['panel_hide']) ? '1' : '0',
            'panel_sso_ip' => filter_var(trim((string) ($in['panel_sso_ip'] ?? '')), FILTER_VALIDATE_IP) ? trim((string) $in['panel_sso_ip']) : '',
            'panel_order_delay' => (string) max(2, min(30, (int) ($in['panel_order_delay'] ?? 6))),
            'power_buttons' => implode(',', array_values(array_intersect(ClientAreaController::POWER, (array) ($in['power_buttons'] ?? [])))),
            'dash_actions' => implode(',', array_values(array_intersect((array) ($in['dash_actions'] ?? []), ProductFamily::all()))),
            'dash_changes' => implode(',', array_values(array_intersect((array) ($in['dash_changes'] ?? []), ProductFamily::all()))),
            'dash_power' => implode(',', array_values(array_intersect((array) ($in['dash_power'] ?? []), [ProductFamily::VPS, ProductFamily::DEDICATED]))),
            'dashboard_notice' => mb_substr(trim(strip_tags((string) ($in['dashboard_notice'] ?? ''))), 0, 500),
            'ns_mode' => in_array($in['ns_mode'] ?? '', ['off', 'branded', 'server'], true) ? (string) $in['ns_mode'] : 'off',
            'ns_on_create' => !empty($in['ns_on_create']) ? '1' : '0',
            'ns_client_button' => !empty($in['ns_client_button']) ? '1' : '0',
            'extra_product_keys' => implode("\n", array_values(array_unique(array_filter(preg_split('/[\s,;]+/', strtolower((string) ($in['extra_product_keys'] ?? ''))) ?: [], static fn ($k) => ProductFamily::classify($k) !== null || ProductFamily::forKey($k) !== null)))),
            'upgrade_mode' => ($in['upgrade_mode'] ?? 'provider') === 'whmcs' ? 'whmcs' : 'provider',
            'block_storage_plan_id' => ctype_digit($plan = trim((string) ($in['block_storage_plan_id'] ?? ''))) ? $plan : '',
        ];
        foreach ($values as $k => $v) {
            Settings::set($k, $v);
        }
        ProductFamily::resetDiscovered();
        $removed = UpgradeService::enforceMode();
        if ($removed > 0) {
            $this->messages[] = ['info', $removed . ' WHMCS upgrade path(s) between LogicBoxes products were removed: plan changes now go through the provider-priced flow.'];
        }
        Logger::log('admin', 'Settings saved');
    }

    // ----------------------------------------------------------------- pages

    private function pageDashboard(): string
    {
        $accounts = count($this->accounts->listRows());
        $errors = Capsule::table('mod_lbh_logs')->where('level', 'error')->where('created_at', '>=', date('Y-m-d H:i:s', time() - 86400 * 7))->count();
        $tasks = Capsule::table('mod_lbh_manual_tasks')->where('status', 'open')->count();
        $tiles = [['API accounts', $accounts], ['Open manual tasks', $tasks], ['Errors (7 days)', $errors]];
        foreach (ProductFamily::all() as $family) {
            $n = Capsule::table('tblhosting')
                ->join('tblproducts', 'tblproducts.id', '=', 'tblhosting.packageid')
                ->where('tblproducts.servertype', ProductFamily::module($family))
                ->where('tblhosting.domainstatus', 'Active')
                ->count();
            $tiles[] = [ProductFamily::label($family), $n];
        }
        $h = '<div class="row">';
        foreach ($tiles as [$label, $n]) {
            $h .= '<div class="col-sm-4 col-md-2"><div class="panel panel-default card"><div class="panel-body card-body text-center"><div style="font-size:26px;font-weight:600">' . (int) $n . '</div><div class="text-muted small">' . $this->e($label) . '</div></div></div></div>';
        }
        $h .= '</div>';
        if ($accounts === 0) {
            $h .= '<div class="alert alert-info">Start by adding your LogicBoxes reseller account under <a href="' . $this->e($this->url('account')) . '">API Accounts</a>. Then create a product with the <a href="' . $this->e($this->url('catalog')) . '">Price Catalog</a> and <a href="' . $this->e($this->url('generator')) . '">Product Generator</a>, or by hand with one of the eight "(LogicBoxes / ResellerClub)" modules.</div>';
        }
        $upd = \LbHosting\Update\Updater::state();
        if (!empty($upd['available'])) {
            $h .= '<div class="alert alert-success">Version ' . $this->e($upd['latest'] ?? '') . ' is available (signature verified). <a href="' . $this->e($this->url('updates')) . '">Review and install</a>.</div>';
        }
        $toImport = Capsule::table('mod_lbh_import_orders')->where('state', 'new')->count();
        if ($toImport > 0) {
            $h .= '<div class="alert alert-info">' . (int) $toImport . ' LogicBoxes order(s) found by the last scan are not in WHMCS yet. <a href="' . $this->e($this->url('import')) . '">Open Import Manager</a>.</div>';
        }
        $stuck = Capsule::table('mod_lbh_upgrades')->whereIn('status', ['failed', 'paid', 'applying'])->count();
        if ($stuck > 0) {
            $h .= '<div class="alert alert-danger">' . (int) $stuck . ' PAID change(s) have not been applied at LogicBoxes. <a href="' . $this->e($this->url('upgrades')) . '">Open Upgrades</a> to retry.</div>';
        }
        if ($tasks > 0) {
            $h .= '<div class="alert alert-warning">' . (int) $tasks . ' change(s) need doing by hand (items the LogicBoxes API cannot provision). <a href="' . $this->e($this->url('tasks')) . '">Open Manual Tasks</a>.</div>';
        }

        $h .= '<h3>What each product supports</h3><table class="table table-condensed"><thead><tr><th>Capability</th>';
        foreach (ProductFamily::all() as $family) {
            $h .= '<th>' . $this->e(ProductFamily::label($family)) . '</th>';
        }
        $h .= '</tr></thead><tbody>';
        $rows = [
            'Create / suspend / unsuspend / terminate / renew' => static fn ($f) => true,
            'Plan change' => static fn ($f) => ProductFamily::can($f, 'plan_change') ? (ProductFamily::can($f, 'downgrade') ? 'up + down' : 'upgrade only') : false,
            'Add-ons through the API' => static fn ($f) => ProductFamily::can($f, 'addon_api') || ProductFamily::can($f, 'ssl') || ProductFamily::can($f, 'dedicated_ips'),
            'Manual items (To-Do + ticket)' => static fn ($f) => ProductFamily::manualItems($f) ? implode(', ', ProductFamily::manualItems($f)) : false,
            'Panel password change' => static fn ($f) => ProductFamily::can($f, 'panel_password'),
            'Locations' => static fn ($f) => (string) count(ProductFamily::keys($f)),
        ];
        foreach ($rows as $label => $fn) {
            $h .= '<tr><td>' . $this->e($label) . '</td>';
            foreach (ProductFamily::all() as $family) {
                $v = $fn($family);
                $h .= '<td>' . ($v === true ? '&#10003;' : ($v === false ? '<span class="text-muted">-</span>' : $this->e($v))) . '</td>';
            }
            $h .= '</tr>';
        }

        return $h . '</tbody></table>';
    }

    private function pageAccounts(): string
    {
        $rows = $this->accounts->listRows();
        $h = '<p><a class="btn btn-primary" href="' . $this->e($this->url('account')) . '">Add API account</a></p>';
        if (!$rows) {
            return $h . '<p class="text-muted">No accounts yet.</p>';
        }
        $h .= '<table class="table table-striped"><thead><tr><th>Name</th><th>Brand</th><th>Reseller ID</th><th>Mode</th><th>Branding URL</th><th>Last test</th><th></th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $test = $r->last_test_at === null ? '<span class="text-muted">never</span>'
                : ($r->last_test_ok ? '<span class="label label-success badge bg-success">OK</span> ' : '<span class="label label-danger badge bg-danger" title="' . $this->e($r->last_test_error) . '">Failed</span> ') . $this->e($r->last_test_at);
            $h .= '<tr><td><strong>' . $this->e($r->name) . '</strong>' . ($r->is_default ? ' <span class="label label-info badge bg-info">default</span>' : '') . ($r->status !== 'active' ? ' <span class="label label-default badge bg-secondary">disabled</span>' : '') . '</td>'
                . '<td>' . $this->e(Account::BRANDS[$r->brand] ?? $r->brand) . '</td>'
                . '<td>' . $this->e($r->reseller_id) . '</td>'
                . '<td>' . ($r->test_mode ? 'Test' : 'Live') . '</td>'
                . '<td>' . $this->e($r->branding_url) . '</td>'
                . '<td>' . $test . '</td>'
                . '<td class="text-right" style="white-space:nowrap">'
                . '<a class="btn btn-default btn-sm" href="' . $this->e($this->url('account', ['id' => $r->id])) . '">Edit</a> '
                . $this->postButton('accounts', 'test_account', (int) $r->id, 'Test', 'btn-info') . ' '
                . $this->postButton('accounts', 'delete_account', (int) $r->id, 'Delete', 'btn-danger', 'Delete this account?')
                . '</td></tr>';
        }

        return $h . '</tbody></table>';
    }

    private function pageAccountForm(int $id): string
    {
        $r = null;
        foreach ($this->accounts->listRows() as $row) {
            if ((int) $row->id === $id) {
                $r = $row;
            }
        }
        $v = static fn (string $k, $d = '') => $r ? ($r->$k ?? $d) : $d;
        $opt = function (array $opts, string $sel): string {
            $o = '';
            foreach ($opts as $k => $label) {
                $o .= '<option value="' . $this->e($k) . '"' . ((string) $k === $sel ? ' selected' : '') . '>' . $this->e($label) . '</option>';
            }

            return $o;
        };

        $h = '<form method="post" action="' . $this->e($this->url('account', $id ? ['id' => $id] : [])) . '" class="form-horizontal" style="max-width:820px">'
            . $this->tokenField() . '<input type="hidden" name="do" value="save_account"><input type="hidden" name="id" value="' . $id . '">';
        $h .= $this->row('Name', '<input name="name" class="form-control" required maxlength="100" value="' . $this->e($v('name')) . '">', 'Your label, e.g. "ResellerClub India".');
        $h .= $this->row('Brand', '<select name="brand" class="form-control">' . $opt(Account::BRANDS, (string) $v('brand', 'resellerclub')) . '</select>');
        $h .= $this->row('Reseller ID', '<input name="reseller_id" class="form-control" required inputmode="numeric" value="' . $this->e($v('reseller_id')) . '">', 'Settings > Personal Information > Primary Profile in the reseller panel.');
        $h .= $this->row('API key', '<input type="password" name="api_key" class="form-control" autocomplete="new-password"' . ($id ? ' placeholder="(unchanged - leave blank to keep)"' : ' required') . '>', 'Settings > API in the reseller panel. Also whitelist this server\'s IP there. Stored encrypted.');
        $h .= $this->row('Test mode', '<label><input type="checkbox" name="test_mode" value="1"' . ($v('test_mode') ? ' checked' : '') . '> Use test.httpapi.com (demo reseller account)</label>');
        $h .= $this->row('Custom API host', '<input name="api_url" class="form-control" placeholder="https://httpapi.com/" value="' . $this->e($v('api_url')) . '">', 'Leave blank for httpapi.com (or test.httpapi.com in test mode).');
        $h .= $this->row('Branding URL', '<input name="branding_url" class="form-control" placeholder="manage.yourbrand.com" value="' . $this->e($v('branding_url')) . '">', 'Customer control panel host, used for auto login. Filled automatically on the first successful connection test.');
        $h .= $this->row('Branded name servers', '<textarea name="nameservers" class="form-control" rows="3" placeholder="ns1.yourbrand.com&#10;ns2.yourbrand.com">' . $this->e($v('nameservers')) . '</textarea>', 'Your reseller (child) name servers, one per line. Used by the name server automation in Phase 5.');
        $h .= $this->row('Default account', '<label><input type="checkbox" name="is_default" value="1"' . ($v('is_default') ? ' checked' : '') . '> Use for products that do not select an account</label>');
        $h .= $this->row('Status', '<select name="status" class="form-control">' . $opt(['active' => 'Active', 'disabled' => 'Disabled'], (string) $v('status', 'active')) . '</select>');
        $h .= '<div class="form-group"><div class="col-sm-offset-3 col-sm-9"><button class="btn btn-primary" type="submit">Save and test connection</button> <a class="btn btn-default" href="' . $this->e($this->url('accounts')) . '">Cancel</a></div></div></form>';

        return $h;
    }

    private function pageTasks(): string
    {
        $show = ($_GET['show'] ?? 'open') === 'all' ? 'all' : 'open';
        $q = Capsule::table('mod_lbh_manual_tasks')->orderBy('id', 'desc')->limit(250);
        if ($show === 'open') {
            $q->where('status', 'open');
        }
        $rows = $q->get()->all();
        $h = '<p>Changes the LogicBoxes API cannot make (Cloud Hosting dedicated IP, Dedicated Server bandwidth). Do them in the reseller panel or with LogicBoxes support, then mark them done here - that also completes the WHMCS To-Do item. Reply to the client in the linked ticket.</p>'
            . '<p><a href="' . $this->e($this->url('tasks')) . '"' . ($show === 'open' ? ' class="btn btn-sm btn-primary"' : ' class="btn btn-sm btn-default"') . '>Open</a> '
            . '<a href="' . $this->e($this->url('tasks', ['show' => 'all'])) . '"' . ($show === 'all' ? ' class="btn btn-sm btn-primary"' : ' class="btn btn-sm btn-default"') . '>All</a></p>';
        if (!$rows) {
            return $h . '<p class="text-muted">Nothing to do.</p>';
        }
        $h .= '<table class="table table-striped"><thead><tr><th>#</th><th>Created</th><th>Service</th><th>Item</th><th>Change</th><th>Ticket</th><th>Status</th><th></th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $actions = '';
            if ($r->status === 'open') {
                $actions = $this->postButton('tasks', 'task_done', (int) $r->id, 'Mark done', 'btn-success') . ' '
                    . $this->postButton('tasks', 'task_cancel', (int) $r->id, 'Cancel', 'btn-default', 'Cancel this task?');
            }
            $h .= '<tr><td>' . (int) $r->id . '</td><td style="white-space:nowrap">' . $this->e($r->created_at) . '</td>'
                . '<td><a href="clientsservices.php?id=' . (int) $r->service_id . '">#' . (int) $r->service_id . '</a></td>'
                . '<td>' . $this->e(ManualFulfilment::ITEMS[$r->item] ?? $r->item) . '</td>'
                . '<td>' . $this->e(($r->previous === null || $r->previous === '' ? 'none' : $r->previous) . ' -> ' . ($r->target === '' ? 'none' : $r->target)) . '</td>'
                . '<td>' . ($r->ticket_id ? '<a href="supporttickets.php?action=view&id=' . (int) $r->ticket_id . '">#' . (int) $r->ticket_id . '</a>' : '-') . '</td>'
                . '<td>' . $this->e($r->status) . (!empty($r->note) ? ' <small class="text-muted">' . $this->e($r->note) . '</small>' : '') . '</td>'
                . '<td class="text-right" style="white-space:nowrap">' . $actions . '</td></tr>';
        }

        return $h . '</tbody></table>';
    }

    private function pageUpgrades(): string
    {
        $filters = ['attention' => 'Needs attention', 'invoiced' => 'Awaiting payment', 'applied' => 'Applied', 'cancelled' => 'Cancelled', 'all' => 'All'];
        $show = (string) ($_GET['show'] ?? 'attention');
        $show = isset($filters[$show]) ? $show : 'attention';
        $q = Capsule::table('mod_lbh_upgrades')->orderBy('id', 'desc')->limit(250);
        if ($show === 'attention') {
            $q->whereIn('status', ['failed', 'paid', 'applying']);
        } elseif ($show !== 'all') {
            $q->where('status', $show);
        }
        $rows = $q->get()->all();
        $h = '<p>Plan and add-on changes clients ordered from their service page. A change is applied at LogicBoxes when its invoice is paid. '
            . '<strong>Failed</strong> means the client has paid but LogicBoxes refused the change (often: not enough reseller funds) - fix the cause and press Retry. A change is never applied twice.</p>';
        if (!UpgradeService::enabled()) {
            $h .= '<div class="alert alert-info">Provider-priced changes are switched off (Settings &gt; Upgrades / downgrades). Clients use the WHMCS upgrade pages.</div>';
        }
        $h .= '<p>';
        foreach ($filters as $k => $label) {
            $h .= '<a href="' . $this->e($this->url('upgrades', ['show' => $k])) . '" class="btn btn-sm ' . ($show === $k ? 'btn-primary' : 'btn-default') . '">' . $this->e($label) . '</a> ';
        }
        $h .= '</p>';
        if (!$rows) {
            return $h . '<p class="text-muted">Nothing here.</p>';
        }
        $cls = ['failed' => 'danger', 'paid' => 'warning', 'applying' => 'warning', 'invoiced' => 'info', 'applied' => 'success', 'cancelled' => 'default'];
        $codes = [];
        foreach (Capsule::table('tblcurrencies')->get() as $c) {
            $codes[(int) $c->id] = (string) $c->code;
        }
        $h .= '<table class="table table-striped"><thead><tr><th>#</th><th>Updated</th><th>Service</th><th>Change</th><th>Months</th><th>Provider cost</th><th>Invoiced</th><th>Invoice</th><th>Status</th><th></th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $actions = '';
            $stale = $r->status === 'applying' && (string) $r->updated_at < date('Y-m-d H:i:s', time() - UpgradeService::STALE_SECONDS);
            if (in_array($r->status, ['failed', 'paid'], true) || $stale) {
                $actions .= $this->postButton('upgrades', 'upgrade_retry', (int) $r->id, 'Retry', 'btn-success', 'Apply this change at LogicBoxes now?') . ' ';
            }
            if (in_array($r->status, ['failed', 'invoiced'], true)) {
                $actions .= $this->postButton('upgrades', 'upgrade_cancel', (int) $r->id, 'Cancel', 'btn-default', 'Cancel this change? The invoice is not cancelled or refunded automatically.');
            }
            $h .= '<tr><td>' . (int) $r->id . '</td><td style="white-space:nowrap">' . $this->e($r->updated_at) . '</td>'
                . '<td><a href="clientsservices.php?id=' . (int) $r->service_id . '">#' . (int) $r->service_id . '</a></td>'
                . '<td>' . $this->e($r->summary) . '</td><td>' . (int) $r->months . '</td>'
                . '<td>' . $this->e(number_format((float) $r->provider_cost, 2)) . '</td>'
                . '<td>' . $this->e(number_format((float) $r->amount, 2) . ' ' . ($codes[(int) $r->currency_id] ?? '')) . '</td>'
                . '<td>' . (!empty($r->invoice_id) ? '<a href="invoices.php?action=edit&id=' . (int) $r->invoice_id . '">#' . (int) $r->invoice_id . '</a>' : '-') . '</td>'
                . '<td><span class="label label-' . $cls[$r->status] . ' badge">' . $this->e($r->status) . '</span>' . (!empty($r->error) ? '<br><small class="text-danger">' . $this->e($r->error) . '</small>' : '') . '</td>'
                . '<td class="text-right" style="white-space:nowrap">' . $actions . '</td></tr>';
        }

        return $h . '</tbody></table>';
    }

    private function pageSettings(): string
    {
        $s = Settings::all();
        $chk = static fn (string $k) => in_array($s[$k] ?? '0', ['1', 'on'], true) ? ' checked' : '';
        $sel = function (string $name, array $opts) use ($s): string {
            $o = '<select name="' . $name . '" class="form-control">';
            foreach ($opts as $k => $label) {
                $o .= '<option value="' . $this->e($k) . '"' . ((string) ($s[$name] ?? '') === (string) $k ? ' selected' : '') . '>' . $this->e($label) . '</option>';
            }

            return $o . '</select>';
        };
        $in = fn (string $name, string $ph = '') => '<input name="' . $name . '" class="form-control" value="' . $this->e($s[$name] ?? '') . '" placeholder="' . $this->e($ph) . '">';

        $h = '<form method="post" class="form-horizontal" style="max-width:900px">' . $this->tokenField() . '<input type="hidden" name="do" value="save_settings">';
        $h .= '<h3>Lifecycle</h3>';
        $h .= $this->row('On Terminate', $sel('terminate_mode', ['suspend' => 'Suspend the order at LogicBoxes (recommended - recoverable)', 'delete' => 'Delete the order at LogicBoxes (permanent: websites / server data are lost)', 'local' => 'Do nothing at LogicBoxes']), 'WHMCS may terminate automatically for overdue invoices. Suspend keeps the data until the order expires.');
        $h .= $this->row('Renewal de-duplication', '<label><input type="checkbox" name="renew_dedupe" value="1"' . $chk('renew_dedupe') . '> Never renew the same service twice within 20 hours / one billing period</label>');
        $h .= $this->row('Auto-renew at LogicBoxes', '<label><input type="checkbox" name="provider_auto_renew" value="1"' . $chk('provider_auto_renew') . '> Send auto-renew=true with orders and renewals</label>', 'Leave off: WHMCS renews the order when the client pays. With it on, LogicBoxes may also renew from your reseller balance.');
        $h .= $this->row('Plan change term', $sel('upgrade_months', ['remaining' => 'Months left until expiry (keeps the expiry date)', 'tenure' => 'The service\'s full billing term']), 'Sent as "months" with modify.json.');
        $h .= $this->row('Add-on removal', '<label><input type="checkbox" name="addon_removal" value="1"' . $chk('addon_removal') . '> Remove add-ons at LogicBoxes when a configurable option is lowered</label>', 'Only add-ons LogicBoxes marks as paid are removed, newest first. Removing an add-on gives no refund.');

        $found = [];
        foreach (ProductFamily::discovered() as $fam => $keys) {
            foreach (array_keys($keys) as $k) {
                $found[] = ProductFamily::label($fam) . ' ' . ProductFamily::keyLabel($fam, (string) $k) . ' (' . $k . ')';
            }
        }
        $h .= '<h3>Locations</h3>';
        $h .= $this->row('Extra product keys', '<textarea name="extra_product_keys" class="form-control" rows="2" placeholder="businesshostinglinuxus wordpresshostingindia">' . $this->e($s['extra_product_keys'] ?? '') . '</textarea>',
            'LogicBoxes documents Business Hosting for India and WordPress Hosting for the US only. Other locations your account sells are added automatically when prices are fetched (Price Catalog) or an API account is tested. Add a key here only if one is missing; keys of the form <code>businesshosting{linux|windows}{region}</code>, <code>wordpresshosting{region}</code> and <code>cloudsites{os}{region}</code> are accepted.'
            . ($found ? '<br><strong>Active now:</strong> ' . $this->e(implode(', ', $found)) : ''));

        $h .= '<h3>Upgrades / downgrades</h3>';
        $h .= $this->row('Pricing of changes', $sel('upgrade_mode', ['provider' => 'Provider-priced (recommended): LogicBoxes cost for the months left, invoiced, applied when paid', 'whmcs' => 'WHMCS standard upgrade pages (WHMCS prorata from your own prices)']), 'Provider-priced removes WHMCS upgrade paths between mapped products and switches off their configurable-option upgrades, so clients cannot use both.');
        $h .= $this->row('Block Storage plan ID', $in('block_storage_plan_id', 'auto-detect'), 'VPS Block Storage (SAN) needs LogicBoxes\' block-storage plan id. Leave empty to read it from the plan list.');

        $h .= '<h3>Manual fulfilment</h3>';
        $h .= $this->row('To-Do item', '<label><input type="checkbox" name="manual_todo" value="1"' . $chk('manual_todo') . '> Create a WHMCS To-Do item for every manual change</label>');
        $h .= $this->row('Support ticket', '<label><input type="checkbox" name="manual_ticket" value="1"' . $chk('manual_ticket') . '> Open a ticket for the client so they know a person is doing it</label>');
        $h .= $this->row('Ticket department ID', $in('manual_ticket_dept', 'first department'));

        $h .= '<h3>Pricing</h3>';
        $h .= $this->row('Plan price basis', $sel('price_basis', ['auto' => 'Auto-detect (compares the 1- and 12-month prices)', 'per_month' => 'Price per MONTH (ResellerClub hosting default)', 'per_term' => 'Price for the WHOLE tenure']), 'What a LogicBoxes plan price number means. Check the Price Catalog "per month" column against your reseller panel.');
        $h .= $this->row('Price at', $sel('price_action', ['renew' => 'Renewal price (recommended)', 'add' => 'New-order ("add") price - often a first-term promo']), 'Which LogicBoxes plan price (cost or customer) WHMCS prices are based on. WHMCS charges the same recurring amount every period.');
        $h .= $this->row('Dedicated IP / add-on price basis', $sel('extras_basis', ['per_month' => 'Per month', 'per_year' => 'Per year']), 'How to read the "ssl" and add-on prices in the LogicBoxes price list. The KB does not say; compare one with your reseller panel.');
        $h .= $this->row('Automatic price sync', '<label><input type="checkbox" name="price_sync_enabled" value="1"' . $chk('price_sync_enabled') . '> When LogicBoxes changes a price, update product and configurable-option prices (every currency and billing cycle) automatically</label>', 'Runs from the WHMCS cron. Per product it can be switched off, and updating existing services\' recurring amounts is a separate per-product choice.');
        $h .= $this->row('Price check interval', $sel('price_sync_interval', ['1' => 'Every hour', '3' => 'Every 3 hours', '6' => 'Every 6 hours', '12' => 'Every 12 hours', '24' => 'Once a day']), 'How often the LogicBoxes price lists are fetched. A change is applied at the check that finds it.' . (Settings::get('price_sync_last') !== '' ? ' Last check: ' . $this->e(Settings::get('price_sync_last')) . '.' : ''));
        $h .= $this->row('Fetch prices', '<label><input type="checkbox" name="price_sync_refresh" value="1"' . $chk('price_sync_refresh') . '> Fetch fresh LogicBoxes prices at each check (off = only re-apply the stored prices)</label>');
        $h .= $this->row('New options', '<label><input type="checkbox" name="price_sync_options" value="1"' . $chk('price_sync_options') . '> Create options LogicBoxes starts pricing on existing products (never duplicates)</label>');

        $h .= '<h3>Client area</h3>';
        $h .= $this->row('Client "Renew now"', '<label><input type="checkbox" name="allow_client_renew" value="1"' . $chk('allow_client_renew') . '> Let clients raise their next renewal invoice early</label>', 'Paying it renews the service in WHMCS and at LogicBoxes like a normal renewal.');
        $h .= $this->row('Renew-now invoice due in at least (days)', $in('renew_invoice_days', '7'), 'The invoice is due on the service\'s next due date, or this many days from today if that is sooner.');
        $h .= $this->row('Panel login', '<label><input type="checkbox" name="allow_panel_login" value="1"' . $chk('allow_panel_login') . '> One-click login to cPanel / WHM / Plesk (hosting products)</label>', 'The panel password is read live from LogicBoxes for that one login and is never stored or logged.');
        $h .= $this->row('Panel host', $sel('panel_host', ['auto' => 'Auto: the server host name if LogicBoxes reports one, else the IP address', 'ip' => 'Always the IP address (browser shows a certificate warning)', 'domain' => 'The client\'s own domain (only if every domain already points at your servers)']), 'With "own domain" the login form is posted to the client\'s domain: if it still points at a previous host, that host receives the panel password. Auto is the safe choice.');
        $h .= $this->row('Order panel login', '<label><input type="checkbox" name="allow_order_panel" value="1"' . $chk('allow_order_panel') . '> One-click login to your branded LogicBoxes control panel</label>', 'Used for Sitebuilder, the VPS / Dedicated server panel, webmail and anything else LogicBoxes offers for the order. Needs the Branding URL on the API account.');
        $h .= $this->row('Password change', '<label><input type="checkbox" name="allow_password_change" value="1"' . $chk('allow_password_change') . '> Let clients set a new control panel password</label>');
        $sections = function (string $name, string $label, string $help, ?array $only = null) use ($s): string {
            $on = array_filter(array_map('trim', explode(',', (string) ($s[$name] ?? ''))));
            $boxes = '';
            foreach ($only ?? ProductFamily::all() as $f) {
                $boxes .= '<label style="margin-right:12px;font-weight:normal"><input type="checkbox" name="' . $name . '[]" value="' . $this->e($f) . '"' . (in_array($f, $on, true) ? ' checked' : '') . '> ' . $this->e(ProductFamily::label($f)) . '</label>';
            }

            return $this->row($label, $boxes, $help);
        };
        $h .= $sections('dash_actions', 'Show "Quick actions"', 'Product types whose client page shows the Quick actions box (panel login, Manage in control panel, Renew now, Refresh). Each button also has its own switch.');
        $h .= $sections('dash_changes', 'Show "Change your plan or add-ons"', 'Product types whose client page shows the plan / add-on change form. When it is off for a type, clients of that type cannot order changes from the page.');
        $h .= $sections('dash_power', 'Show "Server Management Panel"', 'VPS and Dedicated servers: the Server Management Panel box with its description and login details (IP, username). Its buttons follow "Order panel login" (Log in to Server Management Panel) and "Server power buttons" (Reboot / Shut down / Start).', [ProductFamily::VPS, ProductFamily::DEDICATED]);
        $h .= $this->row('Server power buttons', '<label><input type="checkbox" name="allow_power" value="1"' . $chk('allow_power') . '> Offer Reboot / Shut down / Start on VPS and Dedicated servers</label>', 'The LogicBoxes API has no power call for servers, so each button logs the client into the LogicBoxes server panel (same as "Manage in control panel"), where they confirm the action. Needs "Order panel login" on.');
        $pb = array_map('trim', explode(',', (string) ($s['power_buttons'] ?? '')));
        $boxes = '';
        foreach (['reboot' => 'Reboot', 'shutdown' => 'Shut down', 'start' => 'Start'] as $k => $l) {
            $boxes .= '<label style="margin-right:12px;font-weight:normal"><input type="checkbox" name="power_buttons[]" value="' . $k . '"' . (in_array($k, $pb, true) ? ' checked' : '') . '> ' . $l . '</label>';
        }
        $h .= $this->row('Power buttons shown', $boxes, 'Untick a button to hide it. The Server Management Panel box itself is shown or hidden with the switch above.');
        $h .= $this->row('Open the order\'s own page', '<label><input type="checkbox" name="panel_order_page" value="1"' . $chk('panel_order_page') . '> After the one-click login, go straight to the service\'s page in the LogicBoxes panel</label>', 'For VPS / Dedicated this is where the Server Management Panel is (ManageServiceServlet?orderid=...). The new tab signs in first, then moves to the order page after the delay below.');
        $h .= $this->row('Hide the order panel (servers)', '<label><input type="checkbox" name="panel_hide" value="1"' . $chk('panel_hide') . '> VPS / Dedicated: open the Server Management Panel directly, without showing the LogicBoxes order panel</label>', 'This WHMCS server signs in to the order panel itself and sends the client straight to the Server Management Panel. If that fails, the normal panel login is used and the reason is logged. Check it with the "Test Server Panel Login" button on a VPS service.');
        $h .= $this->row('Server sign-in IP', '<input type="text" name="panel_sso_ip" class="form-control" style="max-width:220px" placeholder="detect" value="' . $this->e((string) ($s['panel_sso_ip'] ?? '')) . '">', 'Public IP address of this WHMCS server, used for that sign-in. Leave empty to detect.');
        $h .= $this->row('Order page delay', '<input type="number" name="panel_order_delay" min="2" max="30" class="form-control input-100" style="display:inline-block;width:90px" value="' . $this->e((string) ($s['panel_order_delay'] ?? '6')) . '"> seconds', 'Time the LogicBoxes login needs before the order page opens. If clients see "You need to be logged in", raise it.');
        $h .= $this->row('Client "Refresh"', '<label><input type="checkbox" name="allow_refresh" value="1"' . $chk('allow_refresh') . '> Show the Refresh button in Quick actions</label>', 'The page still refreshes itself in the background at most every 30 minutes.');
        $h .= $this->row('SSH access', '<label><input type="checkbox" name="allow_shell" value="1"' . $chk('allow_shell') . '> Let Cloud Hosting clients switch SSH access on and off</label>');
        $h .= $this->row('DNS section', '<label><input type="checkbox" name="show_dns" value="1"' . $chk('show_dns') . '> Show name servers and the DNS records the hosting needs, with a live check</label>');
        $h .= $sections('dash_expires', 'Show "Expires" date', 'Product types whose Service overview shows the expiry date from LogicBoxes. Untick a type to hide that row.');
        $h .= $this->row('Show panel username', '<label><input type="checkbox" name="show_credentials" value="1"' . $chk('show_credentials') . '> Show the control panel / server username on the dashboard</label>', 'Passwords are never stored by the module.');
        $h .= $this->row('Dashboard notice', '<textarea name="dashboard_notice" class="form-control" rows="2" maxlength="500">' . $this->e($s['dashboard_notice'] ?? '') . '</textarea>', 'Plain text shown at the top of every dashboard. For layout changes copy <code>servers/{module}/templates/clientarea.tpl</code> to <code>servers/{module}/overrides/templates/</code>.');

        $h .= '<h3>Name servers and DNS</h3>';
        $h .= $this->row('Point the client\'s domain at', $sel('ns_mode', ['off' => 'Off - only show what is needed', 'branded' => 'Your branded name servers (API account), with the DNS records added on LogicBoxes DNS', 'server' => 'The hosting server\'s own name servers']), 'Only a domain that is Active in WHMCS under the same client is changed, through its own registrar module. Branded mode adds the records only when the domain is registered in the same LogicBoxes account.');
        $h .= $this->row('After Create', '<label><input type="checkbox" name="ns_on_create" value="1"' . $chk('ns_on_create') . '> Run once automatically when a hosting service is created</label>');
        $h .= $this->row('Client button', '<label><input type="checkbox" name="ns_client_button" value="1"' . $chk('ns_client_button') . '> Let clients apply the name servers from their dashboard</label>');
        $langs = array_combine(Lang::available(), array_map('ucfirst', Lang::available())) ?: ['english' => 'English'];
        $h .= $this->row('Fallback language', $sel('default_language', $langs), 'Used when the client\'s language is unknown. Missing strings always fall back to English.');
        $h .= '<div class="form-group"><div class="col-sm-offset-3 col-sm-9"><button class="btn btn-primary" type="submit">Save settings</button></div></div></form>';

        return $h;
    }

    private function pageLanguages(): string
    {
        $root = dirname(__DIR__, 2);
        $h = '<p>Language files live in <code>modules/addons/lbhosting/lang/</code>. English is the base; any key missing from another language falls back to English, and an unknown language falls back entirely.</p>'
            . '<p>To change wording or add a language without losing it on update, create <code>modules/addons/lbhosting/overrides/lang/&lt;language&gt;.php</code> returning an array of only the keys you want to change (WHMCS language names, e.g. <code>french.php</code>).</p>'
            . '<table class="table" style="max-width:640px"><thead><tr><th>Language</th><th>Module file</th><th>Override</th><th>Keys translated</th></tr></thead><tbody>';
        $base = count(Lang::strings('english'));
        foreach (Lang::available() as $l) {
            $mod = is_file($root . '/lang/' . $l . '.php');
            $ovr = is_file($root . '/overrides/lang/' . $l . '.php');
            $own = $mod ? count((array) include $root . '/lang/' . $l . '.php') : 0;
            $h .= '<tr><td>' . $this->e($l) . '</td><td>' . ($mod ? 'yes' : '-') . '</td><td>' . ($ovr ? 'yes' : '-') . '</td><td>' . ($l === 'english' ? $base : $own . ' / ' . $base) . '</td></tr>';
        }

        return $h . '</tbody></table>';
    }

    private function pageLogs(): string
    {
        $q = Capsule::table('mod_lbh_logs')->orderBy('id', 'desc')->limit(250);
        $sid = (int) ($_GET['serviceid'] ?? 0);
        if ($sid > 0) {
            $q->where('service_id', $sid);
        }
        $level = (string) ($_GET['level'] ?? '');
        if (in_array($level, ['info', 'warning', 'error'], true)) {
            $q->where('level', $level);
        }
        $h = '<form method="get" class="form-inline" style="margin-bottom:10px"><input type="hidden" name="module" value="lbhosting"><input type="hidden" name="page" value="logs">'
            . 'Service ID <input name="serviceid" class="form-control input-sm" style="width:100px" value="' . ($sid ?: '') . '"> '
            . '<select name="level" class="form-control input-sm"><option value="">All levels</option><option' . ($level === 'error' ? ' selected' : '') . '>error</option><option' . ($level === 'warning' ? ' selected' : '') . '>warning</option><option' . ($level === 'info' ? ' selected' : '') . '>info</option></select> '
            . '<button class="btn btn-default btn-sm">Filter</button> <span class="text-muted">Raw API traffic is in Utilities > Logs > Module Log (module "lbhosting").</span></form>';
        $h .= '<table class="table table-condensed table-striped"><thead><tr><th>Time</th><th>Service</th><th>Action</th><th>Level</th><th>Message</th></tr></thead><tbody>';
        foreach ($q->get() as $r) {
            $cls = $r->level === 'error' ? 'danger' : ($r->level === 'warning' ? 'warning' : '');
            $svc = $r->service_id ? '<a href="clientsservices.php?id=' . (int) $r->service_id . '">#' . (int) $r->service_id . '</a>' : '-';
            $h .= '<tr class="' . $cls . '"><td style="white-space:nowrap">' . $this->e($r->created_at) . '</td><td>' . $svc . '</td><td>' . $this->e($r->action) . '</td><td>' . $this->e($r->level) . '</td><td>' . $this->e($r->message) . '</td></tr>';
        }

        return $h . '</tbody></table>';
    }

    // --------------------------------------------------------------- helpers

    private function nav(string $active): string
    {
        $tabs = ['dashboard' => 'Dashboard', 'accounts' => 'API Accounts', 'catalog' => 'Price Catalog', 'generator' => 'Product Generator', 'products' => 'Products & Price Sync', 'upgrades' => 'Upgrades', 'tasks' => 'Manual Tasks', 'import' => 'Import Manager', 'settings' => 'Settings', 'updates' => 'Updates', 'languages' => 'Languages', 'logs' => 'Logs'];
        $h = '<ul class="nav nav-tabs" style="margin-bottom:18px">';
        foreach ($tabs as $k => $label) {
            $isActive = $k === $active || ($active === 'account' && $k === 'accounts') || ($active === 'map' && $k === 'products');
            $h .= '<li class="nav-item' . ($isActive ? ' active' : '') . '"><a class="nav-link' . ($isActive ? ' active' : '') . '" href="' . $this->e($this->url($k)) . '">' . $this->e($label) . '</a></li>';
        }

        return $h . '</ul>';
    }

    private function flashHtml(): string
    {
        if (!empty($_SESSION['lbh_admin_flash']) && is_array($_SESSION['lbh_admin_flash'])) {
            $this->messages = array_merge($_SESSION['lbh_admin_flash'], $this->messages);
            unset($_SESSION['lbh_admin_flash']);
        }
        $h = '';
        foreach ($this->messages as [$type, $msg]) {
            $h .= '<div class="alert alert-' . $this->e($type) . '">' . $this->e($msg) . '</div>';
        }

        return $h;
    }

    public function redirect(string $page, array $query = []): void
    {
        if (!headers_sent() && !defined('LBH_TESTING')) {
            $_SESSION['lbh_admin_flash'] = $this->messages;
            header('Location: ' . $this->url($page, $query), true, 303);
            exit;
        }
    }

    /** @param array<string,scalar> $query */
    public function url(string $page, array $query = []): string
    {
        return $this->base . '&' . http_build_query(array_merge(['page' => $page], $query));
    }

    public function postButton(string $page, string $do, int $id, string $label, string $cls, string $confirm = ''): string
    {
        return '<form method="post" action="' . $this->e($this->url($page)) . '" style="display:inline"' . ($confirm !== '' ? ' onsubmit="return confirm(' . $this->e(json_encode($confirm)) . ')"' : '') . '>'
            . $this->tokenField() . '<input type="hidden" name="do" value="' . $this->e($do) . '"><input type="hidden" name="id" value="' . $id . '">'
            . '<button class="btn btn-sm ' . $this->e($cls) . '" type="submit">' . $this->e($label) . '</button></form>';
    }

    public function tokenField(): string
    {
        $whmcsToken = function_exists('generate_token') ? (string) generate_token('form') : '';

        return Csrf::field('admin') . $whmcsToken;
    }

    public function row(string $label, string $control, string $help = ''): string
    {
        return '<div class="form-group row"><label class="col-sm-3 control-label col-form-label">' . $this->e($label) . '</label><div class="col-sm-9">' . $control
            . ($help !== '' ? '<p class="help-block text-muted small">' . $this->e($help) . '</p>' : '') . '</div></div>';
    }

    /** @param mixed $v */
    public function e($v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    }
}
