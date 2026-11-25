<?php

declare(strict_types=1);

namespace LbHosting\Catalog;

/**
 * The eight product families this suite sells, every LogicBoxes product key in
 * each family, the API path that key lives under, and what the API can do.
 *
 * Sources (read 2026-10-01):
 *  - Product keys: ResellerClub KB answer 1918 "Product Keys".
 *  - Paths/actions: KB answer 836 tree (Single Domain 1699-1732, Multi Domain
 *    1256-1491, Reseller 1320-1528/3063/3457, VPS Linux KVM 2456-2977, Block
 *    Storage 3283-3295, Dedicated Linux 2053-2065, Dedicated Windows 2764-2776,
 *    Managed Server 2223-2235), Cloud Hosting 2647-2664, WordPress Hosting
 *    2843-2853 / 2955 (REST API) and Business Hosting nodes 3547-3577.
 *
 * Path confidence per key:
 *  - VERIFIED  : the path appears in a KB example (US / IN).
 *  - OFFICIAL  : used by ResellerClub's own WHMCS modules (UK / HK).
 *  - PATTERN   : inferred from the sibling regions (TR, US California).
 *    Each key is one row here, so a wrong guess is a one-line fix.
 */
final class ProductFamily
{
    public const CLOUD = 'cloud';
    public const MULTIDOMAIN = 'multidomain';
    public const SINGLEDOMAIN = 'singledomain';
    public const RESELLER = 'reseller';
    public const VPS = 'vps';
    public const DEDICATED = 'dedicated';
    public const WORDPRESS = 'wordpress';
    public const BUSINESS = 'business';

    public const REGIONS = [
        'us' => 'United States (Texas)',
        'usca' => 'United States (California)',
        'in' => 'India',
        'uk' => 'United Kingdom',
        'hk' => 'Hong Kong',
        'tr' => 'Turkey',
    ];

