<?php

declare(strict_types=1);

namespace LbHosting\Service;

use Illuminate\Database\Capsule\Manager as Capsule;
use LbHosting\Accounts\Account;
use LbHosting\Accounts\AccountRepository;
use LbHosting\Api\ApiException;
use LbHosting\Api\HostingApi;
use LbHosting\Api\HttpTransport;
use LbHosting\Catalog\ProductFamily;
use LbHosting\Support\Logger;
use LbHosting\Support\Settings;
use LbHosting\WHMCS\OptionMap;
use LbHosting\WHMCS\ProductConfig;

/**
 * Provisioning lifecycle for all eight families. Every public method throws on
 * failure; ServerModule::run() turns that into WHMCS's 'success' / 'Error: ...'.
 */
final class Provisioner
{
    private string $family;

    private AccountRepository $accounts;

    private ServiceRepository $services;

    private ?HttpTransport $transport;

    public function __construct(string $family, ?AccountRepository $accounts = null, ?ServiceRepository $services = null, ?HttpTransport $transport = null)
    {
        $this->family = ProductFamily::assert($family);
        $this->accounts = $accounts ?? new AccountRepository();
        $this->services = $services ?? new ServiceRepository();
        $this->transport = $transport;
    }

    public function family(): string
    {
        return $this->family;
    }

    private function label(): string
    {
        return ProductFamily::label($this->family);
    }

    // ------------------------------------------------------------------ create

    /** @param array<string,mixed> $params */
    public function create(array $params): string
    {
        $serviceId = (int) $params['serviceid'];
        $cfg = new ProductConfig($this->family, $params);
        $domain = self::domain($params, ProductFamily::isServer($this->family));
        $row = $this->services->get($serviceId);
        $account = $this->accounts->resolve($row && $row->account_id ? (int) $row->account_id : $cfg->accountId);
        $api = $account->api($this->transport);
        $desired = (new OptionMap($params))->desired($cfg);
        $context = self::context($params);

        // Already provisioned (Create clicked twice, or a previous run failed
        // while adding extras): verify the order, then finish the extras.
        if (!empty($row->order_id)) {
            $key = (string) ($row->product_key ?: $cfg->productKey);
            $details = $api->details($this->family, $key, (int) $row->order_id, $serviceId);
            if (!$details) {
                throw new \RuntimeException('This service is linked to order ' . $row->order_id . ', but LogicBoxes does not know that order. Clear the Order ID on the service to order again.');
            }
            $this->services->applyDetails($serviceId, $details);
            $this->extras($api, $key, (int) $row->order_id, $serviceId, $cfg, $desired, $context);
            Logger::log('create', 'Create re-run: order ' . $row->order_id . ' verified, extras reconciled', 'info', $serviceId);

            return 'success';
        }

        if ($cfg->planId === null) {
            throw new \RuntimeException('No ' . $this->label() . ' plan is selected in this product\'s Module Settings.');
        }
        $key = $cfg->productKey;
        $planId = $cfg->planId;
        $months = $this->tenureMonths($serviceId);
        $extra = $this->orderExtras($cfg, $desired);

        $customerId = (new CustomerResolver($api->client()))->findOrCreate((array) ($params['clientsdetails'] ?? []), $serviceId);
        $this->services->upsert($serviceId, [
            'family' => $this->family,
            'account_id' => $account->id,
            'product_key' => $key,
            'plan_id' => $planId,
            'customer_id' => $customerId,
            'tenure_months' => $months,
            'os' => $extra['os'] ?? null,
        ]);

        // Adopt an order a previous (timed-out) attempt already created for
        // this domain instead of ordering twice.
        $existing = $api->orderIdForDomain($this->family, $key, $domain, $serviceId);
        if ($existing !== null) {
            $this->adopt($api, $account, $key, $existing, $customerId, $serviceId, $domain);
            AddonManager::recordDesired($serviceId, $desired);
            Logger::log('create', 'Adopted existing order ' . $existing . ' for ' . $domain . '. Its add-ons were assumed to match the order - check them, then use "Reconcile Add-ons" if needed.', 'warning', $serviceId);

            return 'success';
        }

        $res = $api->add($this->family, $key, $domain, $customerId, $planId, $months, $cfg->invoiceOption, Settings::bool('provider_auto_renew'), $extra, $serviceId);
        $orderId = ServiceRepository::pick($res, ['entityid', 'orderid', 'order-id', 'order_id']);
        if (!is_numeric($orderId) || (int) $orderId <= 0) {
            $orderId = $api->orderIdForDomain($this->family, $key, $domain, $serviceId);
        }
        if (!is_numeric($orderId) || (int) $orderId <= 0) {
            throw new \RuntimeException('LogicBoxes accepted the order but did not return an order ID. Check the Module Log, then use "Link Existing Order".');
        }
        $orderId = (int) $orderId;
        $this->services->upsert($serviceId, ['order_id' => $orderId, 'provider_status' => (string) ($res['actionstatus'] ?? 'Active')]);
        $this->recordOrderedExtras($serviceId, $extra, $desired);
        Logger::log('create', sprintf('Created %s order %d for %s (%s, plan %d, %d months%s)', $this->label(), $orderId, $domain, $key, $planId, $months, isset($extra['os']) ? ', OS ' . $extra['os'] : ''), 'info', $serviceId);

        try {
            $this->services->applyDetails($serviceId, $api->details($this->family, $key, $orderId, $serviceId));
        } catch (\Throwable $e) {
            // non-fatal: servers are often still being set up
        }

        // Extras the add call cannot carry (2nd+ IPs, Cloud CPU/RAM, Reseller
        // WHMCS licence, manual items). A failure here returns an error, and
        // running Create again only sends what is still missing.
        $this->extras($api, $key, $orderId, $serviceId, $cfg, $desired, $context);

        return 'success';
    }

