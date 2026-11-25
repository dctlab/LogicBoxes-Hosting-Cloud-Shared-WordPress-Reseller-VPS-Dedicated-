<?php

declare(strict_types=1);

namespace LbHosting\Service;

use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * mod_lbh_services access (one row per WHMCS service) and the parsing of
 * LogicBoxes details.json payloads.
 *
 * Details key names: the KB documents them for Dedicated (2060) and VPS (2461):
 * currentstatus, endtime, planid, customerid, productkey, ipaddress/ipAddress,
 * siteadminusername, siteadminpassword, os_name, addons[addon_id|addOnID,
 * addon_name|name, is_paid, creation_time|creationTime]. The hosting pages only
 * say "returns the details", so several spellings are accepted everywhere.
 * Use the admin button "Show Provider Details" to see your account's real keys.
 */
final class ServiceRepository
{
    private const TABLE = 'mod_lbh_services';

    public function get(int $serviceId): ?object
    {
        return Capsule::table(self::TABLE)->where('service_id', $serviceId)->first();
    }

    /** @param array<string,mixed> $fields */
    public function upsert(int $serviceId, array $fields): void
    {
        $now = date('Y-m-d H:i:s');
        $fields['updated_at'] = $now;
        if ($this->get($serviceId)) {
            Capsule::table(self::TABLE)->where('service_id', $serviceId)->update($fields);

            return;
        }
        $fields['service_id'] = $serviceId;
        $fields['created_at'] = $now;
        Capsule::table(self::TABLE)->insert($fields);
    }

    /**
     * Another service already linked to this order? Order ids are only unique
     * within one reseller platform, so the account is part of the key.
     */
    public function orderLinkedElsewhere(int $orderId, int $serviceId, ?int $accountId = null): ?int
    {
        $q = Capsule::table(self::TABLE)->where('order_id', $orderId)->where('service_id', '!=', $serviceId);
        if ($accountId) {
            $q->where('account_id', $accountId);
        }
        $other = $q->value('service_id');

        return $other ? (int) $other : null;
    }