    private const DEFS = [
        self::CLOUD => [
            'module' => 'lbhcloud',
            'label' => 'Cloud Hosting',
            'kind' => 'hosting',
            'auto_renew_param' => false,   // cloud add/renew take no auto-renew (KB 2647/2648)
            'plan_change' => false,        // no modify.json for Cloud
            'downgrade' => false,
            'ssl' => false,
            'panel_password' => true,      // change-password.json (KB 2657)
            'dns' => true,                 // dns-record.json (KB 2664)
            'shell' => true,               // enable/disable-shell-access.json (KB 2654/2655)
            'change_domain' => false,
            'addon_api' => true,           // addon/add.json addon=cpu_1|ram_1, addon/delete.json addon-id
            'os_choice' => false,
            'keys' => [
                'cloudsiteslinuxus' => ['path' => 'cloudsites/linux/us', 'os' => 'linux', 'region' => 'us', 'conf' => 'VERIFIED'],
                'cloudsiteslinuxin' => ['path' => 'cloudsites/linux/in', 'os' => 'linux', 'region' => 'in', 'conf' => 'VERIFIED'],
            ],
        ],
        self::MULTIDOMAIN => [
            'module' => 'lbhmultidomain',
            'label' => 'Multi Domain Hosting',
            'kind' => 'hosting',
            'auto_renew_param' => true,
            'plan_change' => true,         // modify.json + modify-pricing.json (KB 1258/1264)
            'downgrade' => true,
            'ssl' => true,                 // dedicated IP = enable-ssl.json / disable-ssl.json (KB 1259/1640)
            'panel_password' => true,
            'dns' => true,
            'shell' => false,
            'change_domain' => true,       // change-domain-name.json (KB 3059)
            'addon_api' => false,
            'os_choice' => false,
            'keys' => [
                'multidomainhosting' => ['path' => 'multidomainhosting/linux/us', 'os' => 'linux', 'region' => 'us', 'conf' => 'VERIFIED'],
                'multidomainhostinglinuxusca' => ['path' => 'multidomainhosting/linux/usca', 'os' => 'linux', 'region' => 'usca', 'conf' => 'PATTERN'],
                'multidomainhostinglinuxin' => ['path' => 'multidomainhosting/linux/in', 'os' => 'linux', 'region' => 'in', 'conf' => 'VERIFIED'],
                'multidomainhostinglinuxuk' => ['path' => 'multidomainhosting/linux/uk', 'os' => 'linux', 'region' => 'uk', 'conf' => 'OFFICIAL'],
                'multidomainhostinglinuxhk' => ['path' => 'multidomainhosting/linux/hk', 'os' => 'linux', 'region' => 'hk', 'conf' => 'OFFICIAL'],
                'multidomainhostinglinuxtr' => ['path' => 'multidomainhosting/linux/tr', 'os' => 'linux', 'region' => 'tr', 'conf' => 'PATTERN'],
                'multidomainwindowshosting' => ['path' => 'multidomainhosting/windows/us', 'os' => 'windows', 'region' => 'us', 'conf' => 'VERIFIED'],
                'multidomainwindowshostingusca' => ['path' => 'multidomainhosting/windows/usca', 'os' => 'windows', 'region' => 'usca', 'conf' => 'PATTERN'],
                'multidomainwindowshostingin' => ['path' => 'multidomainhosting/windows/in', 'os' => 'windows', 'region' => 'in', 'conf' => 'VERIFIED'],
                'multidomainwindowshostinguk' => ['path' => 'multidomainhosting/windows/uk', 'os' => 'windows', 'region' => 'uk', 'conf' => 'OFFICIAL'],
                'multidomainwindowshostinghk' => ['path' => 'multidomainhosting/windows/hk', 'os' => 'windows', 'region' => 'hk', 'conf' => 'OFFICIAL'],
                'multidomainwindowshostingtr' => ['path' => 'multidomainhosting/windows/tr', 'os' => 'windows', 'region' => 'tr', 'conf' => 'PATTERN'],
            ],
        ],
        self::SINGLEDOMAIN => [
            'module' => 'lbhsingledomain',
            'label' => 'Single Domain Hosting',
            'kind' => 'hosting',
            'auto_renew_param' => true,
            'plan_change' => true,         // KB 1701/1711
            'downgrade' => true,
            'ssl' => true,                 // KB 1702/1703
            'panel_password' => true,      // KB 1714
            'dns' => true,                 // KB 1715
            'shell' => false,
            'change_domain' => true,       // KB 3055
            'addon_api' => false,
            'os_choice' => false,
            'keys' => [
                'singledomainhostinglinuxus' => ['path' => 'singledomainhosting/linux/us', 'os' => 'linux', 'region' => 'us', 'conf' => 'VERIFIED'],
                'singledomainhostinglinuxusca' => ['path' => 'singledomainhosting/linux/usca', 'os' => 'linux', 'region' => 'usca', 'conf' => 'PATTERN'],
                'singledomainhostinglinuxin' => ['path' => 'singledomainhosting/linux/in', 'os' => 'linux', 'region' => 'in', 'conf' => 'VERIFIED'],
                'singledomainhostinglinuxuk' => ['path' => 'singledomainhosting/linux/uk', 'os' => 'linux', 'region' => 'uk', 'conf' => 'OFFICIAL'],
                'singledomainhostinglinuxhk' => ['path' => 'singledomainhosting/linux/hk', 'os' => 'linux', 'region' => 'hk', 'conf' => 'OFFICIAL'],
                'singledomainhostinglinuxtr' => ['path' => 'singledomainhosting/linux/tr', 'os' => 'linux', 'region' => 'tr', 'conf' => 'PATTERN'],
                'singledomainhostingwindowsus' => ['path' => 'singledomainhosting/windows/us', 'os' => 'windows', 'region' => 'us', 'conf' => 'VERIFIED'],
                'singledomainhostingwindowsusca' => ['path' => 'singledomainhosting/windows/usca', 'os' => 'windows', 'region' => 'usca', 'conf' => 'PATTERN'],
                'singledomainhostingwindowsin' => ['path' => 'singledomainhosting/windows/in', 'os' => 'windows', 'region' => 'in', 'conf' => 'VERIFIED'],
                'singledomainhostingwindowsuk' => ['path' => 'singledomainhosting/windows/uk', 'os' => 'windows', 'region' => 'uk', 'conf' => 'OFFICIAL'],
                'singledomainhostingwindowshk' => ['path' => 'singledomainhosting/windows/hk', 'os' => 'windows', 'region' => 'hk', 'conf' => 'OFFICIAL'],
                'singledomainhostingwindowstr' => ['path' => 'singledomainhosting/windows/tr', 'os' => 'windows', 'region' => 'tr', 'conf' => 'PATTERN'],
            ],
        ],
        self::RESELLER => [
            'module' => 'lbhreseller',
            'label' => 'Reseller Bulk Hosting',
            'kind' => 'hosting',
            'auto_renew_param' => true,
            'plan_change' => true,         // KB 1322/1329
            'downgrade' => true,
            'ssl' => false,
            'dedicated_ips' => true,       // add-dedicated-ip.json / delete-dedicated-ip.json ips[] (KB 1323/1332)
            'whmcs_license' => true,       // generate-license-key.json / revoke-license-key.json (KB 1372/3457)
            'cpanel_blocks' => true,       // cpanel-blocks on renew/modify, Linux only (KB 1321/1322)
            'panel_password' => true,
            'dns' => true,
            'shell' => false,
            'change_domain' => true,       // KB 3063
            'addon_api' => false,
            'os_choice' => false,
            'keys' => [
                'resellerhosting' => ['path' => 'resellerhosting/linux/us', 'os' => 'linux', 'region' => 'us', 'conf' => 'VERIFIED'],
                'resellerhostinglinuxin' => ['path' => 'resellerhosting/linux/in', 'os' => 'linux', 'region' => 'in', 'conf' => 'VERIFIED'],
                'resellerhostinglinuxuk' => ['path' => 'resellerhosting/linux/uk', 'os' => 'linux', 'region' => 'uk', 'conf' => 'OFFICIAL'],
                'resellerhostinglinuxhk' => ['path' => 'resellerhosting/linux/hk', 'os' => 'linux', 'region' => 'hk', 'conf' => 'OFFICIAL'],
                'resellerwindowshosting' => ['path' => 'resellerhosting/windows/us', 'os' => 'windows', 'region' => 'us', 'conf' => 'VERIFIED'],
                'resellerwindowshostingin' => ['path' => 'resellerhosting/windows/in', 'os' => 'windows', 'region' => 'in', 'conf' => 'VERIFIED'],
                'resellerwindowshostinguk' => ['path' => 'resellerhosting/windows/uk', 'os' => 'windows', 'region' => 'uk', 'conf' => 'OFFICIAL'],
                'resellerwindowshostinghk' => ['path' => 'resellerhosting/windows/hk', 'os' => 'windows', 'region' => 'hk', 'conf' => 'OFFICIAL'],
            ],
        ],
        self::WORDPRESS => [
            'module' => 'lbhwordpress',
            'label' => 'WordPress Hosting',
            'kind' => 'hosting',
            'api' => 'rest',               // restapi/product/wordpresshostingusa/... (KB 2843-2853)
            'auto_renew_param' => true,    // renew only (KB 2844); add takes none (KB 2843)
            'plan_change' => false,        // no modify call documented
            'downgrade' => false,
            'ssl' => false,
            'panel_password' => false,
            'dns' => false,
            'shell' => false,
            'change_domain' => false,
            'addon_api' => true,           // POST order/{id}/addon, DELETE order/{id}/addon/{addon-id} (KB 2845/2846)
            'os_choice' => false,
            'addons' => [
                'cpu_1' => ['label' => 'Extra CPU core', 'multi' => true],
                'ram_1' => ['label' => 'Extra RAM unit', 'multi' => true],
                'ipaddress' => ['label' => 'Dedicated IP address', 'multi' => true],
            ],
            'keys' => [
                'wordpresshostingusa' => ['path' => 'wordpresshostingusa', 'os' => 'linux', 'region' => 'us', 'conf' => 'VERIFIED'],
            ],
        ],
        self::BUSINESS => [
            'module' => 'lbhbusiness',
            'label' => 'Business Hosting',
            'kind' => 'hosting',
            'auto_renew_param' => true,
            'plan_change' => true,         // modify.json + modify-pricing.json (KB 3551/3559)
            'downgrade' => true,
            'ssl' => true,                 // dedicated IP = enable-ssl ...
            'ssl_order_only' => true,      // ... but only on add.json / renew.json (KB 3547/3549): no enable-ssl.json / disable-ssl.json page
            'panel_password' => true,      // KB 3577
            'dns' => true,                 // KB 3567
            'shell' => false,
            'change_domain' => true,       // KB 3569
            'addon_api' => false,
            'os_choice' => false,
            'keys' => [
                'businesshostinglinuxin' => ['path' => 'businesshosting/linux/in', 'os' => 'linux', 'region' => 'in', 'conf' => 'VERIFIED'],
            ],
        ],
        self::VPS => [
            'module' => 'lbhvps',
            'label' => 'VPS (Linux KVM)',
            'kind' => 'server',
            'auto_renew_param' => true,
            'plan_change' => true,         // modify.json, upgrades only, SSD plans only (KB 2977)
            'downgrade' => false,
            'ssl' => false,
            'panel_password' => false,
            'dns' => false,
            'shell' => false,
            'change_domain' => false,
            'addon_api' => true,           // addon/add.json, addon/delete.json (KB 2458/2459)
            'block_storage' => true,       // blockstorage/us/* (KB 3283-3295), US only - Phase 4
            'os_choice' => true,
            'default_os' => 'alma_8',      // KB 2456: alma_8 if omitted
            'os' => ['alma_8' => 'AlmaLinux 8', 'alma_9' => 'AlmaLinux 9', 'rocky_8' => 'Rocky Linux 8', 'rocky_9' => 'Rocky Linux 9', 'ubuntu_20' => 'Ubuntu 20.04', 'ubuntu_22' => 'Ubuntu 22.04'],
            'addons' => [
                'ipaddress' => ['label' => 'Additional IP address', 'multi' => true],
                'whmcs' => ['label' => 'WHMCS licence'],
                'cpanel' => ['label' => 'cPanel licence', 'group' => 'panel'],
                'plesk_10_domain' => ['label' => 'Plesk (10 domains)', 'group' => 'panel'],
                'plesk_30_domain' => ['label' => 'Plesk (30 domains)', 'group' => 'panel'],
                'plesk_unlimited_domain' => ['label' => 'Plesk (unlimited domains)', 'group' => 'panel'],
                'storage_1' => ['label' => 'Storage 1 (HDD plans)', 'group' => 'storage'],
                'storage_2' => ['label' => 'Storage 2 (HDD plans)', 'group' => 'storage'],
                'storage_3' => ['label' => 'Storage 3 (HDD plans)', 'group' => 'storage'],
                'storage_4' => ['label' => 'Storage 4 (HDD plans)', 'group' => 'storage'],
                'storage_5' => ['label' => 'Storage 5 (HDD plans)', 'group' => 'storage'],
            ],
            'keys' => [
                'virtualserverlinuxus' => ['path' => 'virtualserver/linux/us', 'os' => 'linux', 'region' => 'us', 'conf' => 'VERIFIED'],
                'virtualserverlinuxin' => ['path' => 'virtualserver/linux/in', 'os' => 'linux', 'region' => 'in', 'conf' => 'VERIFIED'],
            ],
        ],
        self::DEDICATED => [
            'module' => 'lbhdedicated',
            'label' => 'Dedicated Server',
            'kind' => 'server',
            'auto_renew_param' => true,
            'plan_change' => false,        // no modify call for dedicated / managed servers
            'downgrade' => false,
            'ssl' => false,
            'panel_password' => false,
            'dns' => false,
            'shell' => false,
            'change_domain' => false,
            'addon_api' => true,           // addon/add.json, addon/delete.json (KB 2055/2056, 2766/2767, 2225/2226)
            'os_choice' => true,
            'keys' => [
                'dedicatedserverlinuxus' => [
                    'path' => 'dedicatedserver/linux/us', 'os' => 'linux', 'region' => 'us', 'conf' => 'VERIFIED',
                    'os_list' => ['centos_7' => 'CentOS 7', 'centos_8' => 'CentOS 8', 'ubuntu_18' => 'Ubuntu 18.04', 'ubuntu_20' => 'Ubuntu 20.04', 'debian_9' => 'Debian 9', 'debian_10' => 'Debian 10'],
                    'addons' => ['ipaddress', 'cpanel', 'plesk_web_admin_license', 'plesk_web_pro_license', 'plesk_web_host_license', 'whmcs', 'storage_1', 'storage_2', 'storage_3', 'storage_4', 'storage_5'],
                ],
                'dedicatedserverlinuxin' => [
                    'path' => 'dedicatedserver/linux/in', 'os' => 'linux', 'region' => 'in', 'conf' => 'VERIFIED',
                    'os_list' => ['centos_7' => 'CentOS 7', 'ubuntu_16' => 'Ubuntu 16.04', 'ubuntu_18' => 'Ubuntu 18.04', 'ubuntu_20' => 'Ubuntu 20.04'],
                    'addons' => ['ipaddress', 'cpanel', 'plesk_web_admin_license', 'plesk_web_pro_license', 'plesk_web_host_license', 'whmcs'], // no storage in India (KB 2053)
                ],
                'dedicatedserverwindowsin' => [
                    'path' => 'dedicatedserver/windows/in', 'os' => 'windows', 'region' => 'in', 'conf' => 'VERIFIED',
                    'os_list' => ['win2k12' => 'Windows Server 2012', 'win2k16' => 'Windows Server 2016', 'win2k19' => 'Windows Server 2019'],
                    'os_required' => true,  // KB 2764: os Required
                    'addons' => ['ipaddress', 'plesk_web_admin_license', 'plesk_web_pro_license', 'plesk_web_host_license'],
                ],
                'managedserverlinuxus' => [
                    'path' => 'managedserver/linux/us', 'os' => 'linux', 'region' => 'us', 'conf' => 'VERIFIED',
                    'managed' => true,      // fully managed variant of the Dedicated Server family
                    'os_list' => [],        // managed servers take no os parameter (KB 2223)
                    'addons' => ['ipaddress', 'whmcs', 'storage_1', 'storage_2', 'storage_3', 'storage_4', 'storage_5'],
                ],
            ],
            'addons' => [
                'ipaddress' => ['label' => 'Additional IP address', 'multi' => true],
                'whmcs' => ['label' => 'WHMCS licence'],
                'cpanel' => ['label' => 'cPanel licence', 'group' => 'panel'],
                'plesk_web_admin_license' => ['label' => 'Plesk Web Admin (10 domains)', 'group' => 'panel'],
                'plesk_web_pro_license' => ['label' => 'Plesk Web Pro (30 domains)', 'group' => 'panel'],
                'plesk_web_host_license' => ['label' => 'Plesk Web Host (unlimited)', 'group' => 'panel'],
                'storage_1' => ['label' => 'Storage 1', 'group' => 'storage'],
                'storage_2' => ['label' => 'Storage 2', 'group' => 'storage'],
                'storage_3' => ['label' => 'Storage 3', 'group' => 'storage'],
                'storage_4' => ['label' => 'Storage 4', 'group' => 'storage'],
                'storage_5' => ['label' => 'Storage 5', 'group' => 'storage'],
            ],
            'manual' => ['bandwidth'],     // no bandwidth add-on in the API -> To-Do + ticket
        ],
    ];

