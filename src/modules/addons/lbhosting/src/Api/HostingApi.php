<?php

declare(strict_types=1);

namespace LbHosting\Api;

use LbHosting\Catalog\ProductFamily;

/**
 * Typed wrapper over the LogicBoxes API for the eight hosting / server
 * families. Seven use the legacy HTTP API, where every action is
 * "{path}/{action}.json" and path comes from ProductFamily (e.g.
 * "singledomainhosting/linux/in"). WordPress Hosting uses the REST product
 * API ("restapi/product/wordpresshostingusa/order/{id}/..."); each method
 * here picks the right one, so callers never care.
 *
 * Parameter names and HTTP methods are taken from the ResellerClub KB pages
 * referenced in ProductFamily. Where the KB is silent the call is marked
 * PATTERN in docs/API-REFERENCE.md.
 */
final class HostingApi
{
    public const INVOICE_OPTIONS = ['NoInvoice', 'PayInvoice', 'KeepInvoice', 'OnlyAdd'];

    private LogicBoxesClient $client;

    public function __construct(LogicBoxesClient $client)
    {
        $this->client = $client;
    }

    public function client(): LogicBoxesClient
    {
        return $this->client;
    }

    private static function p(string $family, string $key, string $action): string
    {
        return ProductFamily::path($family, $key) . '/' . $action . '.json';
    }

    /** REST base for a product key: "product/wordpresshostingusa". */
    private static function r(string $family, string $key): string
    {
        return 'product/' . ProductFamily::path($family, $key);
    }

    private static function invoice(string $option): string
    {
        if (!in_array($option, self::INVOICE_OPTIONS, true)) {
            throw new ApiException('Invalid invoice option "' . $option . '".');
        }

        return $option;
    }

    // ------------------------------------------------------------ orders

    /**
     * Place an order. $extra carries family-specific parameters:
     *  enable-ssl (SDH/MDH), add-dedicated-ip (Reseller), os + addon[] (VPS/Dedicated).
     *
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    public function add(string $family, string $key, string $domain, int $customerId, int $planId, int $months, string $invoiceOption, bool $autoRenew, array $extra = [], ?int $serviceId = null): array
    {
        if (ProductFamily::isRest($family)) {
            // KB 2843: POST restapi/product/{key}/order, tenure is "noOfMonths", no auto-renew.
            $res = $this->client->rest('POST', self::r($family, $key) . '/order', [
                'domain-name' => $domain,
                'customer-id' => $customerId,
                'plan-id' => $planId,
                'noOfMonths' => $months,
                'invoice-option' => self::invoice($invoiceOption),
            ], 'AddOrder', $serviceId);

            return is_array($res) ? $res : ['entityid' => $res];
        }
        $params = [
            'domain-name' => $domain,
            'customer-id' => $customerId,
            'plan-id' => $planId,
            'months' => $months,
            'invoice-option' => self::invoice($invoiceOption),
        ];
        if (ProductFamily::can($family, 'auto_renew_param')) {
            $params['auto-renew'] = $autoRenew;
        }
        $res = $this->client->post(self::p($family, $key, 'add'), $params + $extra, 'AddOrder', $serviceId);

        return is_array($res) ? $res : ['entityid' => $res];
    }

    /**
     * @param array<string,mixed> $extra e.g. cpanel-blocks (Reseller Linux)
     * @return array<string,mixed>
     */
    public function renew(string $family, string $key, int $orderId, int $months, string $invoiceOption, bool $autoRenew, array $extra = [], ?int $serviceId = null): array
    {
        if (ProductFamily::isRest($family)) {
            // KB 2844: PATCH restapi/product/{key}/order/{id}/tenure/{months}
            $res = $this->client->rest('PATCH', self::r($family, $key) . '/order/' . $orderId . '/tenure/' . $months, ['invoice-option' => self::invoice($invoiceOption), 'auto-renew' => $autoRenew], 'Renew', $serviceId);

            return is_array($res) ? $res : [];
        }
        $params = ['order-id' => $orderId, 'months' => $months, 'invoice-option' => self::invoice($invoiceOption)];
        if (ProductFamily::can($family, 'auto_renew_param')) {
            $params['auto-renew'] = $autoRenew;
        }
        $res = $this->client->post(self::p($family, $key, 'renew'), $params + $extra, 'Renew', $serviceId);

        return is_array($res) ? $res : [];
    }