    /** @param array<string,mixed> $d */
    public function applyDetails(int $serviceId, array $d): void
    {
        if (!$d) {
            return;
        }
        $fields = ['last_synced_at' => date('Y-m-d H:i:s')];
        $status = self::pick($d, ['currentstatus', 'orderstatus', 'order_status', 'status']);
        if (is_string($status) && !in_array(strtolower($status), ['success', 'error', ''], true)) {
            $fields['provider_status'] = substr($status, 0, 32);
        }
        $ts = self::timestamp(self::pick($d, ['endtime', 'expirytimestamp', 'expirydate', 'expiry_date']));
        if ($ts !== null) {
            $fields['expires_at'] = date('Y-m-d H:i:s', $ts);
        }
        $current = $this->get($serviceId);
        $plan = self::pick($d, ['planid', 'plan-id', 'plan_id']);
        // A queued plan change may still show the old plan for a while; do not
        // let that stale value undo the change we recorded.
        $planLocked = $current && !empty($current->plan_changed_at) && strtotime((string) $current->plan_changed_at) > time() - 48 * 3600
            && is_numeric($plan) && (int) $plan !== (int) $current->plan_id;
        if (is_numeric($plan) && (int) $plan > 0 && !$planLocked) {
            $fields['plan_id'] = (int) $plan;
        }
        $planName = self::pick($d, ['plan_name', 'planname', 'plan-name']);
        if (is_string($planName) && $planName !== '' && !$planLocked) {
            $fields['plan_name'] = substr($planName, 0, 191);
        }
        $customer = self::pick($d, ['customerid', 'customer-id', 'customer_id']);
        if (is_numeric($customer) && (int) $customer > 0) {
            $fields['customer_id'] = (int) $customer;
        }
        $key = self::pick($d, ['productkey', 'product_key']);
        if (is_string($key) && preg_match('/^[a-z0-9]{2,64}$/', strtolower($key))) {
            $fields['product_key'] = strtolower($key);
        }
        $ip = self::primaryIp($d);
        if ($ip !== null) {
            $fields['ip_address'] = $ip;
        }
        $user = self::pick($d, ['siteadminusername', 'cpanelusername', 'cp_username', 'username', 'adminusername']);
        if (is_string($user) && $user !== '' && strlen($user) <= 64) {
            $fields['panel_username'] = $user;
        }
        $os = self::osName($d);
        if (is_string($os) && $os !== '' && strlen($os) <= 32) {
            $fields['os'] = $os;
        }
        $fields['details_json'] = json_encode(self::redactDetails($d), JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $this->upsert($serviceId, $fields);
    }

    /** @return array<string,mixed> stored, redacted details */
    public static function storedDetails(?object $row): array
    {
        if (!$row || empty($row->details_json)) {
            return [];
        }
        $d = json_decode((string) $row->details_json, true);

        return is_array($d) ? $d : [];
    }

    /**
     * Remove every secret before a payload is stored or shown to an admin:
     * any key containing pass / secret / token / licen(s|c)e, at any depth.
     *
     * @param array<mixed> $d
     * @return array<mixed>
     */
    public static function redactDetails(array $d): array
    {
        $out = [];
        foreach ($d as $k => $v) {
            if (is_string($k) && preg_match('/pass|secret|token|licen[cs]e|whmcs_key|auth/i', $k)) {
                $out[$k] = '***';
                continue;
            }
            $out[$k] = is_array($v) ? self::redactDetails($v) : $v;
        }

        return $out;
    }

    /** @param array<string,mixed> $d */
    public static function primaryIp(array $d): ?string
    {
        foreach (['ipaddress', 'ipAddress', 'ip_address', 'ip', 'serverip', 'server_ip', 'primaryip'] as $k) {
            if (isset($d[$k]) && is_string($d[$k])) {
                foreach (preg_split('/[\s,;]+/', $d[$k]) ?: [] as $cand) {
                    if (filter_var($cand, FILTER_VALIDATE_IP)) {
                        return $cand;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Provider add-ons listed in a details payload.
     *
     * @param array<string,mixed> $d
     * @return array<int,array{id:?int,name:string,paid:?bool,created:?int,ip:?string}>
     */
    public static function providerAddons(array $d): array
    {
        $list = null;
        // VPS details carry the add-ons at the top level or inside "installed_os"
        foreach ([$d, is_array($d['installed_os'] ?? null) ? $d['installed_os'] : []] as $src) {
            foreach (['addons', 'addon_details', 'addonDetails', 'addonsDetails', 'addon'] as $k) {
                if (isset($src[$k]) && is_array($src[$k]) && $src[$k] !== []) {
                    $list = $src[$k];
                    break 2;
                }
            }
        }
        if ($list === null) {
            return [];
        }
        $out = [];
        foreach ($list as $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = self::pick($item, ['addon_name', 'name', 'addonname', 'addonName']);
            if (!is_string($name) || $name === '') {
                continue;
            }
            $id = self::pick($item, ['addon_id', 'addOnID', 'addonid', 'addonId', 'id']);
            $paid = self::pick($item, ['is_paid', 'isPaid', 'paid']);
            $created = self::timestamp(self::pick($item, ['creation_time', 'creationTime', 'creationtime']));
            $ip = self::pick($item, ['ipaddress', 'ipAddress', 'ip']);
            $out[] = [
                'id' => is_numeric($id) ? (int) $id : null,
                'name' => strtolower($name),
                'paid' => $paid === null ? null : in_array(strtolower(var_export($paid, true)), ['true', "'true'", "'1'", '1'], true),
                'created' => $created,
                'ip' => is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null,
            ];
        }

        return $out;
    }

    /**
     * Reseller hosting: dedicated IPs and WHMCS licence keys are not documented
     * as named keys, so every value of a key that looks like "dedicated ip" /
     * "licence" is collected (PATTERN).
     *
     * @param array<string,mixed> $d
     * @return string[]
     */
    /** Installed OS: "os_name" at the top level or inside "installed_os". @param array<string,mixed> $d */
    public static function osName(array $d): ?string
    {
        $os = self::pick($d, ['os_name', 'osname', 'os']);
        if (!is_string($os) && is_array($d['installed_os'] ?? null)) {
            $os = self::pick($d['installed_os'], ['os_name', 'osname', 'os']);
        }

        return is_string($os) && $os !== '' ? $os : null;
    }

    /**
     * Extra IP addresses bought as add-ons (VPS "ipaddress" add-ons carry their IP).
     *
     * @param array<string,mixed> $d
     * @return string[]
     */
    public static function addonIps(array $d): array
    {
        $out = [];
        foreach (self::providerAddons($d) as $a) {
            if (!empty($a['ip']) && filter_var($a['ip'], FILTER_VALIDATE_IP)) {
                $out[] = (string) $a['ip'];
            }
        }

        return array_values(array_unique($out));
    }

    public static function dedicatedIps(array $d): array
    {
        $out = [];
        foreach ($d as $k => $v) {
            if (!is_string($k) || !preg_match('/dedicated.?ip|ssl.?ip/i', $k)) {
                continue;
            }
            $vals = is_array($v) ? $v : preg_split('/[\s,;]+/', (string) $v);
            array_walk_recursive($vals, static function ($x) use (&$out) {
                if (is_string($x) && filter_var($x, FILTER_VALIDATE_IP)) {
                    $out[] = $x;
                }
            });
        }

        return array_values(array_unique($out));
    }

    /** @param array<string,mixed> $d @return string[] */
    public static function licenseKeys(array $d): array
    {
        $out = [];
        array_walk_recursive($d, static function ($v, $k) use (&$out) {
            if (is_string($k) && preg_match('/licen[cs]e/i', $k) && is_string($v) && preg_match('/^[A-Za-z0-9-]{10,80}$/', $v)) {
                $out[] = $v;
            }
        });

        return array_values(array_unique($out));
    }

    /** @param mixed $v */
    public static function timestamp($v): ?int
    {
        if (is_numeric($v) && (int) $v > 0) {
            $n = (int) $v;

            return $n > 100000000000 ? intdiv($n, 1000) : $n; // milliseconds
        }
        if (is_string($v) && $v !== '' && ($t = strtotime($v)) !== false && $t > 0) {
            return $t;
        }

        return null;
    }

    /**
     * @param array<string,mixed> $d
     * @param string[] $keys
     * @return mixed
     */
    public static function pick(array $d, array $keys)
    {
        foreach ($keys as $k) {
            if (array_key_exists($k, $d) && $d[$k] !== '' && $d[$k] !== null) {
                return $d[$k];
            }
        }

        return null;
    }
}