    /** Cloud: CPU and RAM are sold in units (KB 2652); the dedicated IP is manual. */
    public const CLOUD_ADDONS = [
        'cpu_1' => ['label' => 'Extra CPU core', 'multi' => true],
        'ram_1' => ['label' => 'Extra RAM unit', 'multi' => true],
    ];

    /** @return string[] */
    public static function all(): array
    {
        return array_keys(self::DEFS);
    }

    public static function assert(string $family): string
    {
        if (!isset(self::DEFS[$family])) {
            throw new \InvalidArgumentException('Unknown product family "' . $family . '".');
        }

        return $family;
    }

    public static function isFamily(string $family): bool
    {
        return isset(self::DEFS[$family]);
    }

    /** @return array<string,mixed> */
    public static function def(string $family): array
    {
        return self::DEFS[self::assert($family)];
    }

    public static function label(string $family): string
    {
        return (string) self::def($family)['label'];
    }

    public static function module(string $family): string
    {
        return (string) self::def($family)['module'];
    }

    /** WordPress Hosting lives on the REST product API; everything else on the legacy HTTP API. */
    public static function isRest(string $family): bool
    {
        return (self::def($family)['api'] ?? 'legacy') === 'rest';
    }

    public static function isServer(string $family): bool
    {
        return self::def($family)['kind'] === 'server';
    }