    /** @return array<string,mixed> */
    public function delete(string $family, string $key, int $orderId, ?int $serviceId = null): array
    {
        $res = ProductFamily::isRest($family)
            ? $this->client->rest('DELETE', self::r($family, $key) . '/order/' . $orderId, [], 'DeleteOrder', $serviceId) // KB 2849
            : $this->client->post(self::p($family, $key, 'delete'), ['order-id' => $orderId], 'DeleteOrder', $serviceId);

        return is_array($res) ? $res : [];
    }

    /**
     * Order details; [] when LogicBoxes says the order does not exist.
     *
     * @return array<string,mixed>
     */
    public function details(string $family, string $key, int $orderId, ?int $serviceId = null): array
    {
        try {
            $res = ProductFamily::isRest($family)
                ? $this->client->rest('GET', self::r($family, $key), ['order-id' => $orderId], 'Details', $serviceId) // KB 2850
                : $this->client->get(self::p($family, $key, 'details'), ['order-id' => $orderId], 'Details', $serviceId);
        } catch (ApiException $e) {
            if ($e->isNotFound()) {
                return [];
            }
            throw $e;
        }
        if (!is_array($res)) {
            return [];
        }
        // REST may answer with a list of orders: take the one asked for.
        if ($res && array_is_list($res)) {
            foreach ($res as $row) {
                if (is_array($row) && (int) ($row['orderid'] ?? $row['entityid'] ?? 0) === $orderId) {
                    return $row;
                }
            }

            return is_array($res[0] ?? null) && count($res) === 1 ? $res[0] : [];
        }

        return $res;
    }

    /** Order id for a domain under one product key, or null. */
    public function orderIdForDomain(string $family, string $key, string $domain, ?int $serviceId = null): ?int
    {
        if (ProductFamily::isRest($family)) {
            return $this->restOrderIdForDomain($family, $key, $domain, $serviceId);
        }
        try {
            $res = $this->client->get(self::p($family, $key, 'orderid'), ['domain-name' => $domain], 'OrderIdLookup', $serviceId);
        } catch (ApiException $e) {
            if ($e->isNotFound() || stripos($e->getMessage(), 'invalid') !== false) {
                return null;
            }
            throw $e;
        }
        if (is_array($res)) {
            $res = $res['orderid'] ?? $res['entityid'] ?? null;
        }

        return (is_numeric($res) && (int) $res > 0) ? (int) $res : null;
    }

