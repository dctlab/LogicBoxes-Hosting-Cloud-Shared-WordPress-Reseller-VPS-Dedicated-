<?php

declare(strict_types=1);

namespace LbHosting\WHMCS;

use Illuminate\Database\Capsule\Manager as Capsule;
use LbHosting\Catalog\ProductFamily;
use LbHosting\I18n\Lang;
use LbHosting\Service\AddonManager;
use LbHosting\Service\AutoLogin;
use LbHosting\Service\BlockStorageService;
use LbHosting\Service\DnsInspector;
use LbHosting\Service\ManualFulfilment;
use LbHosting\Service\NameserverAutomation;
use LbHosting\Service\Provisioner;
use LbHosting\Service\RenewalInvoiceService;
use LbHosting\Service\ServiceRepository;
use LbHosting\Service\UpgradeException;
use LbHosting\Service\UpgradeService;
use LbHosting\Support\Csrf;
use LbHosting\Support\Logger;
use LbHosting\Support\OutputSignal;
use LbHosting\Support\RedirectSignal;
use LbHosting\Support\Settings;

/**
 * The client dashboard of every LogicBoxes Hosting product.
 *
 * render()  builds the page: order facts, add-ons, DNS / name servers with a
 *           live check, the actions this product supports and the Phase 4
 *           change forms. Provider data is refreshed in the background at
 *           most every 30 minutes; a failed refresh never breaks the page.
 * action()  runs one client action (modop=custom): refresh, renew now, panel
 *           login, order-panel login, password change, SSH on/off, name
 *           servers. Every action is POST + CSRF token + owner check and ends
 *           in a redirect back to the service page with a flash message.
 *
 * Customise: copy servers/{module}/templates/clientarea.tpl to
 * servers/{module}/overrides/templates/clientarea.tpl; switch sections on and
 * off under Addons > LogicBoxes Hosting > Settings > Client area.
 */
final class ClientAreaController
{
    private const FLASH = 'lbh_flash';

    public const ACTIONS = ['ClientRefresh', 'ClientRenew', 'ClientLogin', 'ClientPanel', 'ClientPassword', 'ClientShell', 'ClientNameservers', 'ClientPower'];

    /** Dashboard sections an admin can show or hide per product type (Settings > Client area). */
    public const SECTIONS = ['actions' => 'dash_actions', 'changes' => 'dash_changes', 'power' => 'dash_power', 'expires' => 'dash_expires'];

    /** Power requests a client can make on a server (VPS / Dedicated). */
    public const POWER = ['reboot', 'shutdown', 'start'];