    public static function can(string $family, string $capability): bool
    {
        return !empty(self::def($family)[$capability]);
    }

    /** @return array<string,array<string,mixed>> product key => meta (documented keys first, then keys found on the reseller account) */
    public static function keys(string $family): array
    {
        return self::def($family)['keys'] + (self::discovered()[$family] ?? []);
    }

    // ---------------------------------------------------- discovered locations

    /**
     * Locations LogicBoxes sells but does not document (Business Hosting and
     * WordPress Hosting outside the one location in the KB, new Cloud
     * regions). They are recognised by their product key in the account's
     * own price / plan list and get their API path by the family's pattern:
     *
     *   businesshosting{os}{region}   -> businesshosting/{os}/{region}
     *   businesshosting               -> businesshosting/linux/us   (the "no suffix = Linux US" form of the older products)
     *   wordpresshosting{region}      -> restapi/product/{key}      (the REST path IS the product key)
     *   cloudsites{os}{region}        -> cloudsites/{os}/{region}
     *
     * conf = PATTERN: the path follows the documented sibling, it was not read in the KB.
     */
    private const DISCOVER = [
        self::BUSINESS => '/^businesshosting(?:(linux|windows)([a-z]{2,6}))?$/',
        self::WORDPRESS => '/^wordpresshosting([a-z]{2,10})$/',
        self::CLOUD => '/^cloudsites(linux|windows)([a-z]{2,6})$/',
    ];