    /**
     * Family-specific parameters for add.json, sent with the order itself.
     *
     * @param array<string,mixed> $desired
     * @return array<string,mixed>
     */
    private function orderExtras(ProductConfig $cfg, array $desired): array
    {
        $extra = [];
        switch ($this->family) {
            case ProductFamily::SINGLEDOMAIN:
            case ProductFamily::MULTIDOMAIN:
            case ProductFamily::BUSINESS:
                if ($desired['ssl']) {
                    $extra['enable-ssl'] = true;
                }
                break;
            case ProductFamily::RESELLER:
                if ((int) ($desired['dedicated_ips'] ?? 0) > 0) {
                    $extra['add-dedicated-ip'] = true;
                }
                break;
            case ProductFamily::VPS:
            case ProductFamily::DEDICATED:
                $osList = ProductFamily::osList($this->family, $cfg->productKey);
                $os = $desired['os'] ?? null;
                if ($os !== null && $osList && !isset($osList[$os])) {
                    // A newer OS than our list knows: let LogicBoxes validate it.
                    Logger::log('create', 'OS "' . $os . '" is not in the module\'s list for ' . $cfg->productKey . '; sending it anyway', 'warning');
                }
                if ($os === null && ProductFamily::osRequired($this->family, $cfg->productKey)) {
                    throw new \RuntimeException('Choose an operating system: add an "Operating System" configurable option or set "Default OS" in the product\'s Module Settings.');
                }
                if ($os !== null && ($osList || ProductFamily::osRequired($this->family, $cfg->productKey))) {
                    $extra['os'] = $os;
                }
                $codes = [];
                foreach ((array) $desired['addons'] as $code => $qty) {
                    if ((int) $qty > 0) {
                        $codes[] = (string) $code;
                    }
                }
                if ($codes) {
                    $extra['addon'] = $codes; // one of each; more units via addon/add.json
                }
                break;
        }

        return $extra;
    }

    /**
     * After the order exists, record what add.json already carried so the
     * reconcile step only sends the remainder.
     *
     * @param array<string,mixed> $extra
     * @param array<string,mixed> $desired
     */
    private function recordOrderedExtras(int $serviceId, array $extra, array $desired): void
    {
        if (!empty($extra['enable-ssl'])) {
            AddonManager::record($serviceId, 'ssl', 1);
        }
        if (!empty($extra['add-dedicated-ip'])) {
            AddonManager::record($serviceId, 'dedicated_ip', 1);
        }
        foreach ((array) ($extra['addon'] ?? []) as $code) {
            AddonManager::record($serviceId, 'addon:' . $code, 1);
        }
    }

