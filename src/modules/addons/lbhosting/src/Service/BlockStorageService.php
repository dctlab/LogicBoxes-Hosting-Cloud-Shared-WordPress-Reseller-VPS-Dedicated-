<?php

declare(strict_types=1);

namespace LbHosting\Service;

use Illuminate\Database\Capsule\Manager as Capsule;
use LbHosting\Api\HostingApi;
use LbHosting\Support\Logger;
use LbHosting\Support\Settings;

/**
 * VPS Block Storage (the "SAN storage" add-on): one volume per VPS service,
 * sold through the "Block Storage" quantity option (GB). At LogicBoxes a
 * volume is a separate order attached to the VPS order (KB 3283-3295), so the
 * module keeps it in step with the server: created / grown on Create and
 * ChangePackage, renewed, suspended and removed with the VPS.
 *
 *   none -> N GB   blockstorage/us/add.json (10-500 GB, attach-order-id)
 *   N -> bigger    upgrade.json with the FINAL size
 *   N -> smaller   not possible (no shrink call): refused with a message
 *   N -> 0         delete.json, only when add-on removal is enabled
 *
 * US VPS only. The plan id comes from Settings, or is detected from
 * plan-details.json.
 */
final class BlockStorageService
{
    public const MIN_GB = 10;
    public const MAX_GB = 500;

    private HostingApi $api;

    private string $vpsKey;

    private int $serviceId;

    public function __construct(HostingApi $api, string $vpsKey, int $serviceId)
    {
        $this->api = $api;
        $this->vpsKey = $vpsKey;
        $this->serviceId = $serviceId;
    }

    public static function volume(int $serviceId): ?object
    {
        return Capsule::table('mod_lbh_block_volumes')->where('service_id', $serviceId)->first();
    }

    /** @return string|null what changed, for the log */
    public function converge(int $wantGb, int $vpsOrderId, int $customerId, int $months, string $invoiceOption): ?string
    {
        $vol = self::volume($this->serviceId);
        $have = $vol && !empty($vol->order_id) ? (int) $vol->size_gb : 0;
        if ($wantGb === $have) {
            return null;
        }
        if ($wantGb > 0 && ($wantGb < self::MIN_GB || $wantGb > self::MAX_GB)) {
            throw new \RuntimeException(sprintf('Block Storage must be between %d and %d GB (requested %d GB).', self::MIN_GB, self::MAX_GB, $wantGb));
        }
        HostingApi::blockStoragePath($this->vpsKey); // throws for non-US VPS before anything is ordered

        if ($have === 0) {
            $planId = $this->planId();
            $res = $this->api->blockStorageAdd($this->vpsKey, $customerId, $planId, max(1, $months), $wantGb, $vpsOrderId, $invoiceOption, Settings::bool('provider_auto_renew'), $this->serviceId);
            $orderId = ServiceRepository::pick($res, ['entityid', 'orderid', 'order-id']);
            if (!is_numeric($orderId) || (int) $orderId <= 0) {
                throw new \RuntimeException('LogicBoxes accepted the Block Storage order but did not return an order ID. Check the Module Log before trying again.');
            }
            $this->save(['order_id' => (int) $orderId, 'size_gb' => $wantGb, 'status' => 'Active']);
            Logger::log('storage', sprintf('Block Storage volume %d created: %d GB attached to order %d', $orderId, $wantGb, $vpsOrderId), 'info', $this->serviceId);

            return 'Block Storage 0 -> ' . $wantGb . ' GB';
        }
        if ($wantGb > $have) {
            $this->api->blockStorageUpgrade($this->vpsKey, (int) $vol->order_id, $wantGb, $invoiceOption, $this->serviceId);
            $this->save(['size_gb' => $wantGb]);
            Logger::log('storage', sprintf('Block Storage volume %d grown %d -> %d GB', $vol->order_id, $have, $wantGb), 'info', $this->serviceId);

            return sprintf('Block Storage %d -> %d GB', $have, $wantGb);
        }
        if ($wantGb === 0) {
            if (!Settings::bool('addon_removal')) {
                Logger::log('storage', 'Block Storage set to 0 in WHMCS; removal at LogicBoxes is disabled in Settings, so the volume was kept', 'warning', $this->serviceId);

                return null;
            }
            $this->api->blockStorageDelete($this->vpsKey, (int) $vol->order_id, $this->serviceId);
            $this->save(['order_id' => null, 'size_gb' => 0, 'status' => 'Deleted']);
            Logger::log('storage', 'Block Storage volume ' . $vol->order_id . ' deleted', 'warning', $this->serviceId);

            return sprintf('Block Storage %d GB removed', $have);
        }
        throw new \RuntimeException(sprintf('A Block Storage volume cannot be made smaller (%d -> %d GB). Delete it (set 0) and order a new one, after moving the data.', $have, $wantGb));
    }

    /** Renew the volume together with the VPS. Returns false when there is no volume. */
    public function renew(int $months, string $invoiceOption): bool
    {
        $vol = self::volume($this->serviceId);
        if (!$vol || empty($vol->order_id)) {
            return false;
        }
        $this->api->blockStorageRenew($this->vpsKey, (int) $vol->order_id, $months, $invoiceOption, Settings::bool('provider_auto_renew'), $this->serviceId);
        Logger::log('storage', sprintf('Block Storage volume %d renewed for %d months', $vol->order_id, $months), 'info', $this->serviceId);

        return true;
    }

    public function suspend(bool $suspend, string $reason = ''): void
    {
        $vol = self::volume($this->serviceId);
        if (!$vol || empty($vol->order_id)) {
            return;
        }
        $suspend ? $this->api->suspend((int) $vol->order_id, $reason, $this->serviceId) : $this->api->unsuspend((int) $vol->order_id, $this->serviceId);
        $this->save(['status' => $suspend ? 'Suspended' : 'Active']);
    }

    public function delete(): void
    {
        $vol = self::volume($this->serviceId);
        if (!$vol || empty($vol->order_id)) {
            return;
        }
        $this->api->blockStorageDelete($this->vpsKey, (int) $vol->order_id, $this->serviceId);
        $this->save(['order_id' => null, 'size_gb' => 0, 'status' => 'Deleted']);
    }

    private function planId(): int
    {
        $id = (int) Settings::get('block_storage_plan_id');
        if ($id <= 0) {
            $id = (int) ($this->api->blockStoragePlanId() ?? 0);
        }
        if ($id <= 0) {
            throw new \RuntimeException('The Block Storage plan ID is unknown. Enter it under Addons > LogicBoxes Hosting > Settings > Block Storage plan ID (reseller panel: Manage Products and Pricing > Block Storage).');
        }

        return $id;
    }

    /** @param array<string,mixed> $fields */
    private function save(array $fields): void
    {
        $now = date('Y-m-d H:i:s');
        if (self::volume($this->serviceId)) {
            Capsule::table('mod_lbh_block_volumes')->where('service_id', $this->serviceId)->update($fields + ['updated_at' => $now]);
        } else {
            Capsule::table('mod_lbh_block_volumes')->insert($fields + ['service_id' => $this->serviceId, 'created_at' => $now, 'updated_at' => $now]);
        }
    }
}