    private const REGION_ALIASES = ['usa' => 'us', 'india' => 'in', 'ind' => 'in', 'gb' => 'uk', 'turkey' => 'tr', 'global' => 'us'];

    /** @var array<string,array<string,array<string,mixed>>>|null */
    private static ?array $discovered = null;

    /** @return array<string,array<string,array<string,mixed>>> family => key => meta */
    public static function discovered(): array
    {
        if (self::$discovered === null) {
            self::$discovered = [];
            try {
                $stored = json_decode(\LbHosting\Support\Settings::get('discovered_keys'), true);
                $manual = preg_split('/[\s,;]+/', strtolower(\LbHosting\Support\Settings::get('extra_product_keys'))) ?: [];
                $keys = array_merge(is_array($stored) ? array_map('strval', $stored) : [], $manual);
                foreach ($keys as $key) {
                    $hit = self::classify($key);
                    if ($hit !== null) {
                        self::$discovered[$hit[0]][$key] = $hit[1];
                    }
                }
            } catch (\Throwable $e) {
                // settings table not there yet (before activation)
            }
        }

        return self::$discovered;
    }

    public static function resetDiscovered(): void
    {
        self::$discovered = null;
    }

    /**
     * Family and meta of an undocumented product key, or null when it is not one of ours.
     *
     * @return array{0:string,1:array<string,mixed>}|null
     */
    public static function classify(string $key): ?array
    {
        $key = strtolower(trim($key));
        if ($key === '' || strlen($key) > 64) {
            return null;
        }
        foreach (self::DEFS as $d) {
            if (isset($d['keys'][$key])) {
                return null; // documented
            }
        }
        foreach (self::DISCOVER as $family => $re) {
            if (!preg_match($re, $key, $m)) {
                continue;
            }
            if ($family === self::WORDPRESS) {
                $region = self::REGION_ALIASES[$m[1]] ?? $m[1];

                return [$family, ['path' => $key, 'os' => 'linux', 'region' => $region, 'conf' => 'PATTERN', 'discovered' => true]];
            }
            $os = $m[1] ?? 'linux';
            $region = isset($m[2]) ? (self::REGION_ALIASES[$m[2]] ?? $m[2]) : 'us';
            $base = $family === self::BUSINESS ? 'businesshosting' : 'cloudsites';

            return [$family, ['path' => $base . '/' . $os . '/' . ($m[2] ?? 'us'), 'os' => $os, 'region' => $region, 'conf' => 'PATTERN', 'discovered' => true]];
        }

        return null;
    }

