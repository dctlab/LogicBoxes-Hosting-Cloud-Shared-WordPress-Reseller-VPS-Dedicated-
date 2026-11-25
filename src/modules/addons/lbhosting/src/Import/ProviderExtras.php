<?php

declare(strict_types=1);

namespace LbHosting\Import;

use Illuminate\Database\Capsule\Manager as Capsule;
use LbHosting\Catalog\ProductFamily;
use LbHosting\Products\ProductMap;
use LbHosting\Service\ServiceRepository;

/**
 * What an existing LogicBoxes order carries besides its plan, and how that
 * maps onto the configurable options of a generator-made WHMCS product.
 *
 * The details.json field names for these are only documented for VPS and
 * Dedicated add-ons; the rest is PATTERN (see ServiceRepository). An import
 * therefore records "WHMCS options = what is at LogicBoxes" as the baseline,
 * so a wrong guess can never order or remove anything by itself - it only
 * shows in the imported service's options, where the admin can correct it.
 */
final class ProviderExtras
{
    /**
     * @param array<string,mixed> $d details.json
     * @return array{os:?string, addons:array<string,int>, dedicated_ips:int, ssl:bool, whmcs:bool}
     */
    public static function read(string $family, array $d): array
    {
        $addons = [];
        foreach (ServiceRepository::providerAddons($d) as $a) {
            $addons[$a['name']] = ($addons[$a['name']] ?? 0) + 1;
        }
        $ips = ServiceRepository::dedicatedIps($d);
        $ssl = false;
        foreach (['ssl', 'isssl', 'enablessl', 'enable-ssl', 'sslenabled', 'hasdedicatedip', 'isdedicatedip'] as $k) {
            $v = ServiceRepository::pick($d, [$k]);
            if ($v !== null && in_array(strtolower(is_bool($v) ? ($v ? 'true' : 'false') : (string) $v), ['true', '1', 'yes', 'enabled', 'active'], true)) {
                $ssl = true;
            }
        }
        $os = ServiceRepository::osName($d);

        return [
            'os' => is_string($os) && $os !== '' ? strtolower($os) : null,
            'addons' => $addons,
            'dedicated_ips' => count($ips),
            'ssl' => $ssl || (!ProductFamily::isServer($family) && $family !== ProductFamily::RESELLER && count($ips) > 0),
            'whmcs' => isset($addons['whmcs']) || ServiceRepository::licenseKeys($d) !== [],
        ];
    }

    /** @param array<string,mixed> $x */
    public static function summary(array $x): string
    {
        $parts = [];
        if (!empty($x['os'])) {
            $parts[] = (string) $x['os'];
        }
        foreach ((array) ($x['addons'] ?? []) as $name => $n) {
            $parts[] = $name . ((int) $n > 1 ? ' x' . (int) $n : '');
        }
        if (!empty($x['dedicated_ips'])) {
            $parts[] = (int) $x['dedicated_ips'] . ' dedicated IP';
        } elseif (!empty($x['ssl'])) {
            $parts[] = 'dedicated IP';
        }
        if (!empty($x['whmcs']) && empty($x['addons']['whmcs'])) {
            $parts[] = 'WHMCS licence';
        }

        return implode(', ', $parts);
    }

    /**
     * Configurable-option choices that mirror the order, for a mapped product.
     *
     * @param array<string,mixed> $x read()
     * @return array<int,array{optionid:int, qty:int}> config option id => row for tblhostingconfigoptions
     */
    public static function selections(string $family, int $mapId, array $x): array
    {
        $byOption = [];
        foreach (ProductMap::options($mapId) as $o) {
            $byOption[(int) $o->config_option_id][] = $o;
        }
        $addons = (array) ($x['addons'] ?? []);
        $out = [];
        foreach ($byOption as $configId => $subs) {
            $type = (int) Capsule::table('tblproductconfigoptions')->where('id', $configId)->value('optiontype');
            $logical = (string) $subs[0]->logical;
            if ($type === 1) { // dropdown: the sub-option whose code is on the order
                foreach ($subs as $s) {
                    $code = strtolower((string) $s->code);
                    if ($code !== '' && (isset($addons[$code]) || ($logical === 'os' && $code === ($x['os'] ?? null)))) {
                        $out[$configId] = ['optionid' => (int) $s->config_sub_id, 'qty' => 0];
                    }
                }
                continue;
            }
            $n = 0;
            switch ($logical) {
                case 'dedicated_ip':
                    if (ProductFamily::isServer($family) || ProductFamily::isRest($family)) {
                        $n = (int) ($addons['ipaddress'] ?? 0);
                    } elseif ($family === ProductFamily::RESELLER) {
                        $n = (int) ($x['dedicated_ips'] ?? 0);
                    } else {
                        $n = !empty($x['ssl']) ? 1 : 0;
                    }
                    break;
                case 'whmcs':
                    $n = !empty($x['whmcs']) ? 1 : 0;
                    break;
                case 'cpu':
                    $n = (int) ($addons['cpu_1'] ?? 0);
                    break;
                case 'ram':
                    $n = (int) ($addons['ram_1'] ?? 0);
                    break;
                default:
                    continue 2; // cPanel blocks, bandwidth, block storage: not readable from the order
            }
            if ($type === 3) {
                $n = $n > 0 ? 1 : 0;
            }
            if ($n > 0) {
                $out[$configId] = ['optionid' => (int) $subs[0]->config_sub_id, 'qty' => $n];
            }
        }

        return $out;
    }
}
