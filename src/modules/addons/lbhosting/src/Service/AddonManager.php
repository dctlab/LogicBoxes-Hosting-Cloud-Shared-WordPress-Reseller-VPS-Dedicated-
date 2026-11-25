<?php

declare(strict_types=1);

namespace LbHosting\Service;

use Illuminate\Database\Capsule\Manager as Capsule;
use LbHosting\Api\HostingApi;
use LbHosting\Catalog\ProductFamily;
use LbHosting\Support\Logger;
use LbHosting\Support\Settings;

/**
 * Converges a service's provider-side extras on what the customer ordered
 * (OptionMap::desired()), sending only the difference.
 *
 * mod_lbh_addons records what the module itself has applied, per add-on code.
 * That record - not the provider's add-on list - is the baseline, because
 * plans include free add-ons (e.g. "free_quantity": 2 IPs, KB 2061) that must
 * never be counted or deleted as if the customer had bought them.
 *
 * Increases call the add API once per unit and update the record after every
 * success, so a failure halfway leaves an accurate record and a re-run only
 * sends what is still missing. Decreases need the provider's add-on ids /
 * IPs / licence keys, read from a fresh details.json; only add-ons that
 * LogicBoxes marks as paid are removed (newest first), and anything that
 * cannot be identified safely is refused with a clear message.
 */
final class AddonManager
{
    private HostingApi $api;

    private string $family;

    private string $key;

    private int $orderId;

    private int $serviceId;

    private string $invoiceOption;

    /** @var array<string,mixed>|null lazily fetched details */
    private ?array $details = null;

    /** @var string[] */
    private array $errors = [];

    /** @var string[] */
    private array $changes = [];

    public function __construct(HostingApi $api, string $family, string $key, int $orderId, int $serviceId, string $invoiceOption)
    {
        $this->api = $api;
        $this->family = $family;
        $this->key = $key;
        $this->orderId = $orderId;
        $this->serviceId = $serviceId;
        $this->invoiceOption = $invoiceOption;
    }

    // ------------------------------------------------------------- records

    public static function recorded(int $serviceId, string $addon): int
    {
        return (int) (Capsule::table('mod_lbh_addons')->where('service_id', $serviceId)->where('addon', $addon)->value('quantity') ?? 0);
    }

    public static function recordedValue(int $serviceId, string $addon): ?string
    {
        $row = Capsule::table('mod_lbh_addons')->where('service_id', $serviceId)->where('addon', $addon)->first();
        if (!$row) {
            return null;
        }
        $v = json_decode((string) ($row->provider_ids ?? ''), true);

        return is_array($v) && array_key_exists('value', $v) ? (string) $v['value'] : (string) $row->quantity;
    }

    public static function record(int $serviceId, string $addon, int $quantity, ?array $meta = null): void
    {
        $vals = ['quantity' => max(0, $quantity), 'updated_at' => date('Y-m-d H:i:s')];
        if ($meta !== null) {
            $vals['provider_ids'] = json_encode($meta, JSON_UNESCAPED_SLASHES);
        }
        Capsule::table('mod_lbh_addons')->updateOrInsert(['service_id' => $serviceId, 'addon' => $addon], $vals);
    }

    /**
     * Assume the provider already matches $desired (adopted / imported orders)
     * without calling the API.
     *
     * @param array<string,mixed> $desired
     */
    public static function recordDesired(int $serviceId, array $desired): void
    {
        foreach ((array) $desired['addons'] as $code => $qty) {
            self::record($serviceId, 'addon:' . $code, (int) $qty);
        }
        foreach (['ssl' => 'ssl', 'dedicated_ips' => 'dedicated_ip', 'whmcs' => 'whmcs', 'cpanel_blocks' => 'cpanel_blocks'] as $k => $rec) {
            if ($desired[$k] !== null) {
                self::record($serviceId, $rec, (int) $desired[$k]);
            }
        }
        foreach ((array) $desired['manual'] as $item => $value) {
            self::record($serviceId, 'manual:' . $item, is_numeric($value) ? (int) $value : ($value === '' ? 0 : 1), ['value' => (string) $value]);
        }
    }

    /** @return array<string,int> addon => quantity */
    public static function all(int $serviceId): array
    {
        $out = [];
        foreach (Capsule::table('mod_lbh_addons')->where('service_id', $serviceId)->get() as $r) {
            $out[(string) $r->addon] = (int) $r->quantity;
        }

        return $out;
    }

    // ----------------------------------------------------------- converge