    /**
     * Converge everything beyond the plan on the service's options: provider
     * add-ons, manual items and (VPS) the Block Storage volume.
     *
     * @param array<string,mixed> $desired
     * @param array<string,mixed> $context
     * @return string[] changes made
     */
    private function extras(HostingApi $api, string $key, int $orderId, int $serviceId, ProductConfig $cfg, array $desired, array $context): array
    {
        $changes = (new AddonManager($api, $this->family, $key, $orderId, $serviceId, $cfg->invoiceOption))->reconcile($desired, $context);
        if ($this->family === ProductFamily::VPS && $desired['block_storage'] !== null) {
            $row = $this->services->get($serviceId);
            $months = self::remainingMonths(!empty($row->expires_at) ? (string) $row->expires_at : null) ?? max(1, (int) ($row->tenure_months ?? 1));
            $change = (new BlockStorageService($api, $key, $serviceId))->converge((int) $desired['block_storage'], $orderId, (int) ($row->customer_id ?? 0), $months, $cfg->invoiceOption);
            if ($change !== null) {
                $changes[] = $change;
            }
        }

        return $changes;
    }

    /** Keep a VPS's Block Storage volume in the same state as the server; never fails the main action. */
    private function withVolume(HostingApi $api, object $row, int $serviceId, string $what, callable $fn): void
    {
        if ($this->family !== ProductFamily::VPS) {
            return;
        }
        try {
            $fn(new BlockStorageService($api, (string) $row->product_key, $serviceId));
        } catch (\Throwable $e) {
            Logger::log('storage', 'Block Storage ' . $what . ' failed: ' . $e->getMessage() . ' - fix it in the reseller panel.', 'error', $serviceId);
        }
    }

    private function adopt(HostingApi $api, Account $account, string $key, int $orderId, int $customerId, int $serviceId, string $domain): void
    {
        $other = $this->services->orderLinkedElsewhere($orderId, $serviceId, $account->id);
        if ($other !== null) {
            throw new \RuntimeException('A ' . $this->label() . ' order for ' . $domain . ' already exists and is linked to service #' . $other . '.');
        }
        $details = $api->details($this->family, $key, $orderId, $serviceId);
        $owner = ServiceRepository::pick($details, ['customerid', 'customer-id', 'customer_id']);
        if (is_numeric($owner) && (int) $owner !== $customerId) {
            throw new \RuntimeException('A ' . $this->label() . ' order for ' . $domain . ' already exists at LogicBoxes under a different customer (order ' . $orderId . ').');
        }
        $status = strtolower((string) ServiceRepository::pick($details, ['currentstatus', 'status']));
        if ($status === 'deleted') {
            throw new \RuntimeException('The existing ' . $this->label() . ' order for ' . $domain . ' (' . $orderId . ') is deleted at LogicBoxes. Use another domain/hostname.');
        }
        $this->services->upsert($serviceId, ['order_id' => $orderId]);
        $this->services->applyDetails($serviceId, $details);
    }

    // ------------------------------------------------------- suspend/unsuspend

    /** @param array<string,mixed> $params */
    public function suspend(array $params): void
    {
        [$row, $api] = $this->linked($params);
        $api->suspend((int) $row->order_id, (string) ($params['suspendreason'] ?? ''), (int) $params['serviceid'], $this->family, (string) $row->product_key);
        $this->services->upsert((int) $params['serviceid'], ['provider_status' => 'Suspended']);
        $this->withVolume($api, $row, (int) $params['serviceid'], 'suspend', static fn (BlockStorageService $v) => $v->suspend(true, (string) ($params['suspendreason'] ?? '')));
        Logger::log('suspend', 'Suspended order ' . $row->order_id, 'info', (int) $params['serviceid']);
    }

    /** @param array<string,mixed> $params */
    public function unsuspend(array $params): void
    {
        [$row, $api] = $this->linked($params);
        $api->unsuspend((int) $row->order_id, (int) $params['serviceid'], $this->family, (string) $row->product_key);
        $this->services->upsert((int) $params['serviceid'], ['provider_status' => 'Active']);
        $this->withVolume($api, $row, (int) $params['serviceid'], 'unsuspend', static fn (BlockStorageService $v) => $v->suspend(false));
        Logger::log('unsuspend', 'Unsuspended order ' . $row->order_id, 'info', (int) $params['serviceid']);
    }