    /**
     * After an update, make WHMCS rebuild the client page. WHMCS (Smarty) only recompiles a template whose file
     * date is newer than its compiled copy, and files unpacked from a zip can keep an older date - so the old
     * page kept showing. Once per version the eight module templates get the current date.
     */
    public static function refreshTemplates(?string $serversDir = null): int
    {
        try {
            if (Settings::get('tpl_version') === LBH_VERSION) {
                return 0;
            }
            $dir = $serversDir ?? dirname(LBH_ROOT, 2) . '/servers';
            $done = 0;
            foreach (ProductFamily::all() as $family) {
                $file = $dir . '/' . ProductFamily::module($family) . '/templates/clientarea.tpl';
                if (is_file($file) && @touch($file)) {
                    $done++;
                }
            }
            Settings::set('tpl_version', LBH_VERSION);
            clearstatcache();

            return $done;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** Power buttons switched on in Settings, in display order. */
    public static function powerButtons(): array
    {
        $on = array_map('trim', explode(',', Settings::get('power_buttons')));

        return array_values(array_filter(self::POWER, static fn (string $p): bool => in_array($p, $on, true)));
    }

    /** Whether a dashboard section is switched on for this product type. */
    public static function shows(string $section, string $family): bool
    {
        $setting = self::SECTIONS[$section] ?? null;
        if ($setting === null) {
            return true;
        }
        $families = array_filter(array_map('trim', explode(',', Settings::get($setting))));

        return in_array($family, $families, true);
    }

    /** A dedicated resolver for tests (fn(string $host, int $type): array). */
    public static $dnsResolver = null;

    /**
     * The provider-priced change flow (Phase 4): what can be changed, plus the
     * handling of this page's own POSTs (quote / confirm). Every POST needs
     * the per-service CSRF token; every submitted id is checked against what
     * UpgradeService offers for THIS service.
     *
     * @param array<string,mixed> $params
     * @param array<string,string> $strings
     * @return array<string,mixed>
     */
    private static function upgrades(string $family, int $serviceId, array $params, array $strings): array
    {
        $out = ['lbh_upgrade' => null, 'lbh_quote' => null, 'lbh_notice' => '', 'lbh_error' => '', 'lbh_token' => '', 'lbh_form_url' => 'clientarea.php?action=productdetails&id=' . $serviceId];
        if (!UpgradeService::enabled() || !self::shows('changes', $family) || (string) ($params['status'] ?? 'Active') !== 'Active') {
            return $out;
        }
        $svc = new UpgradeService(ServerModule::$transport);
        $ctx = 'client' . $serviceId;
        $action = (string) ($_POST['lbh_action'] ?? '');
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && in_array($action, ['upgrade_quote', 'upgrade_confirm'], true)) {
            try {
                if (!Csrf::verify($_POST['lbh_token'] ?? null, $ctx)) {
                    throw new UpgradeException($strings['upgrade_token'] ?? 'Your session expired. Please try again.');
                }
                $request = ($_POST['lbh_kind'] ?? '') === 'plan'
                    ? ['kind' => 'plan', 'product_id' => (int) ($_POST['lbh_product'] ?? 0)]
                    : ['kind' => 'options', 'options' => is_array($_POST['lbh_opt'] ?? null) ? $_POST['lbh_opt'] : []];
                if ($action === 'upgrade_quote') {
                    $q = $svc->quote($serviceId, $request);
                    $out['lbh_quote'] = [
                        'kind' => $q['kind'],
                        'lines' => $q['lines'],
                        'months' => $q['months'],
                        'months_text' => sprintf($strings['upgrade_for_months'] ?? '%d month(s) remaining', $q['months']),
                        'amount' => number_format($q['amount'], 2),
                        'free' => $q['amount'] <= 0,
                        'currency' => $q['currency_code'],
                        'recurring' => $q['recurring'] === null ? '' : number_format($q['recurring'], 2),
                        'product_id' => (int) ($request['product_id'] ?? 0),
                        'options' => array_map('intval', (array) ($q['payload']['options'] ?? [])),
                    ];
                } else {
                    $res = $svc->confirm($serviceId, $request);
                    if ($res['invoice_id']) {
                        self::redirect('viewinvoice.php?id=' . (int) $res['invoice_id']);
                    }
                    $out[$res['applied'] ? 'lbh_notice' : 'lbh_error'] = $res['applied']
                        ? ($strings['upgrade_applied'] ?? 'Your change has been applied.')
                        : ($strings['upgrade_failed'] ?? 'We could not complete this change. Our team has been notified.');
                }
            } catch (\LbHosting\Support\RedirectSignal $e) {
                throw $e;
            } catch (UpgradeException $e) {
                $out['lbh_error'] = $e->getMessage();
            } catch (\Throwable $e) {
                \LbHosting\Support\Logger::log('upgrade', 'Client change request failed: ' . $e->getMessage(), 'error', $serviceId);
                $out['lbh_error'] = $strings['upgrade_failed'] ?? 'We could not complete this change. Our team has been notified.';
            }
        }

        $choices = $svc->choices($serviceId);
        $pending = $choices['pending'];
        $currency = (string) \Illuminate\Database\Capsule\Manager::table('tblcurrencies')
            ->where('id', (int) \Illuminate\Database\Capsule\Manager::table('tblclients')->where('id', (int) ($params['userid'] ?? 0))->value('currency'))->value('code');
        foreach ($choices['plans'] as &$p) {
            $p['price'] = number_format((float) $p['price'], 2);
        }
        unset($p);
        foreach ($choices['options'] as &$o) {
            $o['subs'] = array_map(static fn ($s) => ['sub_id' => $s['sub_id'], 'label' => $s['label']], $o['subs']);
        }
        unset($o);
        if ($choices['plans'] || $choices['options'] || $pending) {
            $out['lbh_upgrade'] = [
                'plans' => $choices['plans'],
                'options' => $choices['options'],
                'currency' => $currency,
                'pending' => $pending ? [
                    'status' => (string) $pending->status,
                    'summary' => (string) $pending->summary,
                    'invoice_id' => $pending->status === 'invoiced' ? (int) $pending->invoice_id : 0,
                ] : null,
            ];
            $out['lbh_token'] = Csrf::token($ctx);
        }

        return $out;
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    public static function render(string $family, array $params): array
    {
        $vars = ['lbh_ok' => false, 'lbh_family' => $family];
        $serviceId = (int) ($params['serviceid'] ?? 0);
        try {
            Database::ensure();
            $clientLang = (string) ($params['clientsdetails']['language'] ?? '');
            Lang::setLanguage(null);
            $lang = Lang::language($clientLang);
            $strings = Lang::strings($lang);
            $repo = new ServiceRepository();
            $row = $repo->get($serviceId);
            $linked = !empty($row->order_id);
            $active = (string) ($params['status'] ?? 'Active') === 'Active';

            // Background refresh, at most every 30 minutes.
            if ($linked && $active && (empty($row->last_synced_at) || strtotime((string) $row->last_synced_at) < time() - 1800)) {
                try {
                    (new Provisioner($family, null, null, ServerModule::$transport))->sync($params);
                    $row = $repo->get($serviceId) ?? $row;
                } catch (\Throwable $e) {
                    Logger::log('clientarea', 'Background sync failed: ' . $e->getMessage(), 'warning', $serviceId);
                    $repo->upsert($serviceId, ['last_synced_at' => date('Y-m-d H:i:s')]);
                }
            }

            $tasks = [];
            foreach (ManualFulfilment::openFor($serviceId) as $t) {
                $tasks[] = [
                    'item' => $strings['manual_' . $t->item] ?? $t->item,
                    'target' => $t->target === '' ? ($strings['none'] ?? 'none') : (string) $t->target,
                ];
            }
            $key = $row && !empty($row->product_key) ? (string) $row->product_key : '';
            $status = (string) ($row->provider_status ?? '');
            $suspended = stripos($status, 'suspend') !== false;
            $panel = $key !== '' ? AutoLogin::panelType($family, $key) : null;
            $usable = $linked && $active && !$suspended;

            $vars = [
                'lbh_ok' => true,
                'lbh_family' => $family,
                'lbh_is_server' => ProductFamily::isServer($family),
                'lbh_linked' => $linked,
                'lbh_product' => ProductFamily::label($family),
                'lbh_domain' => (string) ($params['domain'] ?? ''),
                'lbh_location' => $key !== '' ? ProductFamily::keyLabel($family, $key) : '',
                'lbh_status' => $status,
                'lbh_status_class' => $suspended ? 'warning' : (stripos($status, 'active') !== false ? 'success' : 'default'),
                'lbh_plan' => (string) (($row->plan_name ?? '') !== '' ? $row->plan_name : ''),
                'lbh_expires' => $row && !empty($row->expires_at) && self::shows('expires', $family) ? substr((string) $row->expires_at, 0, 10) : '',
                'lbh_ip' => (string) ($row->ip_address ?? ''),
                'lbh_username' => Settings::bool('show_credentials') ? (string) ($row->panel_username ?? '') : '',
                'lbh_os' => (string) ($row->os ?? ''),
                'lbh_tasks' => $tasks,
                'lbh_lang' => $strings,
                'lbh_notice_lines' => array_values(array_filter(array_map('trim', explode("\n", Settings::get('dashboard_notice'))), 'strlen')),
                'lbh_flash' => self::takeFlash($serviceId),
                'lbh_action_url' => 'clientarea.php?action=productdetails&id=' . $serviceId . '&modop=custom&a=',
                'lbh_action_token' => Csrf::token('clientact' . $serviceId),
                'lbh_extras' => $linked ? self::extras($family, $serviceId, $row, $strings) : [],
                'lbh_can' => [
                    'refresh' => $linked && Settings::bool('allow_refresh') && in_array((string) ($params['status'] ?? 'Active'), ['Active', 'Suspended'], true),
                    'renew' => $linked && $active && Settings::bool('allow_client_renew'),
                    'login' => $usable && $panel !== null && Settings::bool('allow_panel_login'),
                    'panel' => $usable && Settings::bool('allow_order_panel'),
                    'password' => $usable && ProductFamily::can($family, 'panel_password') && Settings::bool('allow_password_change'),
                    'shell' => $usable && ProductFamily::can($family, 'shell') && Settings::bool('allow_shell'),
                    'power' => $usable && ProductFamily::isServer($family) && Settings::bool('allow_power') && Settings::bool('allow_order_panel') && self::powerButtons() !== [],
                ],
                'lbh_power_buttons' => self::powerButtons(),
                'lbh_show' => [
                    'actions' => self::shows('actions', $family),
                    'changes' => self::shows('changes', $family),
                    'power' => self::shows('power', $family),
                ],
                'lbh_panel_label' => $panel !== null ? sprintf($strings['login_panel'] ?? 'Log in to %s', AutoLogin::PANELS[$panel]['label']) : '',
                'lbh_dns' => null,
            ];
            if ($linked && $active && ProductFamily::can($family, 'dns') && Settings::bool('show_dns')) {
                $vars['lbh_dns'] = self::dns($family, $params, $row, $strings);
            }
            $vars += self::upgrades($family, $serviceId, $params, $strings);
        } catch (RedirectSignal $e) {
            throw $e; // test seam only
        } catch (\Throwable $e) {
            Logger::log('clientarea', 'Client area error: ' . $e->getMessage(), 'error', $serviceId);
            $vars = ['lbh_ok' => false, 'lbh_family' => $family, 'lbh_lang' => Lang::strings('english')];
        }

        $vars += ['lbh_upgrade' => null, 'lbh_quote' => null, 'lbh_notice' => '', 'lbh_error' => '', 'lbh_token' => '', 'lbh_form_url' => '', 'lbh_tasks' => [], 'lbh_linked' => false,
            'lbh_flash' => null, 'lbh_extras' => [], 'lbh_can' => [], 'lbh_show' => ['actions' => false, 'changes' => false, 'power' => false], 'lbh_power_buttons' => [], 'lbh_dns' => null, 'lbh_notice_lines' => [], 'lbh_action_url' => '', 'lbh_action_token' => '', 'lbh_panel_label' => '', 'lbh_domain' => '', 'lbh_status_class' => 'default'];

        if (!defined('LBH_TESTING')) {
            self::refreshTemplates();
        }
        $override = dirname(LBH_ROOT, 2) . '/servers/' . ProductFamily::module($family) . '/overrides/templates/clientarea.tpl';

        return ['templatefile' => is_file($override) ? 'overrides/templates/clientarea' : 'templates/clientarea', 'vars' => $vars];
    }

    /**
     * What is on the order besides the plan: add-ons the module applied,
     * dedicated IPs and the Block Storage volume.
     *
     * @param array<string,string> $strings
     * @return array<int,array{label:string,value:string}>
     */
    private static function extras(string $family, int $serviceId, ?object $row, array $strings): array
    {
        $out = [];
        $key = $row && !empty($row->product_key) ? (string) $row->product_key : '';
        $catalog = [];
        try {
            $catalog = $key !== '' ? ProductFamily::addons($family, $key) : [];
        } catch (\Throwable $e) {
        }
        foreach (AddonManager::all($serviceId) as $code => $qty) {
            if ((int) $qty <= 0) {
                continue;
            }
            $short = preg_replace('/^addon:/', '', (string) $code);
            $label = $strings['addon_' . $short] ?? ($catalog[$short]['label'] ?? ucwords(str_replace('_', ' ', (string) $short)));
            $out[] = ['label' => (string) $label, 'value' => (int) $qty > 1 ? '× ' . (int) $qty : ($strings['yes'] ?? 'Yes')];
        }
        $stored = ServiceRepository::storedDetails($row);
        $ips = ServiceRepository::dedicatedIps($stored);
        if ($ips) {
            $out[] = ['label' => $strings['dedicated_ips'] ?? 'Dedicated IPs', 'value' => implode(', ', array_slice($ips, 0, 16))];
        }
        $extra = array_values(array_diff(ServiceRepository::addonIps($stored), $ips, [(string) ($row->ip_address ?? '')]));
        if ($extra) {
            $out[] = ['label' => $strings['additional_ips'] ?? 'Additional IP addresses', 'value' => implode(', ', array_slice($extra, 0, 16))];
        }
        $vol = BlockStorageService::volume($serviceId);
        if ($vol && !empty($vol->order_id) && (int) $vol->size_gb > 0) {
            $out[] = ['label' => $strings['block_storage'] ?? 'Block Storage', 'value' => (int) $vol->size_gb . ' GB'];
        }

        return $out;
    }

    /**
     * Name servers and DNS records: what the order needs, what DNS answers now.
     *
     * @param array<string,mixed> $params
     * @param array<string,string> $strings
     * @return array<string,mixed>|null
     */
    private static function dns(string $family, array $params, ?object $row, array $strings): ?array
    {
        try {
            $domain = Provisioner::domain($params, false);
            $prov = new Provisioner($family, null, null, ServerModule::$transport);
            $records = NameserverAutomation::records($prov, $family, $params);
            $mode = NameserverAutomation::mode();
            $target = NameserverAutomation::target($mode, $row && $row->account_id ? (int) $row->account_id : null, $records, ServiceRepository::storedDetails($row));
            $show = array_values(array_filter($records, static fn ($r) => $r['type'] !== 'NS'));
            $live = (new DnsInspector(self::$dnsResolver))->inspect($domain, array_slice($show, 0, 12)); // each record is one live lookup
            foreach ($live['records'] as $i => $r) {
                $live['records'][$i]['state_label'] = $strings['dns_' . $r['state']] ?? $r['state'];
                $live['records'][$i]['state_class'] = $r['state'] === 'ok' ? 'success' : ($r['state'] === 'missing' ? 'danger' : 'warning');
                $live['records'][$i]['current'] = implode(', ', array_slice((array) $r['current'], 0, 4));
            }
            $a = $live['nameservers'];
            $b = $target;
            sort($b);
            $job = NameserverAutomation::job((int) $params['serviceid']);
            $wd = NameserverAutomation::whmcsDomain((int) ($params['userid'] ?? 0), $domain);

            return [
                'domain' => $domain,
                'nameservers' => $live['nameservers'],
                'target' => $target,
                'ns_ok' => $target !== [] && $a === $b,
                'records' => $live['records'],
                'all_ok' => $live['all_ok'],
                'checked_text' => sprintf($strings['dns_checked'] ?? 'Checked %s', $live['checked_at']),
                'can_apply' => $mode !== 'off' && Settings::bool('ns_client_button') && $wd !== null && count($target) >= 2 && $a !== $b,
                'job_failed' => $job && $job->status === 'failed',
            ];
        } catch (\Throwable $e) {
            Logger::log('clientarea', 'DNS section unavailable: ' . $e->getMessage(), 'warning', (int) $params['serviceid']);

            return null;
        }
    }

    // ---------------------------------------------------------------- actions

    /** @param array<string,mixed> $params */
    public static function action(string $family, string $action, array $params): string
    {
        $serviceId = (int) ($params['serviceid'] ?? 0);
        Lang::setLanguage(null);
        $lang = Lang::strings(Lang::language((string) ($params['clientsdetails']['language'] ?? '')));
        $t = static fn (string $k) => $lang[$k] ?? ($lang['error_generic'] ?? 'Something went wrong. Please try again.');
        $back = 'clientarea.php?action=productdetails&id=' . $serviceId;

        try {
            Database::ensure();
            self::guard($params);
            $active = (string) ($params['status'] ?? '') === 'Active';
            $prov = new Provisioner($family, null, null, ServerModule::$transport);
            $status = (string) ($params['status'] ?? '');
            if (!$active && !($action === 'ClientRenew' || ($action === 'ClientRefresh' && $status === 'Suspended'))) {
                throw new \DomainException('service_not_active');
            }
            self::throttle($serviceId, $action);
            switch ($action) {
                case 'ClientRefresh':
                    if (!Settings::bool('allow_refresh')) {
                        throw new \DomainException('action_disabled');
                    }
                    $prov->sync($params);
                    try {
                        if (ProductFamily::can($family, 'dns')) {
                            NameserverAutomation::records($prov, $family, $params, true);
                        }
                    } catch (\Throwable $e) {
                    }
                    foreach (array_keys($_SESSION ?? []) as $k) {
                        if (is_string($k) && str_starts_with($k, 'lbh_dns_')) {
                            unset($_SESSION[$k]);
                        }
                    }
                    self::flash($serviceId, 'success', $t('refresh_done'));
                    break;

                case 'ClientRenew':
                    if (!Settings::bool('allow_client_renew')) {
                        throw new \DomainException('action_disabled');
                    }
                    [, $invoiceId] = (new RenewalInvoiceService())->createOrFind($serviceId, (int) $params['userid']);
                    self::redirect('viewinvoice.php?id=' . $invoiceId);
                    break;

                case 'ClientLogin':
                    if (!Settings::bool('allow_panel_login')) {
                        throw new \DomainException('action_disabled');
                    }
                    [$row, $api] = $prov->linked($params);
                    $panel = AutoLogin::panelType($family, (string) $row->product_key);
                    if ($panel === null) {
                        throw new \DomainException('action_disabled');
                    }
                    // Live details: the stored copy never contains the password.
                    $details = $api->details($family, (string) $row->product_key, (int) $row->order_id, $serviceId);
                    $form = AutoLogin::panelForm($panel, $details, (string) ($params['domain'] ?? ''));
                    Logger::log('login', $form['label'] . ' login issued', 'info', $serviceId);
                    self::emit(AutoLogin::page($form, $t('login_continue')));
                    break;

                case 'ClientPanel':
                    if (!Settings::bool('allow_order_panel')) {
                        throw new \DomainException('action_disabled');
                    }
                    [$row] = $prov->linked($params);
                    $account = $prov->account(!empty($row->account_id) ? (int) $row->account_id : (new ProductConfig($family, $params))->accountId);
                    self::directPanel($account, $row, $family, $serviceId);
                    $url = AutoLogin::orderPanelUrl($account, $account->client(ServerModule::$transport), (int) ($row->customer_id ?? 0), self::clientIp(), $serviceId);
                    Logger::log('login', 'Order panel login issued', 'info', $serviceId);
                    self::openPanel($url, $row);
                    break;

                case 'ClientPower':
                    // The LogicBoxes API has no power call for VPS / Dedicated servers: the request opens the
                    // server's own LogicBoxes control panel (one-click login), where the client confirms it.
                    $power = (string) ($_POST['lbh_power'] ?? '');
                    if (!in_array($power, self::powerButtons(), true) || !ProductFamily::isServer($family) || !Settings::bool('allow_power')
                        || !Settings::bool('allow_order_panel') || !self::shows('power', $family)) {
                        throw new \DomainException('action_disabled');
                    }
                    [$row] = $prov->linked($params);
                    if (stripos((string) ($row->provider_status ?? ''), 'suspend') !== false) {
                        throw new \DomainException('service_not_active');
                    }
                    $account = $prov->account(!empty($row->account_id) ? (int) $row->account_id : (new ProductConfig($family, $params))->accountId);
                    Logger::log('power', 'Client asked to ' . $power . ' the server', 'info', $serviceId);
                    self::directPanel($account, $row, $family, $serviceId);
                    $url = AutoLogin::orderPanelUrl($account, $account->client(ServerModule::$transport), (int) ($row->customer_id ?? 0), self::clientIp(), $serviceId);
                    Logger::log('power', 'Server panel opened (fallback login)', 'info', $serviceId);
                    self::openPanel($url, $row);
                    break;

                case 'ClientPassword':
                    if (!Settings::bool('allow_password_change') || !ProductFamily::can($family, 'panel_password')) {
                        throw new \DomainException('action_disabled');
                    }
                    $new = self::rawInput($_POST['lbh_password'] ?? '');
                    if ($new !== self::rawInput($_POST['lbh_password2'] ?? '')) {
                        throw new \DomainException('password_mismatch');
                    }
                    if (!self::strongPassword($new)) {
                        throw new \DomainException('password_weak');
                    }
                    $prov->changePassword(['password' => $new] + $params);
                    self::flash($serviceId, 'success', $t('password_done'));
                    break;

                case 'ClientShell':
                    if (!Settings::bool('allow_shell') || !ProductFamily::can($family, 'shell')) {
                        throw new \DomainException('action_disabled');
                    }
                    $enable = (string) ($_POST['lbh_enable'] ?? '') === '1';
                    [$row, $api] = $prov->linked($params);
                    $api->setShellAccess($family, (string) $row->product_key, (int) $row->order_id, $enable, $serviceId);
                    Logger::log('shell', 'SSH access ' . ($enable ? 'enabled' : 'disabled') . ' by the client', 'info', $serviceId);
                    self::flash($serviceId, 'success', $t($enable ? 'shell_enabled' : 'shell_disabled'));
                    break;

                case 'ClientNameservers':
                    if (!Settings::bool('ns_client_button') || NameserverAutomation::mode() === 'off') {
                        throw new \DomainException('action_disabled');
                    }
                    $res = (new NameserverAutomation(ServerModule::$transport))->apply($family, $params, 'client', true);
                    if ($res['status'] === 'done') {
                        self::flash($serviceId, 'success', $t('ns_done'));
                    } else {
                        self::flash($serviceId, 'danger', $t(isset($lang['ns_' . $res['message']]) ? 'ns_' . $res['message'] : 'ns_failed'));
                    }
                    break;

                default:
                    throw new \DomainException('action_disabled');
            }
        } catch (RedirectSignal | OutputSignal $r) {
            throw $r; // tests only
        } catch (\DomainException $e) {
            self::flash($serviceId, 'danger', $t($e->getMessage()));
        } catch (\Throwable $e) {
            Logger::log(strtolower($action), 'Client action failed: ' . $e->getMessage(), 'error', $serviceId);
            $own = ['ClientLogin' => 'login_failed', 'ClientPanel' => 'login_failed', 'ClientPower' => 'login_failed', 'ClientPassword' => 'password_failed', 'ClientRenew' => 'renew_failed'];
            $key = $e->getMessage();
            self::flash($serviceId, 'danger', isset($lang[$key]) ? $lang[$key] : $t($own[$action] ?? 'error_generic'));
        }

        self::redirect($back);

        return 'success';
    }

    /** LogicBoxes panel passwords: 9-16 characters with upper, lower, digit and a special character. */
    public static function strongPassword(string $p): bool
    {
        return strlen($p) >= 9 && strlen($p) <= 16
            && preg_match('/[a-z]/', $p) && preg_match('/[A-Z]/', $p) && preg_match('/\d/', $p)
            && preg_match('/[^a-zA-Z0-9\s]/', $p) && !preg_match('/\s/', $p);
    }

    /**
     * WHMCS HTML-encodes request input globally (& < > " ' become entities).
     * A password must be used exactly as typed, so undo that here.
     *
     * @param mixed $v
     */
    private static function rawInput($v): string
    {
        if (!is_string($v)) {
            return '';
        }
        if (class_exists('\\WHMCS\\Input\\Sanitize') && method_exists('\\WHMCS\\Input\\Sanitize', 'decode')) {
            return (string) \WHMCS\Input\Sanitize::decode($v);
        }

        return html_entity_decode($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** The visitor's IP as WHMCS sees it (honours WHMCS's trusted-proxy settings). */
    private static function clientIp(): string
    {
        try {
            if (class_exists('\\App') && method_exists('\\App', 'getRemoteIp')) {
                $ip = (string) \App::getRemoteIp();
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        } catch (\Throwable $e) {
        }

        return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    }

    /** Seconds a client must wait before repeating an action that calls LogicBoxes or the registrar. */
    private const COOLDOWN = ['ClientRefresh' => 30, 'ClientLogin' => 5, 'ClientPanel' => 5, 'ClientPassword' => 30, 'ClientShell' => 15, 'ClientNameservers' => 120, 'ClientPower' => 5];

    private static function throttle(int $serviceId, string $action): void
    {
        $wait = self::COOLDOWN[$action] ?? 0;
        if ($wait <= 0) {
            return;
        }
        $last = (int) ($_SESSION['lbh_rate'][$serviceId][$action] ?? 0);
        if ($last > time() - $wait) {
            throw new \DomainException('too_fast');
        }
        $_SESSION['lbh_rate'][$serviceId][$action] = time();
    }

    /** @param array<string,mixed> $params */
    private static function guard(array $params): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            throw new \DomainException('session_expired');
        }
        if (!Csrf::verify($_POST['lbh_token'] ?? null, 'clientact' . (int) ($params['serviceid'] ?? 0))) {
            throw new \DomainException('session_expired');
        }
        // WHMCS already scopes productdetails to the owner; verify anyway.
        $sessionClient = (int) ($_SESSION['uid'] ?? 0);
        if ($sessionClient <= 0 || $sessionClient !== (int) ($params['userid'] ?? -1)) {
            throw new \DomainException('session_expired');
        }
    }

    private static function flash(int $serviceId, string $type, string $message): void
    {
        $_SESSION[self::FLASH][$serviceId] = ['type' => $type, 'message' => $message];
    }

    /** @return array{type:string,message:string}|null */
    private static function takeFlash(int $serviceId): ?array
    {
        $f = $_SESSION[self::FLASH][$serviceId] ?? null;
        unset($_SESSION[self::FLASH][$serviceId]);

        return is_array($f) ? $f : null;
    }

    /** A standalone page (the panel login form). Never cached. */
    private static function emit(string $html): void
    {
        if (defined('LBH_TESTING')) {
            throw new OutputSignal($html);
        }
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate, private');
            header('Pragma: no-cache');
            header('Referrer-Policy: no-referrer');
            header('X-Robots-Tag: noindex');
        }
        echo $html;
        exit;
    }

    /**
     * VPS / Dedicated with "Hide the order panel" on: the WHMCS server signs in to the branded panel itself and the
     * client goes straight to the Server Management Panel hand-off. Returns (no output) when it cannot - the caller
     * then uses the normal panel login.
     */
    private static function directPanel(\LbHosting\Accounts\Account $account, object $row, string $family, int $serviceId): void
    {
        if (!Settings::bool('panel_hide') || !ProductFamily::isServer($family)) {
            return;
        }
        try {
            $hand = self::panelHandoff($account, $row, $serviceId);
        } catch (\Throwable $e) {
            Logger::log('login', 'Direct Server Management Panel login failed, using the panel login: ' . $e->getMessage(), 'warning', $serviceId);

            return;
        }
        Logger::log('login', 'Server Management Panel opened directly (' . parse_url($hand['url'], PHP_URL_HOST) . ')', 'info', $serviceId);
        if ($hand['method'] === 'GET') {
            self::redirect($hand['url'], true);

            return;
        }
        $inputs = '';
        foreach ($hand['fields'] as $k => $v) {
            $inputs .= '<input type="hidden" name="' . htmlspecialchars((string) $k, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8') . '">';
        }
        self::emit('<!doctype html><html><head><meta charset="utf-8"><meta name="robots" content="noindex"><title>Opening...</title></head>'
            . '<body onload="document.forms[0].submit()" style="font-family:sans-serif;padding:40px;text-align:center"><p>Opening the Server Management Panel...</p>'
            . '<form method="post" action="' . htmlspecialchars($hand['url'], ENT_QUOTES, 'UTF-8') . '">' . $inputs . '<noscript><button type="submit">Continue</button></noscript></form></body></html>');
    }

    /**
     * Server-side sign-in to the branded panel, stopping at the Server Management Panel hand-off.
     *
     * @return array{method:string,url:string,fields:array<string,string>}
     */
    public static function panelHandoff(\LbHosting\Accounts\Account $account, object $row, int $serviceId, ?array &$trace = null): array
    {
        $client = $account->client(ServerModule::$transport);
        $login = AutoLogin::orderPanelUrl($account, $client, (int) ($row->customer_id ?? 0), self::serverIp(), $serviceId);
        $page = AutoLogin::orderPageUrl((string) parse_url($login, PHP_URL_HOST), (int) ($row->order_id ?? 0), (string) ($row->product_key ?? ''));
        if ($page === null) {
            throw new \RuntimeException('no_order_page');
        }
        $sso = new \LbHosting\Service\PanelSso();
        try {
            return $sso->resolve($login, $page);
        } finally {
            $trace = $sso->trace;
        }
    }

    /** The IP the server-side sign-in comes from: the setting, else this server's public address, else the client's. */
    private static function serverIp(): string
    {
        $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        foreach ([Settings::get('panel_sso_ip'), (string) ($_SERVER['SERVER_ADDR'] ?? '')] as $ip) {
            if (filter_var(trim($ip), FILTER_VALIDATE_IP, $flags)) {
                return trim($ip);
            }
        }

        return self::clientIp();
    }

    /**
     * Send the client to the LogicBoxes panel. LogicBoxes has no "land on this order" option for its one-click
     * login (a redirect field is followed before the session exists), so when "Open the order's own page" is on
     * the tab signs in first and the dashboard that opened it moves it to the order's page a few seconds later.
     */
    private static function openPanel(string $loginUrl, object $row): void
    {
        if (stripos($loginUrl, 'https://') !== 0) {
            self::redirect('clientarea.php');

            return;
        }
        $host = (string) parse_url($loginUrl, PHP_URL_HOST);
        $page = Settings::bool('panel_order_page') ? AutoLogin::orderPageUrl($host, (int) ($row->order_id ?? 0), (string) ($row->product_key ?? '')) : null;
        if ($page === null) {
            self::redirect($loginUrl, true);

            return;
        }
        $j = static fn (string $v): string => (string) json_encode($v, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
        $delay = max(2, min(30, (int) Settings::get('panel_order_delay'))) * 1000;
        $e = htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8');
        self::emit('<!doctype html><html><head><meta charset="utf-8"><meta name="robots" content="noindex"><title>Signing in...</title></head>'
            . '<body style="font-family:sans-serif;padding:40px;text-align:center"><p>Signing you in to the control panel...</p>'
            . '<script>(function(){var page=' . $j($page) . ',login=' . $j($loginUrl) . ';'
            . 'try{if(window.opener&&window.opener.lbhPanelPage){window.opener.lbhPanelPage(window.name,page,' . $delay . ');}}catch(e){}'
            . 'window.location.replace(login);})();</script>'
            . '<noscript><a href="' . $e . '">Continue</a></noscript></body></html>');
    }

    /** Relative WHMCS URLs only, unless $external (an https URL this module built). */
    private static function redirect(string $url, bool $external = false): void
    {
        if (!$external && preg_match('#^[a-z][a-z0-9+.-]*:#i', $url)) {
            $url = 'clientarea.php';
        }
        if ($external && stripos($url, 'https://') !== 0) {
            $url = 'clientarea.php';
        }
        if (defined('LBH_TESTING')) {
            throw new RedirectSignal($url);
        }
        if (!headers_sent()) {
            header('Location: ' . $url, true, 303);
            exit;
        }
    }
}