    /**
     * Remember the undocumented locations found among an account's product keys.
     *
     * @param string[] $productKeys every top-level key of customer-price.json / plan-details.json
     * @return string[] keys that are new
     */
    public static function discover(array $productKeys): array
    {
        $known = [];
        foreach (self::discovered() as $keys) {
            $known = array_merge($known, array_keys($keys));
        }
        $new = [];
        foreach ($productKeys as $key) {
            $key = strtolower(trim((string) $key));
            if (!in_array($key, $known, true) && !in_array($key, $new, true) && self::classify($key) !== null) {
                $new[] = $key;
            }
        }
        if ($new) {
            $stored = json_decode(\LbHosting\Support\Settings::get('discovered_keys'), true);
            $all = array_values(array_unique(array_merge(is_array($stored) ? array_map('strval', $stored) : [], $new)));
            \LbHosting\Support\Settings::set('discovered_keys', (string) json_encode($all));
            self::$discovered = null;
        }

        return $new;
    }

    public static function defaultKey(string $family): string
    {
        return (string) array_key_first(self::keys($family));
    }

    /** @return array<string,mixed> */
    public static function keyMeta(string $family, string $key): array
    {
        $keys = self::keys($family);
        $key = strtolower($key);
        if (!isset($keys[$key])) {
            throw new \InvalidArgumentException('Product key "' . $key . '" does not belong to ' . self::label($family) . '.');
        }

        return $keys[$key];
    }