    /**
     * REST lookup by domain (KB 2850). The answer may be one order or a list;
     * a deleted order is never returned, and a live one wins over the rest.
     */
    private function restOrderIdForDomain(string $family, string $key, string $domain, ?int $serviceId): ?int
    {
        try {
            $res = $this->client->rest('GET', self::r($family, $key), ['domain-name' => $domain], 'OrderIdLookup', $serviceId);
        } catch (ApiException $e) {
            if ($e->isNotFound() || stripos($e->getMessage(), 'invalid') !== false) {
                return null;
            }
            throw $e;
        }
        if (!is_array($res) || !$res) {
            return null;
        }
        $rows = array_is_list($res) ? $res : [$res];
        $best = null;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int) ($row['orderid'] ?? $row['entityid'] ?? 0);
            $name = strtolower((string) ($row['domainname'] ?? $row['description'] ?? $domain));
            $status = strtolower((string) ($row['currentstatus'] ?? ''));
            if ($id <= 0 || $name !== strtolower($domain) || $status === 'deleted') {
                continue;
            }
            if ($best === null || $status === 'active') {
                $best = $id;
            }
        }

        return $best;
    }

    /**
     * search.json page (Import Manager, Phase 6).
     *
     * @param string[] $statuses
     * @return array<string,mixed>
     */
    public function search(string $family, string $key, int $page, int $perPage, array $statuses = [], ?string $domain = null, ?int $serviceId = null): array
    {
        $params = ['no-of-records' => max(10, min(500, $perPage)), 'page-no' => max(1, $page)];
        if ($statuses) {
            $params['status'] = $statuses;
        }
        if ($domain !== null && $domain !== '') {
            $params['domain-name'] = $domain;
        }
        try {
            $res = ProductFamily::isRest($family)
                ? $this->client->rest('GET', self::r($family, $key) . '/list.json', ['no-of-records' => $params['no-of-records'], 'page-no' => $params['page-no'], 'order-by' => 'orderid'], 'Search', $serviceId) // KB 2955
                : $this->client->get(self::p($family, $key, 'search'), $params, 'Search', $serviceId);
        } catch (ApiException $e) {
            if ($e->isNotFound()) {
                return [];
            }
            throw $e;
        }

        return is_array($res) ? $res : [];
    }

    // ------------------------------------------------- suspend (all products)

    /** orders/suspend.json - every product's own "Suspend" KB page redirects here (KB 1077). */
    public function suspend(int $orderId, string $reason, ?int $serviceId = null, ?string $family = null, ?string $key = null): array
    {
        $reason = trim($reason) !== '' ? mb_substr(trim($reason), 0, 200) : 'Suspended by billing system';
        if ($family !== null && $key !== null && ProductFamily::isRest($family)) {
            // KB 2847: PATCH restapi/product/{key}/order/{id}/suspend
            $res = $this->client->rest('PATCH', self::r($family, $key) . '/order/' . $orderId . '/suspend', ['reason' => $reason], 'Suspend', $serviceId);

            return is_array($res) ? $res : [];
        }
        $res = $this->client->post('orders/suspend.json', ['order-id' => $orderId, 'reason' => $reason], 'Suspend', $serviceId);

        return is_array($res) ? $res : [];
    }

    /** orders/unsuspend.json (KB 1078). */
    public function unsuspend(int $orderId, ?int $serviceId = null, ?string $family = null, ?string $key = null): array
    {
        if ($family !== null && $key !== null && ProductFamily::isRest($family)) {
            // KB 2848: PATCH restapi/product/{key}/order/{id}/unsuspend
            $res = $this->client->rest('PATCH', self::r($family, $key) . '/order/' . $orderId . '/unsuspend', [], 'Unsuspend', $serviceId);

            return is_array($res) ? $res : [];
        }
        $res = $this->client->post('orders/unsuspend.json', ['order-id' => $orderId], 'Unsuspend', $serviceId);

        return is_array($res) ? $res : [];
    }

    // ---------------------------------------------------------- plan change

    /** modify.json - SDH / MDH / Reseller (up or down), VPS (upgrade only). */
    public function modify(string $family, string $key, int $orderId, int $newPlanId, int $months, string $invoiceOption, array $extra = [], ?int $serviceId = null): array
    {
        if (!ProductFamily::can($family, 'plan_change')) {
            throw new ApiException('The LogicBoxes API cannot change the plan of a ' . ProductFamily::label($family) . ' order.');
        }
        $res = $this->client->post(self::p($family, $key, 'modify'), [
            'order-id' => $orderId,
            'new-plan-id' => $newPlanId,
            'months' => max(1, $months),
            'invoice-option' => self::invoice($invoiceOption),
        ] + $extra, 'ModifyPlan', $serviceId);

        return is_array($res) ? $res : [];
    }

    /**
     * modify-pricing.json -> upgradecost, modifiedOrderPrice, currentOrderPrice,
     * remainingmonths, endtime (KB 1264 / 1711 / 1329). Used by the Phase 4
     * provider-priced upgrade flow.
     *
     * @return array<string,mixed>
     */
    public function modifyPricing(string $family, string $key, int $orderId, int $newPlanId, int $months, ?int $serviceId = null): array
    {
        $res = $this->client->get(self::p($family, $key, 'modify-pricing'), ['order-id' => $orderId, 'new-plan-id' => $newPlanId, 'months' => max(1, $months)], 'ModifyPricing', $serviceId);

        return is_array($res) ? $res : [];
    }

    // ------------------------------------------------------------- add-ons

    /** addon/add.json - Cloud (cpu_1/ram_1), VPS, Dedicated, Managed. */
    public function addonAdd(string $family, string $key, int $orderId, string $addon, string $invoiceOption, ?int $serviceId = null): array
    {
        if (!ProductFamily::can($family, 'addon_api')) {
            throw new ApiException(ProductFamily::label($family) . ' has no add-on API.');
        }
        if (!isset(ProductFamily::addons($family, $key)[$addon])) {
            throw new ApiException('Add-on "' . $addon . '" is not available for ' . ProductFamily::keyLabel($family, $key) . '.');
        }
        $res = ProductFamily::isRest($family)
            ? $this->client->rest('POST', self::r($family, $key) . '/order/' . $orderId . '/addon', ['addon' => $addon, 'invoice-option' => self::invoice($invoiceOption)], 'AddonAdd', $serviceId) // KB 2845
            : $this->client->post(self::p($family, $key, 'addon/add'), ['order-id' => $orderId, 'addon' => $addon, 'invoice-option' => self::invoice($invoiceOption)], 'AddonAdd', $serviceId);

        return is_array($res) ? $res : [];
    }

    /** addon/delete.json (addon-id from details). */
    public function addonDelete(string $family, string $key, int $orderId, int $addonId, ?int $serviceId = null): array
    {
        $res = ProductFamily::isRest($family)
            ? $this->client->rest('DELETE', self::r($family, $key) . '/order/' . $orderId . '/addon/' . $addonId, [], 'AddonDelete', $serviceId) // KB 2846
            : $this->client->post(self::p($family, $key, 'addon/delete'), ['order-id' => $orderId, 'addon-id' => $addonId], 'AddonDelete', $serviceId);

        return is_array($res) ? $res : [];
    }

    /** Dedicated IP on Single / Multi Domain hosting = "SSL" (KB 1259/1702). */
    public function enableSsl(string $family, string $key, int $orderId, string $invoiceOption, ?int $serviceId = null): array
    {
        $res = $this->client->post(self::p($family, $key, 'enable-ssl'), ['order-id' => $orderId, 'invoice-option' => self::invoice($invoiceOption)], 'EnableSsl', $serviceId);

        return is_array($res) ? $res : [];
    }

    /**
     * KB 1640/1703. NOTE: the Multi Domain Linux US example omits "/linux/us"
     * (".../multidomainhosting/disable-ssl.json"); every other region and the
     * Single Domain pages use the full path, so the full path is used here.
     */
    public function disableSsl(string $family, string $key, int $orderId, ?int $serviceId = null): array
    {
        $res = $this->client->post(self::p($family, $key, 'disable-ssl'), ['order-id' => $orderId], 'DisableSsl', $serviceId);

        return is_array($res) ? $res : [];
    }

    /** Reseller hosting: one more dedicated IP per call (KB 1323). */
    public function addDedicatedIp(string $family, string $key, int $orderId, string $invoiceOption, ?int $serviceId = null): array
    {
        $res = $this->client->post(self::p($family, $key, 'add-dedicated-ip'), ['order-id' => $orderId, 'invoice-option' => self::invoice($invoiceOption)], 'AddDedicatedIp', $serviceId);

        return is_array($res) ? $res : [];
    }

    /** @param string[] $ips */
    public function deleteDedicatedIps(string $family, string $key, int $orderId, array $ips, ?int $serviceId = null): array
    {
        $res = $this->client->post(self::p($family, $key, 'delete-dedicated-ip'), ['order-id' => $orderId, 'ips' => array_values($ips)], 'DeleteDedicatedIp', $serviceId);

        return is_array($res) ? $res : [];
    }

    /** Reseller hosting WHMCS licence (KB 1372). */
    public function generateLicenseKey(string $family, string $key, int $orderId, string $invoiceOption, ?int $serviceId = null): array
    {
        $res = $this->client->post(self::p($family, $key, 'generate-license-key'), ['order-id' => $orderId, 'invoice-option' => self::invoice($invoiceOption)], 'GenerateLicenseKey', $serviceId);

        return is_array($res) ? $res : [];
    }

    /** @param string[] $keys (KB 3457) */
    public function revokeLicenseKeys(string $family, string $key, int $orderId, array $keys, ?int $serviceId = null): array
    {
        $res = $this->client->post(self::p($family, $key, 'revoke-license-key'), ['order-id' => $orderId, 'license-key' => array_values($keys)], 'RevokeLicenseKey', $serviceId);

        return is_array($res) ? $res : [];
    }

    // ------------------------------------------------------- hosting extras

    public function changePanelPassword(string $family, string $key, int $orderId, string $password, ?int $serviceId = null): array
    {
        if (!ProductFamily::can($family, 'panel_password')) {
            throw new ApiException('The LogicBoxes API cannot change the panel password of a ' . ProductFamily::label($family) . ' order.');
        }
        $res = $this->client->post(self::p($family, $key, 'change-password'), ['order-id' => $orderId, 'new-passwd' => $password], 'ChangePassword', $serviceId);

        return is_array($res) ? $res : [];
    }

    /** @return array<mixed> */
    public function dnsRecords(string $family, string $key, int $orderId, ?int $serviceId = null): array
    {
        $res = $this->client->get(self::p($family, $key, 'dns-record'), ['order-id' => $orderId], 'DnsRecords', $serviceId);

        return is_array($res) ? $res : [];
    }

    public function setShellAccess(string $family, string $key, int $orderId, bool $enable, ?int $serviceId = null): array
    {
        if (!ProductFamily::can($family, 'shell')) {
            throw new ApiException('Shell access is not available for ' . ProductFamily::label($family) . '.');
        }
        $res = $this->client->post(self::p($family, $key, $enable ? 'enable-shell-access' : 'disable-shell-access'), ['order-id' => $orderId], $enable ? 'EnableShell' : 'DisableShell', $serviceId);

        return is_array($res) ? $res : [];
    }

    // -------------------------------------------------- VPS block storage

    /** Block storage exists for US VPS only (KB 3283-3295: every example is blockstorage/us). */
    public static function blockStoragePath(string $vpsKey): string
    {
        if ($vpsKey !== 'virtualserverlinuxus') {
            throw new ApiException('Block Storage is only available for VPS in the US location.');
        }

        return 'blockstorage/us';
    }

    /** blockstorage/us/add.json (KB 3283): a separate order attached to the VPS order. */
    public function blockStorageAdd(string $vpsKey, int $customerId, int $planId, int $months, int $sizeGb, int $attachOrderId, string $invoiceOption, bool $autoRenew, ?int $serviceId = null): array
    {
        $res = $this->client->post(self::blockStoragePath($vpsKey) . '/add.json', [
            'customer-id' => $customerId,
            'months' => $months,
            'plan-id' => $planId,
            'auto-renew' => $autoRenew,
            'invoice-option' => self::invoice($invoiceOption),
            'storage-size-gb' => $sizeGb,
            'attach-order-id' => $attachOrderId,
        ], 'BlockStorageAdd', $serviceId);

        return is_array($res) ? $res : ['entityid' => $res];
    }

    /** upgrade.json takes the FINAL size, not the increment (KB 3295). */
    public function blockStorageUpgrade(string $vpsKey, int $orderId, int $sizeGb, string $invoiceOption, ?int $serviceId = null): array
    {
        $res = $this->client->post(self::blockStoragePath($vpsKey) . '/upgrade.json', ['order-id' => $orderId, 'storage-size-gb' => $sizeGb, 'invoice-option' => self::invoice($invoiceOption)], 'BlockStorageUpgrade', $serviceId);

        return is_array($res) ? $res : [];
    }

    public function blockStorageRenew(string $vpsKey, int $orderId, int $months, string $invoiceOption, bool $autoRenew, ?int $serviceId = null): array
    {
        $res = $this->client->post(self::blockStoragePath($vpsKey) . '/renew.json', ['order-id' => $orderId, 'months' => $months, 'auto-renew' => $autoRenew, 'invoice-option' => self::invoice($invoiceOption)], 'BlockStorageRenew', $serviceId);

        return is_array($res) ? $res : [];
    }

    public function blockStorageDelete(string $vpsKey, int $orderId, ?int $serviceId = null): array
    {
        $res = $this->client->post(self::blockStoragePath($vpsKey) . '/delete.json', ['order-id' => $orderId], 'BlockStorageDelete', $serviceId);

        return is_array($res) ? $res : [];
    }

    /** @return array<string,mixed> */
    public function blockStorageDetails(string $vpsKey, int $orderId, ?int $serviceId = null): array
    {
        try {
            $res = $this->client->get(self::blockStoragePath($vpsKey) . '/details.json', ['order-id' => $orderId], 'BlockStorageDetails', $serviceId);
        } catch (ApiException $e) {
            if ($e->isNotFound()) {
                return [];
            }
            throw $e;
        }

        return is_array($res) ? $res : [];
    }

    /**
     * Block Storage plan id from plan-details.json: the product key is not on
     * the KB Product Keys page, so any key containing "blockstorage" is used
     * (PATTERN). Returns null when none is found - set it in Settings then.
     */
    public function blockStoragePlanId(): ?int
    {
        $res = $this->client->get('products/plan-details.json', [], 'PlanDetails');
        foreach (is_array($res) ? $res : [] as $key => $node) {
            if (!is_array($node) || stripos((string) $key, 'blockstorage') === false) {
                continue;
            }
            $plans = isset($node['plans']) && is_array($node['plans']) ? $node['plans'] : $node;
            foreach ($plans as $id => $plan) {
                if (is_numeric($id)) {
                    return (int) $id;
                }
            }
        }

        return null;
    }

    // -------------------------------------------------------------- catalogue

    /**
     * Plans for one product key from products/plan-details.json (KB 939).
     * Returns plan id => raw plan details.
     *
     * @return array<int,array<string,mixed>>
     */
    public function plans(string $key): array
    {
        $res = $this->client->get('products/plan-details.json', ['product-key' => $key], 'PlanDetails');
        if (!is_array($res)) {
            return [];
        }
        $node = $res[$key] ?? $res;
        if (isset($node['plans']) && is_array($node['plans'])) {
            $node = $node['plans'];
        }
        $out = [];
        foreach ((array) $node as $id => $plan) {
            if (is_numeric($id) && is_array($plan)) {
                $out[(int) $id] = $plan;
            }
        }
        ksort($out);

        return $out;
    }

    /**
     * Plans of several product keys with ONE plan-details.json call (no
     * product-key filter returns every product of the reseller, KB 939).
     *
     * @param string[] $keys
     * @return array<string,array<int,array<string,mixed>>> key => plan id => details
     */
    public function plansForKeys(array $keys): array
    {
        $res = $this->client->get('products/plan-details.json', [], 'PlanDetails');
        if (is_array($res)) {
            // Locations the KB does not list (Business / WordPress Hosting outside one region) show up here by product key.
            $new = ProductFamily::discover(array_map('strval', array_keys($res)));
            foreach ($new as $key) {
                $keys[] = $key;
            }
        }
        $out = [];
        foreach ($keys as $key) {
            $node = is_array($res) ? ($res[$key] ?? null) : null;
            if (!is_array($node)) {
                continue;
            }
            if (isset($node['plans']) && is_array($node['plans'])) {
                $node = $node['plans'];
            }
            foreach ($node as $id => $plan) {
                if (is_numeric($id) && is_array($plan)) {
                    $out[$key][(int) $id] = $plan;
                }
            }
            if (isset($out[$key])) {
                ksort($out[$key]);
            }
        }

        return $out;
    }

    /** @return array<string,mixed> */
    public function resellerDetails(): array
    {
        $res = $this->client->get('resellers/details.json', [], 'ResellerDetails');

        return is_array($res) ? $res : [];
    }

    public static function planName(array $plan, int $id): string
    {
        foreach (['plan_name', 'planname', 'name', 'plan-name'] as $k) {
            if (!empty($plan[$k]) && is_string($plan[$k])) {
                return trim($plan[$k]);
            }
        }

        return 'Plan ' . $id;
    }
}