    // ---------------------------------------------------------------- terminate

    /** @param array<string,mixed> $params */
    public function terminate(array $params): void
    {
        $serviceId = (int) $params['serviceid'];
        $mode = Settings::get('terminate_mode');
        $row = $this->services->get($serviceId);
        if (empty($row->order_id)) {
            Logger::log('terminate', 'Terminate: no linked order, nothing to do at provider', 'info', $serviceId);

            return;
        }
        if ($mode === 'local') {
            Logger::log('terminate', 'Terminate (local only): order ' . $row->order_id . ' left untouched at provider', 'warning', $serviceId);
            $this->services->upsert($serviceId, ['provider_status' => 'Terminated (local)']);

            return;
        }
        [, $api] = $this->linked($params);
        if ($mode === 'delete') {
            $api->delete($this->family, (string) $row->product_key, (int) $row->order_id, $serviceId);
            $this->services->upsert($serviceId, ['provider_status' => 'Deleted']);
            $this->withVolume($api, $row, $serviceId, 'delete', static fn (BlockStorageService $v) => $v->delete());
            Logger::log('terminate', 'Deleted order ' . $row->order_id . ' at provider', 'info', $serviceId);

            return;
        }
        // Default: suspend and let it expire - recoverable if WHMCS terminated
        // automatically by mistake (servers keep their data until expiry).
        try {
            $api->suspend((int) $row->order_id, 'Terminated in billing system', $serviceId, $this->family, (string) $row->product_key);
        } catch (ApiException $e) {
            if (stripos($e->getMessage(), 'suspend') === false) {
                throw $e;
            }
        }
        $this->services->upsert($serviceId, ['provider_status' => 'Suspended (terminated)']);
        $this->withVolume($api, $row, $serviceId, 'suspend', static fn (BlockStorageService $v) => $v->suspend(true, 'Terminated in billing system'));
        Logger::log('terminate', 'Terminated: order ' . $row->order_id . ' suspended at provider', 'info', $serviceId);
    }

    // ---------------------------------------------------- change package

    /**
     * WHMCS calls this after a product upgrade/downgrade or a configurable
     * option change (and when an admin clicks "Change Package"). Applies a
     * plan change, then converges add-ons on the service's options.
     *
     * @param array<string,mixed> $params
     */
    public function changePackage(array $params): string
    {
        [$row, $api] = $this->linked($params);
        $serviceId = (int) $params['serviceid'];
        $cfg = new ProductConfig($this->family, $params);
        $key = (string) $row->product_key;
        $orderId = (int) $row->order_id;

        $details = $api->details($this->family, $key, $orderId, $serviceId);
        if (!$details) {
            throw new \RuntimeException('Order ' . $orderId . ' was not found at LogicBoxes.');
        }
        $this->services->applyDetails($serviceId, $details);
        $row = $this->services->get($serviceId) ?? $row;

        $planChanged = false;
        if ($cfg->planId !== null && (int) $row->plan_id > 0 && $cfg->planId !== (int) $row->plan_id) {
            $this->changePlan($api, $row, $cfg, $serviceId);
            $planChanged = true;
        }

        $desired = (new OptionMap($params))->desired($cfg);
        // An OS option on a running server is informational: reinstalls are not part of the order API.
        $this->extras($api, $key, $orderId, $serviceId, $cfg, $desired, self::context($params));

        return $planChanged ? 'plan_changed' : 'success';
    }