    /**
     * @param array<string,mixed> $desired OptionMap::desired()
     * @param array<string,mixed> $context domain, client_id for manual tasks
     * @return string[] human-readable list of changes made
     */
    public function reconcile(array $desired, array $context): array
    {
        $this->errors = [];
        $this->changes = [];

        foreach ((array) $desired['addons'] as $code => $want) {
            $this->converge('addon:' . $code, (int) $want, fn () => $this->api->addonAdd($this->family, $this->key, $this->orderId, (string) $code, $this->invoiceOption, $this->serviceId), fn (int $n) => $this->removeProviderAddons((string) $code, $n));
        }
        if ($desired['ssl'] !== null && ProductFamily::can($this->family, 'ssl_order_only')) {
            // Business Hosting: enable-ssl exists only on add.json / renew.json
            // (KB 3547/3549). A change on a running order is done by hand.
            $have = self::recorded($this->serviceId, 'ssl');
            $want = $desired['ssl'] ? 1 : 0;
            if ($want !== $have) {
                $result = (new ManualFulfilment())->request($this->serviceId, $this->family, 'dedicated_ip', (string) $want, (string) $have, $context + ['order_id' => $this->orderId]);
                if ($result === 'created') {
                    $this->changes[] = sprintf('manual task: dedicated IP %d -> %d', $have, $want);
                }
                self::record($this->serviceId, 'ssl', $want);
            }
        } elseif ($desired['ssl'] !== null) {
            $this->converge('ssl', $desired['ssl'] ? 1 : 0, fn () => $this->api->enableSsl($this->family, $this->key, $this->orderId, $this->invoiceOption, $this->serviceId), function (int $n) {
                $this->api->disableSsl($this->family, $this->key, $this->orderId, $this->serviceId);

                return 1;
            });
        }
        if ($desired['dedicated_ips'] !== null) {
            $this->converge('dedicated_ip', (int) $desired['dedicated_ips'], fn () => $this->api->addDedicatedIp($this->family, $this->key, $this->orderId, $this->invoiceOption, $this->serviceId), fn (int $n) => $this->removeDedicatedIps($n));
        }
        if ($desired['whmcs'] !== null) {
            $this->converge('whmcs', $desired['whmcs'] ? 1 : 0, fn () => $this->api->generateLicenseKey($this->family, $this->key, $this->orderId, $this->invoiceOption, $this->serviceId), fn (int $n) => $this->revokeLicences($n));
        }
        if ($desired['cpanel_blocks'] !== null) {
            $this->convergeCpanelBlocks((int) $desired['cpanel_blocks']);
        }
        foreach ((array) $desired['manual'] as $item => $value) {
            $this->manual((string) $item, (string) $value, $context);
        }
        if (!empty($desired['unknown'])) {
            $this->errors[] = 'Unrecognised value in configurable option(s): ' . implode(', ', array_unique($desired['unknown'])) . '. Name the sub-options "code|Friendly name" (see the README).';
        }

        if ($this->changes) {
            Logger::log('addons', 'Add-ons: ' . implode('; ', $this->changes), 'info', $this->serviceId);
        }
        if ($this->errors) {
            throw new \RuntimeException(implode(' ', $this->errors));
        }

        return $this->changes;
    }

    /**
     * @param callable():mixed $addOne
     * @param callable(int):int $remove removes up to n units, returns how many it removed
     */
    private function converge(string $record, int $want, callable $addOne, callable $remove): void
    {
        $have = self::recorded($this->serviceId, $record);
        if ($want === $have) {
            return;
        }
        if ($want > $have) {
            for ($i = $have; $i < $want; $i++) {
                try {
                    $addOne();
                } catch (\Throwable $e) {
                    $this->errors[] = sprintf('Adding %s failed after %d of %d: %s', $this->labelFor($record), $i - $have, $want - $have, $e->getMessage());

                    return;
                }
                self::record($this->serviceId, $record, $i + 1);
            }
            $this->changes[] = sprintf('%s %d -> %d', $this->labelFor($record), $have, $want);

            return;
        }
        if (!Settings::bool('addon_removal')) {
            Logger::log('addons', sprintf('%s lowered %d -> %d in WHMCS; removal at LogicBoxes is disabled in Settings, so nothing was removed', $this->labelFor($record), $have, $want), 'warning', $this->serviceId);
            self::record($this->serviceId, $record, $want);

            return;
        }
        try {
            $removed = $remove($have - $want);
        } catch (\Throwable $e) {
            $this->errors[] = sprintf('Removing %s failed: %s', $this->labelFor($record), $e->getMessage());

            return;
        }
        self::record($this->serviceId, $record, $have - $removed);
        $this->changes[] = sprintf('%s %d -> %d', $this->labelFor($record), $have, $have - $removed);
        if ($removed < $have - $want) {
            $this->errors[] = sprintf('Only %d of %d %s could be removed safely at LogicBoxes - remove the rest in the reseller panel, then use "Reconcile Add-ons".', $removed, $have - $want, $this->labelFor($record));
        }
    }

