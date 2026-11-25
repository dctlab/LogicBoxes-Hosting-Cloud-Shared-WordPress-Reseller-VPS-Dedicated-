<?php

declare(strict_types=1);

namespace LbHosting\WHMCS;

use Illuminate\Database\Capsule\Manager as Capsule;
use LbHosting\Accounts\AccountRepository;
use LbHosting\Api\HostingApi;
use LbHosting\Catalog\ProductFamily;
use LbHosting\Service\AddonManager;
use LbHosting\Service\ManualFulfilment;
use LbHosting\Service\Provisioner;
use LbHosting\Service\ServiceRepository;
use LbHosting\Support\Logger;

/**
 * Implementation behind the thin lbhcloud_* / lbhmultidomain_* / ... functions.
 * Every lifecycle method catches everything and returns the WHMCS contract
 * shape - an exception must never escape to WHMCS.
 */
final class ServerModule
{
    /** Test seam: offline tests inject a scripted transport. Always null in production. */
    public static ?\LbHosting\Api\HttpTransport $transport = null;

    /** @return array<string,mixed> */
    public static function metaData(string $family): array
    {
        return [
            'DisplayName' => ProductFamily::label($family) . ' (LogicBoxes / ResellerClub)',
            'APIVersion' => '1.1',
            'RequiresServer' => false,
        ];
    }

    /** @return array<string,array<string,mixed>> ORDER MATTERS - see ProductConfig */
    public static function configOptions(string $family): array
    {
        $module = ProductFamily::module($family);
        $opts = [
            'API Account' => [
                'Type' => 'dropdown',
                'Options' => self::accountOptions(),
                'Description' => 'LogicBoxes reseller account (Addons > LogicBoxes Hosting > API Accounts). Blank = default account.',
                'SimpleMode' => true,
            ],
            'Plan' => [
                'Type' => 'text', 'Size' => '40', 'Loader' => $module . '_LoaderPlans', 'SimpleMode' => true,
                'Description' => 'Click the load icon to pick a plan. Stored as productkey:planid - the product key decides location and OS.',
            ],
            'Invoice Option' => [
                'Type' => 'dropdown',
                'Options' => ['NoInvoice' => 'NoInvoice (debit reseller balance)', 'PayInvoice' => 'PayInvoice', 'KeepInvoice' => 'KeepInvoice', 'OnlyAdd' => 'OnlyAdd'],
                'Default' => 'NoInvoice',
                'Description' => 'How LogicBoxes bills YOU for orders, renewals, upgrades and add-ons',
                'SimpleMode' => true,
            ],
        ];
        if (ProductFamily::isServer($family)) {
            $opts['Default OS'] = [
                'Type' => 'text', 'Size' => '16', 'SimpleMode' => true,
                'Description' => 'OS code used when the order has no "Operating System" option, e.g. alma_8, ubuntu_22, win2k19. Blank = provider default.',
            ];
            $opts['Included Add-ons'] = [
                'Type' => 'text', 'Size' => '40', 'SimpleMode' => true,
                'Description' => 'Add-on codes always ordered with this product, comma separated (e.g. cpanel,whmcs). The customer is not charged for them separately.',
            ];
        }

        return $opts;
    }

    /** @return array<string,string> */
    private static function accountOptions(): array
    {
        try {
            Database::ensure();
            $opts = ['' => '- Default account -'];
            foreach ((new AccountRepository())->options() as $id => $label) {
                $opts[(string) $id] = $label;
            }

            return $opts;
        } catch (\Throwable $e) {
            return ['' => '- Activate the LogicBoxes Hosting addon first -'];
        }
    }