    private function changePlan(HostingApi $api, object $row, ProductConfig $cfg, int $serviceId): void
    {
        $oldPlan = (int) $row->plan_id;
        $newPlan = (int) $cfg->planId;
        if (!ProductFamily::can($this->family, 'plan_change')) {
            throw new \RuntimeException('The LogicBoxes API cannot change the plan of an existing ' . $this->label() . ' order (plan ' . $oldPlan . ' -> ' . $newPlan . '). Change it in the reseller panel, then use "Sync from Provider".');
        }
        if ($cfg->productKey !== (string) $row->product_key) {
            throw new \RuntimeException(sprintf('Cannot move an order between %s and %s: only plans of the same location and OS can be changed.', ProductFamily::keyLabel($this->family, (string) $row->product_key), ProductFamily::keyLabel($this->family, $cfg->productKey)));
        }
        $months = Settings::get('upgrade_months') === 'tenure'
            ? max(1, (int) ($row->tenure_months ?? 1))
            : (self::remainingMonths($row->expires_at ? (string) $row->expires_at : null) ?? max(1, (int) ($row->tenure_months ?? 1)));

        try {
            $price = $api->modifyPricing($this->family, (string) $row->product_key, (int) $row->order_id, $newPlan, $months, $serviceId);
            if ($price) {
                Logger::log('plan', sprintf('LogicBoxes price for plan %d -> %d: upgradecost %s (remaining %s months)', $oldPlan, $newPlan, $price['upgradecost'] ?? '?', $price['remainingmonths'] ?? '?'), 'info', $serviceId);
                if (isset($price['remainingmonths']) && is_numeric($price['remainingmonths']) && (int) $price['remainingmonths'] > 0 && Settings::get('upgrade_months') !== 'tenure') {
                    $months = (int) $price['remainingmonths'];
                }
            }
        } catch (\Throwable $e) {
            // informational; VPS has no modify-pricing call
        }
        try {
            $api->modify($this->family, (string) $row->product_key, (int) $row->order_id, $newPlan, $months, $cfg->invoiceOption, [], $serviceId);
        } catch (ApiException $e) {
            if (!ProductFamily::can($this->family, 'downgrade')) {
                throw new \RuntimeException('Plan change refused by LogicBoxes: ' . $e->getMessage() . ' (' . $this->label() . ' supports upgrades only; HDD plans cannot be upgraded.)', 0, $e);
            }
            throw $e;
        }
        $this->services->upsert($serviceId, ['plan_id' => $newPlan, 'plan_name' => null, 'plan_changed_at' => date('Y-m-d H:i:s')]);
        Logger::log('plan', sprintf('Plan changed %d -> %d (%d months)', $oldPlan, $newPlan, $months), 'info', $serviceId);
    }

    /** Whole months left until expiry (rounded up), or null when unknown/past. */
    public static function remainingMonths(?string $expiresAt, ?int $now = null): ?int
    {
        if ($expiresAt === null || $expiresAt === '') {
            return null;
        }
        $end = strtotime($expiresAt);
        $now = $now ?? time();
        if ($end === false || $end <= $now) {
            return null;
        }

        return max(1, (int) ceil(($end - $now) / (30.44 * 86400) - 0.02));
    }

    /** Admin "Reconcile Add-ons": converge extras without touching the plan. @param array<string,mixed> $params */
    public function reconcile(array $params): void
    {
        [$row, $api] = $this->linked($params);
        $cfg = new ProductConfig($this->family, $params);
        $changes = $this->extras($api, (string) $row->product_key, (int) $row->order_id, (int) $params['serviceid'], $cfg, (new OptionMap($params))->desired($cfg), self::context($params));
        if (!$changes) {
            Logger::log('addons', 'Reconcile: add-ons already match the configurable options', 'info', (int) $params['serviceid']);
        }
    }

    // ------------------------------------------------------------------- renew