    private function removeProviderAddons(string $code, int $n): int
    {
        $candidates = array_values(array_filter(ServiceRepository::providerAddons($this->details()), static fn ($a) => $a['name'] === $code && $a['id'] !== null));
        $paid = array_values(array_filter($candidates, static fn ($a) => $a['paid'] === true));
        if (!$paid) {
            $unknown = array_filter($candidates, static fn ($a) => $a['paid'] === null);
            throw new \RuntimeException($unknown
                ? 'LogicBoxes does not say which "' . $code . '" add-ons are paid, so none were removed automatically'
                : 'no paid "' . $code . '" add-on found on order ' . $this->orderId);
        }
        usort($paid, static fn ($a, $b) => ($b['created'] ?? $b['id']) <=> ($a['created'] ?? $a['id']));
        $done = 0;
        foreach (array_slice($paid, 0, $n) as $a) {
            $this->api->addonDelete($this->family, $this->key, $this->orderId, (int) $a['id'], $this->serviceId);
            $done++;
        }
        $this->details = null;

        return $done;
    }

    private function removeDedicatedIps(int $n): int
    {
        $ips = ServiceRepository::dedicatedIps($this->details());
        if (!$ips) {
            throw new \RuntimeException('the order details do not list its dedicated IPs');
        }
        $remove = array_slice($ips, -$n);
        $this->api->deleteDedicatedIps($this->family, $this->key, $this->orderId, $remove, $this->serviceId);
        $this->details = null;

        return count($remove);
    }

    private function revokeLicences(int $n): int
    {
        $keys = ServiceRepository::licenseKeys($this->details());
        if (!$keys) {
            throw new \RuntimeException('the order details do not show a WHMCS licence key');
        }
        $remove = array_slice($keys, -$n);
        $this->api->revokeLicenseKeys($this->family, $this->key, $this->orderId, $remove, $this->serviceId);
        $this->details = null;

        return count($remove);
    }

    /**
     * Reseller Linux extra cPanel accounts: cpanel-blocks is an additive unit
     * on modify.json (1 block = 10 accounts, value > 0, KB 1322). Increases are
     * sent as a same-plan modify (PATTERN - confirm on a live order); the API
     * has no way to remove blocks before renewal.
     */
    private function convergeCpanelBlocks(int $want): void
    {
        $have = self::recorded($this->serviceId, 'cpanel_blocks');
        if ($want === $have) {
            return;
        }
        if ($want < $have) {
            $this->errors[] = sprintf('cPanel blocks cannot be removed through the API (%d -> %d). They drop at the next renewal if not renewed; adjust in the reseller panel if needed.', $have, $want);
            self::record($this->serviceId, 'cpanel_blocks', $want);

            return;
        }
        $row = Capsule::table('mod_lbh_services')->where('service_id', $this->serviceId)->first();
        $plan = (int) ($row->plan_id ?? 0);
        if ($plan <= 0) {
            $this->errors[] = 'cPanel blocks: the current plan is unknown - run "Sync from Provider" first.';

            return;
        }
        $months = \LbHosting\Service\Provisioner::remainingMonths($row->expires_at ?? null) ?? max(1, (int) ($row->tenure_months ?? 1));
        try {
            $this->api->modify($this->family, $this->key, $this->orderId, $plan, $months, $this->invoiceOption, ['cpanel-blocks' => $want - $have], $this->serviceId);
        } catch (\Throwable $e) {
            $this->errors[] = 'Adding cPanel blocks failed: ' . $e->getMessage();

            return;
        }
        self::record($this->serviceId, 'cpanel_blocks', $want);
        $this->changes[] = sprintf('cPanel blocks %d -> %d', $have, $want);
    }

    private function manual(string $item, string $value, array $context): void
    {
        $record = 'manual:' . $item;
        $previous = self::recordedValue($this->serviceId, $record);
        $result = (new ManualFulfilment())->request($this->serviceId, $this->family, $item, $value, $previous, $context + ['order_id' => $this->orderId]);
        if ($result === 'created') {
            $this->changes[] = sprintf('manual task: %s -> %s', $item, $value === '' ? 'none' : $value);
        }
        self::record($this->serviceId, $record, is_numeric($value) ? (int) $value : ($value === '' ? 0 : 1), ['value' => $value]);
    }

    /** @return array<string,mixed> */
    private function details(): array
    {
        if ($this->details === null) {
            $this->details = $this->api->details($this->family, $this->key, $this->orderId, $this->serviceId);
        }

        return $this->details;
    }

    private function labelFor(string $record): string
    {
        if (str_starts_with($record, 'addon:')) {
            $code = substr($record, 6);
            $all = ProductFamily::addons($this->family, $this->key);

            return (string) ($all[$code]['label'] ?? $code);
        }

        return [
            'ssl' => 'Dedicated IP (SSL)',
            'dedicated_ip' => 'Dedicated IPs',
            'whmcs' => 'WHMCS licence',
            'cpanel_blocks' => 'cPanel blocks',
        ][$record] ?? $record;
    }
}