    /** @param array<string,mixed> $params @return array<string,string> */
    public static function loaderPlans(string $family, array $params): array
    {
        Database::ensure();
        $accId = ProductConfig::int($params['configoption1'] ?? null);
        $account = (new AccountRepository())->resolve($accId > 0 ? $accId : null);
        $keys = array_keys(ProductFamily::keys($family));
        $out = [];
        foreach ($account->api(self::$transport)->plansForKeys($keys) as $key => $plans) {
            foreach ($plans as $id => $plan) {
                $out[ProductConfig::planValue($key, $id)] = sprintf('%s - %s (%s:%d)', ProductFamily::keyLabel($family, $key), HostingApi::planName($plan, $id), $key, $id);
            }
        }
        if (!$out) {
            throw new \RuntimeException('No ' . ProductFamily::label($family) . ' plans found on account "' . $account->name . '". Is the product enabled for your reseller account?');
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $params
     * @param callable(Provisioner, array<string,mixed>): mixed $fn
     */
    public static function run(string $family, string $action, array $params, callable $fn): string
    {
        try {
            Database::ensure();
            $fn(new Provisioner($family, null, null, self::$transport), $params);
            if (in_array($action, ['CreateAccount', 'ChangePackage'], true) && !empty($params['serviceid'])) {
                try {
                    \LbHosting\Pricing\RecurringPricer::afterChange((int) $params['serviceid'], $action);
                } catch (\Throwable $e) {
                    Logger::log('pricing', 'Recurring price check after ' . $action . ' failed: ' . $e->getMessage(), 'warning', (int) $params['serviceid']);
                }
            }

            if ($action === 'CreateAccount' && !empty($params['serviceid']) && \LbHosting\Support\Settings::bool('ns_on_create')) {
                try {
                    (new \LbHosting\Service\NameserverAutomation(self::$transport))->apply($family, $params, 'create');
                } catch (\Throwable $e) {
                    Logger::log('nameservers', 'Name server automation after Create failed: ' . $e->getMessage(), 'warning', (int) $params['serviceid']);
                }
            }

            return 'success';
        } catch (\Throwable $e) {
            Logger::log(strtolower($action), $action . ' failed: ' . $e->getMessage(), 'error', isset($params['serviceid']) ? (int) $params['serviceid'] : null);
            Logger::moduleCall($action, $params['serviceid'] ?? null, get_class($e) . ': ' . $e->getMessage(), $e->getMessage());

            return 'Error: ' . $e->getMessage();
        }
    }

    /** @return array<string,string> */
    public static function adminButtons(string $family): array
    {
        return [
            'Sync from Provider' => 'AdminSync',
            'Link Existing Order' => 'AdminLinkOrder',
            'Renew at Provider' => 'AdminRenew',
            'Reconcile Add-ons' => 'AdminReconcile',
            'Show Provider Details' => 'AdminDetails',
        ] + (ProductFamily::can($family, 'dns') ? ['Apply Name Servers' => 'AdminNameservers'] : [])
          + (ProductFamily::isServer($family) ? ['Test Server Panel Login' => 'AdminPanelTest'] : []);
    }

    /** Admin "Apply Name Servers": run the name server / DNS automation again. @param array<string,mixed> $params */
    public static function adminNameservers(string $family, array $params): string
    {
        try {
            Database::ensure();
            $serviceId = (int) $params['serviceid'];
            if (\LbHosting\Service\NameserverAutomation::mode() === 'off') {
                return 'Error: Name server automation is off (Addons > LogicBoxes Hosting > Settings > Name servers and DNS).';
            }
            $r = (new \LbHosting\Service\NameserverAutomation(self::$transport))->apply($family, $params, 'admin', true);
            $text = [
                'ok' => 'Name servers set to ' . implode(', ', $r['nameservers']) . ($r['records_added'] ? '; ' . $r['records_added'] . ' DNS record(s) added.' : '.'),
                'zone_elsewhere' => 'The domain was not found as a domain order in this LogicBoxes account, so its DNS zone cannot be set up there. Its name servers were NOT changed.',
                'zone_partial' => 'Some DNS records could not be added (see the module log), so the name servers were NOT changed. Fix the cause and run this again.',
                'no_records' => 'LogicBoxes returned no DNS records for this order yet, so the name servers were NOT changed. Try again once the order is set up.',
                'domain_not_in_whmcs' => 'The service domain is not an Active domain of this client in WHMCS - nothing was changed.',
                'no_branded_ns' => 'Enter at least two branded name servers on the API account.',
                'no_server_ns' => 'LogicBoxes did not report the hosting server\'s name servers for this order.',
                'registrar_failed' => 'The registrar module refused the name server change - see the module log.',
            ][$r['message']] ?? $r['message'];
            if ($r['status'] === 'failed') {
                return 'Error: ' . $text;
            }
            $_SESSION['lbh_admin_msg'][$serviceId] = [$r['status'] === 'done' ? 'success' : 'warning', $text];

            return 'success';
        } catch (\Throwable $e) {
            return 'Error: ' . $e->getMessage();
        }
    }

    /** Admin "Test Server Panel Login": the server-side sign-in step by step. @param array<string,mixed> $params */
    public static function adminPanelTest(string $family, array $params): string
    {
        try {
            Database::ensure();
            $serviceId = (int) $params['serviceid'];
            $prov = new Provisioner($family, null, null, self::$transport);
            [$row] = $prov->linked($params);
            $account = $prov->account(!empty($row->account_id) ? (int) $row->account_id : (new \LbHosting\WHMCS\ProductConfig($family, $params))->accountId);
            $trace = [];
            try {
                $hand = ClientAreaController::panelHandoff($account, $row, $serviceId, $trace);
                $text = "Server Management Panel login works.\nThe client is sent to: " . parse_url($hand['url'], PHP_URL_SCHEME) . '://' . parse_url($hand['url'], PHP_URL_HOST) . (string) parse_url($hand['url'], PHP_URL_PATH) . ' (' . $hand['method'] . ")\n\nSteps:\n" . implode("\n", $trace);
                $_SESSION['lbh_admin_msg'][$serviceId] = ['success', $text];
            } catch (\Throwable $e) {
                $text = 'Server Management Panel login did not work: ' . $e->getMessage() . "\nClients get the normal panel login instead.\n\nSteps:\n" . implode("\n", $trace);
                $_SESSION['lbh_admin_msg'][$serviceId] = ['warning', $text];
            }
            Logger::log('admin', 'Server panel login test: ' . strtok($text, "\n"), 'info', $serviceId);

            return 'success';
        } catch (\Throwable $e) {
            return 'Error: ' . $e->getMessage();
        }
    }

    /** Admin "Show Provider Details": the redacted details payload, for field mapping. @param array<string,mixed> $params */
    public static function adminDetails(string $family, array $params): string
    {
        return self::run($family, 'AdminDetails', $params, static function (Provisioner $p, array $x) {
            $d = ServiceRepository::redactDetails($p->sync($x));
            $_SESSION['lbh_admin_msg'][(int) $x['serviceid']] = ['info', "Provider details (secrets removed):\n" . json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
        });
    }

    /** @param array<string,mixed> $params @return array<string,string> */
    public static function adminServicesTabFields(string $family, array $params): array
    {
        try {
            Database::ensure();
            $serviceId = (int) $params['serviceid'];
            $row = (new ServiceRepository())->get($serviceId);
            $e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
            $accounts = (new AccountRepository())->options();
            $accName = $row && $row->account_id ? ($accounts[(int) $row->account_id] ?? ('#' . $row->account_id)) : '(product default)';
            $msg = $_SESSION['lbh_admin_msg'][$serviceId] ?? null;
            unset($_SESSION['lbh_admin_msg'][$serviceId]);
            $label = ProductFamily::label($family);

            $fields = [];
            if ($msg) {
                $body = str_contains((string) $msg[1], "\n")
                    ? '<pre style="max-height:420px;overflow:auto;white-space:pre-wrap;margin:0">' . $e($msg[1]) . '</pre>'
                    : $e($msg[1]);
                $fields[$label] = '<div class="alert alert-' . $e($msg[0]) . '" style="margin:0">' . $body . '</div>';
            }
            $key = $row && !empty($row->product_key) ? (string) $row->product_key : '';
            $keyInfo = '-';
            if ($key !== '') {
                $conf = '';
                try {
                    $conf = (string) ProductFamily::keyMeta($family, $key)['conf'];
                } catch (\Throwable $ex) {
                }
                $keyInfo = $e(ProductFamily::keyLabel($family, $key)) . ' <code>' . $e($key) . '</code>' . ($conf === 'PATTERN' ? ' <span class="label label-warning badge bg-warning" title="API path for this location is inferred, not documented">path unverified</span>' : '');
            }
            $fields += [
                'LogicBoxes Order ID' => '<input type="text" name="lbh_order_id" class="form-control input-200" value="' . $e($row->order_id ?? '') . '"> <small>Change only to re-link this service to another existing order.</small>',
                'API Account / Location' => $e($accName) . ' / ' . $keyInfo,
                'Customer ID / Plan' => $e($row->customer_id ?? '-') . ' / ' . $e(($row->plan_name ?? '') !== '' ? $row->plan_name . ' (' . $row->plan_id . ')' : ($row->plan_id ?? '-')),
                'Provider Status / Expiry' => $e($row->provider_status ?? '-') . ' / ' . $e($row->expires_at ?? '-'),
                'IP / Panel user / OS' => $e($row->ip_address ?? '-') . ' / ' . $e($row->panel_username ?? '-') . ' / ' . $e($row->os ?? '-'),
            ];
            $addons = AddonManager::all($serviceId);
            if ($addons) {
                $parts = [];
                foreach ($addons as $a => $q) {
                    $parts[] = $e($a) . ' &times; ' . (int) $q;
                }
                $fields['Add-ons applied by module'] = implode(', ', $parts);
            }
            $details = ServiceRepository::storedDetails($row);
            if ($details) {
                $fields['LogicBoxes details'] = '<details><summary>Show the order details last read from LogicBoxes (passwords removed)</summary><pre style="max-height:420px;overflow:auto;white-space:pre-wrap;margin:6px 0 0">'
                    . $e(json_encode($details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '</pre></details>';
            }
            $vol = Capsule::table('mod_lbh_block_volumes')->where('service_id', $serviceId)->first();
            if ($vol) {
                $fields['Block Storage'] = (int) $vol->size_gb . ' GB, order ' . $e($vol->order_id ?? '-') . ' (' . $e($vol->status ?? '-') . ')';
            }
            $up = Capsule::table('mod_lbh_upgrades')->where('service_id', $serviceId)->whereIn('status', \LbHosting\Service\UpgradeService::OPEN)->orderBy('id', 'desc')->first();
            if ($up) {
                $fields['Pending change'] = '<span class="label label-' . ($up->status === 'failed' ? 'danger' : 'info') . ' badge">' . $e($up->status) . '</span> ' . $e($up->summary)
                    . (!empty($up->error) ? ' <small class="text-danger">' . $e($up->error) . '</small>' : '')
                    . ' <a href="addonmodules.php?module=lbhosting&amp;page=upgrades&amp;show=all">Upgrades queue</a>';
            }
            $job = Capsule::table('mod_lbh_dns_jobs')->where('service_id', $serviceId)->first();
            if ($job) {
                $fields['Name servers'] = '<span class="label label-' . ($job->status === 'done' ? 'success' : ($job->status === 'failed' ? 'danger' : 'default')) . ' badge">' . $e($job->status) . '</span> '
                    . $e(implode(', ', json_decode((string) $job->nameservers, true) ?: [])) . ' <small class="text-muted">' . $e($job->mode . ' / ' . $job->source . ' / ' . $job->message . ' / ' . $job->updated_at) . '</small>';
            }
            $tasks = ManualFulfilment::openFor($serviceId);
            if ($tasks) {
                $t = [];
                foreach ($tasks as $task) {
                    $t[] = $e((ManualFulfilment::ITEMS[$task->item] ?? $task->item) . ': ' . ($task->previous ?? 'none') . ' -> ' . ($task->target === '' ? 'none' : $task->target))
                        . ($task->ticket_id ? ' (ticket #' . (int) $task->ticket_id . ')' : '');
                }
                $fields['Open manual tasks'] = '<span class="text-warning">' . implode('<br>', $t) . '</span> <a href="addonmodules.php?module=lbhosting&page=tasks">Manage</a>';
            }
            $fields['Last Sync'] = $e($row->last_synced_at ?? 'never');

            return $fields;
        } catch (\Throwable $ex) {
            return [ProductFamily::label($family) => 'Unavailable: ' . htmlspecialchars($ex->getMessage(), ENT_QUOTES, 'UTF-8')];
        }
    }

    /**
     * Admin re-link of the order id, validated against LogicBoxes before it is
     * saved so a typo cannot silently point a service at someone else's order.
     *
     * @param array<string,mixed> $params
     */
    public static function adminServicesTabFieldsSave(string $family, array $params): void
    {
        if (!isset($_POST['lbh_order_id'])) {
            return;
        }
        $serviceId = (int) ($params['serviceid'] ?? 0);
        try {
            Database::ensure();
            $raw = trim((string) $_POST['lbh_order_id']);
            $repo = new ServiceRepository();
            $row = $repo->get($serviceId);
            $current = $row && $row->order_id ? (string) $row->order_id : '';
            if ($raw === $current) {
                return;
            }
            if ($raw === '') {
                $repo->upsert($serviceId, ['order_id' => null]);
                Logger::log('admin_edit', 'Order link cleared by admin', 'warning', $serviceId);
                $_SESSION['lbh_admin_msg'][$serviceId] = ['warning', 'Order link cleared.'];

                return;
            }
            if (!ctype_digit($raw)) {
                $_SESSION['lbh_admin_msg'][$serviceId] = ['danger', 'Order ID must be numeric.'];

                return;
            }
            $orderId = (int) $raw;
            $cfg = new ProductConfig($family, $params);
            $account = (new AccountRepository())->resolve($row && $row->account_id ? (int) $row->account_id : $cfg->accountId);
            if (($other = $repo->orderLinkedElsewhere($orderId, $serviceId, $account->id)) !== null) {
                $_SESSION['lbh_admin_msg'][$serviceId] = ['danger', 'Order ' . $orderId . ' is already linked to service #' . $other . '.'];

                return;
            }
            $api = $account->api(self::$transport);
            $found = null;
            $first = $row && $row->product_key ? (string) $row->product_key : $cfg->productKey;
            foreach (array_unique(array_merge([$first], array_keys(ProductFamily::keys($family)))) as $key) {
                try {
                    $d = $api->details($family, $key, $orderId, $serviceId);
                } catch (\LbHosting\Api\ApiException $ex) {
                    continue; // wrong location for this order - try the next one
                }
                $dKey = strtolower((string) ServiceRepository::pick($d, ['productkey', 'product_key']));
                if ($d && ($dKey === '' || $dKey === $key)) {
                    $found = [$key, $d];
                    break;
                }
            }
            if ($found === null) {
                $_SESSION['lbh_admin_msg'][$serviceId] = ['danger', 'Order ' . $orderId . ' was not found at LogicBoxes for ' . ProductFamily::label($family) . ' - nothing changed.'];

                return;
            }
            $repo->upsert($serviceId, ['order_id' => $orderId, 'product_key' => $found[0], 'family' => $family, 'account_id' => $account->id]);
            $repo->applyDetails($serviceId, $found[1]);
            Logger::log('admin_edit', 'Order ID set to ' . $orderId . ' by admin', 'warning', $serviceId);
            $_SESSION['lbh_admin_msg'][$serviceId] = ['success', 'Linked to order ' . $orderId . '.'];
        } catch (\Throwable $e) {
            Logger::log('admin_edit', 'Saving order link failed: ' . $e->getMessage(), 'error', $serviceId);
            $_SESSION['lbh_admin_msg'][$serviceId] = ['danger', 'Order link not changed: ' . $e->getMessage()];
        }
    }
}