    /**
     * Renew at the provider for one billing period. Idempotent per period
     * (unique key) and refuses a second renewal within 20 hours unless $force.
     *
     * @param array<string,mixed> $params
     */
    public function renew(array $params, string $source, bool $force = false): string
    {
        [$row, $api] = $this->linked($params);
        $serviceId = (int) $params['serviceid'];
        $cfg = new ProductConfig($this->family, $params);
        $service = Capsule::table('tblhosting')->where('id', $serviceId)->first();
        $months = ProductConfig::monthsForCycle((string) ($service->billingcycle ?? ''));
        if ($months <= 0) {
            throw new \RuntimeException('This service\'s billing cycle cannot be renewed at the provider.');
        }
        $periodKey = 'due:' . (string) ($service->nextduedate ?? date('Y-m-d'));

        if (!$force && $row->created_at && strtotime((string) $row->created_at) > time() - 20 * 3600) {
            Logger::log('renew', 'Renew skipped (' . $source . '): order was created less than 20 hours ago', 'info', $serviceId);

            return 'skipped';
        }
        if (!$force && Settings::bool('renew_dedupe')) {
            $recent = Capsule::table('mod_lbh_renewals')
                ->where('service_id', $serviceId)
                ->whereIn('status', ['renewed', 'processing'])
                ->where(function ($q) use ($periodKey) {
                    $q->where('period_key', $periodKey)
                        ->orWhere('updated_at', '>=', date('Y-m-d H:i:s', time() - 20 * 3600));
                })->first();
            if ($recent) {
                Logger::log('renew', 'Renew skipped (' . $source . '): already renewed for this period (renewal #' . $recent->id . ')', 'info', $serviceId);

                return 'skipped';
            }
        }
        if ($force) {
            $periodKey .= ':f' . time();
        }
        $periodKey = substr($periodKey, 0, 32);

        Capsule::table('mod_lbh_renewals')->where('service_id', $serviceId)->where('period_key', $periodKey)->where('status', 'failed')->delete();
        try {
            $claimId = Capsule::table('mod_lbh_renewals')->insertGetId([
                'service_id' => $serviceId, 'period_key' => $periodKey, 'source' => $source, 'status' => 'processing',
                'months' => $months, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            Logger::log('renew', 'Renew skipped (' . $source . '): period already claimed', 'info', $serviceId);

            return 'skipped';
        }

        $extra = [];
        $blocks = AddonManager::recorded($serviceId, 'cpanel_blocks');
        if ($this->family === ProductFamily::RESELLER && $blocks > 0) {
            $extra['cpanel-blocks'] = $blocks; // renew the extra cPanel accounts with the order (PATTERN)
        }
        if (ProductFamily::can($this->family, 'ssl_order_only') && AddonManager::recorded($serviceId, 'ssl') > 0) {
            $extra['enable-ssl'] = true; // Business Hosting: the dedicated IP is carried on renew.json (KB 3549)
        }
        try {
            $api->renew($this->family, (string) $row->product_key, (int) $row->order_id, $months, $cfg->invoiceOption, Settings::bool('provider_auto_renew'), $extra, $serviceId);
        } catch (\Throwable $e) {
            Capsule::table('mod_lbh_renewals')->where('id', $claimId)->update(['status' => 'failed', 'message' => substr($e->getMessage(), 0, 1000), 'updated_at' => date('Y-m-d H:i:s')]);
            Logger::log('renew', 'Provider renewal failed (' . $source . '): ' . $e->getMessage(), 'error', $serviceId);
            throw $e;
        }
        Capsule::table('mod_lbh_renewals')->where('id', $claimId)->update(['status' => 'renewed', 'updated_at' => date('Y-m-d H:i:s')]);
        Logger::log('renew', sprintf('Renewed order %d for %d months (%s)', $row->order_id, $months, $source), 'info', $serviceId);
        $this->withVolume($api, $row, $serviceId, 'renewal', static fn (BlockStorageService $v) => $v->renew($months, $cfg->invoiceOption));
        try {
            $this->services->applyDetails($serviceId, $api->details($this->family, (string) $row->product_key, (int) $row->order_id, $serviceId));
        } catch (\Throwable $e) {
        }

        return 'renewed';
    }

    // -------------------------------------------------------- sync / link

    /** @param array<string,mixed> $params @return array<string,mixed> raw details */
    public function sync(array $params): array
    {
        [$row, $api] = $this->linked($params);
        $details = $api->details($this->family, (string) $row->product_key, (int) $row->order_id, (int) $params['serviceid']);
        if (!$details) {
            throw new \RuntimeException('Order ' . $row->order_id . ' was not found at LogicBoxes.');
        }
        $this->services->applyDetails((int) $params['serviceid'], $details);

        return $details;
    }

    /**
     * Admin "Link Existing Order": find the order for the service's domain,
     * trying the product's own key first, then every other key of the family.
     *
     * @param array<string,mixed> $params
     */
    public function linkByDomain(array $params): int
    {
        $serviceId = (int) $params['serviceid'];
        $cfg = new ProductConfig($this->family, $params);
        $row = $this->services->get($serviceId);
        $account = $this->accounts->resolve($row && $row->account_id ? (int) $row->account_id : $cfg->accountId);
        $api = $account->api($this->transport);
        $domain = self::domain($params, ProductFamily::isServer($this->family));
        $keys = array_unique(array_merge([$cfg->productKey], array_keys(ProductFamily::keys($this->family))));
        $lastError = null;
        foreach ($keys as $key) {
            try {
                $orderId = $api->orderIdForDomain($this->family, $key, $domain, $serviceId);
            } catch (ApiException $e) {
                $lastError = $e->getMessage(); // e.g. a location not enabled on this account
                continue;
            }
            if ($orderId === null) {
                continue;
            }
            $other = $this->services->orderLinkedElsewhere($orderId, $serviceId, $account->id);
            if ($other !== null) {
                throw new \RuntimeException('Order ' . $orderId . ' is already linked to service #' . $other . '.');
            }
            $this->services->upsert($serviceId, ['family' => $this->family, 'account_id' => $account->id, 'product_key' => $key, 'order_id' => $orderId]);
            $this->services->applyDetails($serviceId, $api->details($this->family, $key, $orderId, $serviceId));
            Logger::log('link', 'Linked to existing order ' . $orderId . ' (' . $key . ')', 'info', $serviceId);

            return $orderId;
        }
        throw new \RuntimeException('No ' . $this->label() . ' order found for ' . $domain . ' on account "' . $account->name . '" (searched ' . count($keys) . ' locations' . ($lastError !== null ? '; last API error: ' . $lastError : '') . ').');
    }

    /** WHMCS ChangePassword (hosting families). @param array<string,mixed> $params */
    public function changePassword(array $params): void
    {
        [$row, $api] = $this->linked($params);
        $password = (string) ($params['password'] ?? '');
        if (strlen($password) < 8) {
            throw new \RuntimeException('The new password must be at least 8 characters.');
        }
        $api->changePanelPassword($this->family, (string) $row->product_key, (int) $row->order_id, $password, (int) $params['serviceid']);
        Logger::log('password', 'Control panel password changed', 'info', (int) $params['serviceid']);
    }

    // ----------------------------------------------------------------- helpers

    /** @param array<string,mixed> $params @return array{0:object,1:HostingApi} */
    public function linked(array $params): array
    {
        $serviceId = (int) $params['serviceid'];
        $row = $this->services->get($serviceId);
        if (empty($row->order_id)) {
            throw new \RuntimeException('This service is not linked to a ' . $this->label() . ' order yet. Run Create, or use "Link Existing Order".');
        }
        if (empty($row->product_key)) {
            $cfg = new ProductConfig($this->family, $params);
            $this->services->upsert($serviceId, ['product_key' => $cfg->productKey, 'family' => $this->family]);
            $row = $this->services->get($serviceId) ?? $row;
        }
        $account = $this->accounts->resolve($row->account_id ? (int) $row->account_id : (new ProductConfig($this->family, $params))->accountId);

        return [$row, $account->api($this->transport)];
    }

    public function account(?int $id): Account
    {
        return $this->accounts->resolve($id);
    }

    private function tenureMonths(int $serviceId): int
    {
        $cycle = (string) Capsule::table('tblhosting')->where('id', $serviceId)->value('billingcycle');
        $months = ProductConfig::monthsForCycle($cycle);
        if ($months <= 0) {
            throw new \RuntimeException('Billing cycle "' . $cycle . '" is not supported for ' . $this->label() . '. Use Monthly, Quarterly, Semi-Annually, Annually, Biennially or Triennially.');
        }

        return $months;
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    private static function context(array $params): array
    {
        return [
            'domain' => (string) ($params['domain'] ?? ''),
            'client_id' => (int) ($params['userid'] ?? ($params['clientsdetails']['userid'] ?? 0)),
        ];
    }

    /**
     * Domain (hosting) or hostname (servers) the order is placed for.
     *
     * @param array<string,mixed> $params
     */
    public static function domain(array $params, bool $hostname = false): string
    {
        $d = strtolower(trim((string) ($params['domain'] ?? '')));
        $d = (string) preg_replace('#^https?://#', '', $d);
        $d = explode('/', $d)[0];
        if (!$hostname) {
            $d = (string) preg_replace('#^www\.#', '', $d);
        }
        $d = rtrim($d, '.');
        if ($d === '' || !preg_match('/^(?=.{3,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $d)) {
            throw new \RuntimeException($hostname
                ? 'The service has no valid hostname. Enter a fully qualified hostname (e.g. server1.example.com) in the service Domain/Hostname field.'
                : 'The service has no valid domain name. Enter the customer\'s domain in the service Domain field.');
        }

        return $d;
    }
}