    /** API path prefix for a product key, e.g. "multidomainhosting/linux/in". */
    public static function path(string $family, string $key): string
    {
        return (string) self::keyMeta($family, $key)['path'];
    }

    public static function keyLabel(string $family, string $key): string
    {
        try {
            $m = self::keyMeta($family, $key);
        } catch (\InvalidArgumentException $e) {
            return $key;
        }
        $parts = [];
        if (!empty($m['managed'])) {
            $parts[] = 'Managed';
        }
        $parts[] = ucfirst((string) $m['os']);
        $parts[] = self::REGIONS[$m['region']] ?? strtoupper((string) $m['region']);

        return implode(' - ', $parts);
    }

    public static function isManaged(string $family, string $key): bool
    {
        try {
            return !empty(self::keyMeta($family, $key)['managed']);
        } catch (\InvalidArgumentException $e) {
            return false;
        }
    }

    public static function forModule(string $module): ?string
    {
        foreach (self::DEFS as $f => $d) {
            if ($d['module'] === $module) {
                return $f;
            }
        }

        return null;
    }

    public static function forKey(string $key): ?string
    {
        $key = strtolower($key);
        foreach (self::DEFS as $f => $d) {
            if (isset($d['keys'][$key])) {
                return $f;
            }
        }
        foreach (self::discovered() as $f => $keys) {
            if (isset($keys[$key])) {
                return $f;
            }
        }

        return null;
    }

    /** @return string[] */
    public static function modules(): array
    {
        return array_values(array_map(static fn ($d) => (string) $d['module'], self::DEFS));
    }

    /**
     * Operating systems a product key accepts (servers only).
     *
     * @return array<string,string> code => label
     */
    public static function osList(string $family, string $key): array
    {
        $d = self::def($family);
        if (empty($d['os_choice'])) {
            return [];
        }
        $meta = self::keyMeta($family, $key);
        if (array_key_exists('os_list', $meta)) {
            return $meta['os_list'];
        }

        return $d['os'] ?? [];
    }

    public static function defaultOs(string $family, string $key): ?string
    {
        $d = self::def($family);
        if (!empty($d['default_os'])) {
            return (string) $d['default_os'];
        }

        return null;
    }

    /** OS is mandatory for this key (Dedicated Windows). */
    public static function osRequired(string $family, string $key): bool
    {
        return !empty(self::keyMeta($family, $key)['os_required']);
    }

    /**
     * Provider add-on codes a key accepts through addon/add.json.
     *
     * @return array<string,array<string,mixed>> code => meta
     */
    public static function addons(string $family, string $key): array
    {
        $d = self::def($family);
        if ($family === self::CLOUD) {
            return self::CLOUD_ADDONS;
        }
        $all = $d['addons'] ?? [];
        $meta = self::keyMeta($family, $key);
        if (isset($meta['addons'])) {
            return array_intersect_key($all, array_flip($meta['addons']));
        }

        return $all;
    }

    /** @return string[] items this family can only fulfil by hand (To-Do + ticket) */
    public static function manualItems(string $family): array
    {
        $out = self::def($family)['manual'] ?? [];
        if ($family === self::CLOUD) {
            $out[] = 'dedicated_ip'; // KB 2652: "IP addresses cannot be provisioned using the API calls"
        }
        if (self::can($family, 'ssl_order_only')) {
            $out[] = 'dedicated_ip'; // only changeable with the order / at renewal
        }

        return $out;
    }
}
