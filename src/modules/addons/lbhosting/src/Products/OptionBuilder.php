<?php

declare(strict_types=1);

namespace LbHosting\Products;

use Illuminate\Database\Capsule\Manager as Capsule;
use LbHosting\Catalog\ProductFamily;
use LbHosting\Pricing\PriceCatalog;
use LbHosting\Support\Logger;

/**
 * "Fully automated Configurable Options Setup ... with Selling Price Import".
 *
 * Decides which configurable options a plan gets and creates them in WHMCS,
 * named exactly as OptionMap reads them, with "code|Label" sub-options:
 *
 *   Cloud          CPU (qty, addon:cpu_1), RAM (qty, addon:ram_1), Dedicated IP (yes/no, manual price)
 *   SDH / MDH / Business  Dedicated IP (yes/no, the plan's "ssl" price)
 *   WordPress      CPU, RAM, Dedicated IP (qty, addon:cpu_1 / ram_1 / ipaddress)
 *   Reseller       Dedicated IPs (qty, "ssl"), WHMCS License (yes/no), cPanel Blocks (qty, Linux)
 *   VPS            Operating System (free), Control Panel, Dedicated IP (qty), WHMCS License, Storage (HDD plans),
 *                  Block Storage (GB, US only, manual price per GB)
 *   Dedicated      Operating System (plan's supported_os), Control Panel, Dedicated IP, WHMCS License,
 *                  Storage (US), Bandwidth (manual tiers you enter)
 *
 * Price of every sub-option comes from the LogicBoxes price list the product
 * is priced from (cost price + markup, or customer price): the item LogicBoxes
 * lists for it, found by its usual names (PRICE_ITEMS), or the item the admin
 * bound it to on the product's page when the name is not recognised.
 * An option LogicBoxes does not price is NOT created - nothing is ever sold
 * for free, or at a made-up price, by accident. Only items LogicBoxes never
 * prices because it does not provision them through the API (Cloud dedicated
 * IP, Dedicated bandwidth, VPS Block Storage per GB) take a price you enter.
 * Only the OS choice is free.
 *
 * apply() never duplicates: options it manages are found through
 * mod_lbh_option_map, and an option of the same name that is already on the
 * product (made by hand, or by an older version) is adopted, not re-created.
 *
 * WHMCS tables (core schema, PATTERN - no localAPI exists for config options):
 *   tblproductconfiggroups, tblproductconfigoptions (optiontype 1 dropdown /
 *   3 yes-no / 4 quantity - VERIFIED codes), tblproductconfigoptionssub,
 *   tblproductconfiglinks, tblpricing type "configoptions".
 */
final class OptionBuilder
{
    public const NAMES = [
        'os' => 'Operating System',
        'control_panel' => 'Control Panel',
        'dedicated_ip' => 'Dedicated IP',
        'cpu' => 'CPU',
        'ram' => 'RAM',
        'whmcs' => 'WHMCS License',
        'storage' => 'Storage',
        'cpanel_blocks' => 'cPanel Blocks',
        'bandwidth' => 'Bandwidth',
        'block_storage' => 'Block Storage',
    ];

    /**
     * Where an option's price is found in the LogicBoxes price list, by logical
     * option: exact items first, then a name pattern over every extras item of
     * the plan. The KB documents "ssl" (dedicated IP of hosting plans) and the
     * server "addons" codes; the cPanel-block and reseller WHMCS items are not
     * documented, so they are recognised by name (PATTERN) or bound by hand.
     */
    public const PRICE_ITEMS = [
        'dedicated_ip' => ['ssl', 'addon:ipaddress', 'addon:dedicatedip', 'addon:dedicated_ip'],
        'whmcs' => ['addon:whmcs', 'addon:whmcs_license', 'addon:whmcs_licence', 'addon:whmcslicense', 'addon:whmcslicence'],
        'cpanel_blocks' => ['addon:cpanel_blocks', 'addon:cpanel_block', 'addon:cpanelblocks', 'addon:cpanelblock', 'addon:cpanel_accounts', 'addon:cpanelaccounts', 'addon:additional_cpanel_accounts', 'addon:additional_cpanel', 'addon:cpanel_10'],
        'cpu' => ['addon:cpu_1', 'addon:cpu'],
        'ram' => ['addon:ram_1', 'addon:ram'],
    ];

    private const PRICE_PATTERNS = [
        'whmcs' => '/^addon:.*whmcs/',
        'cpanel_blocks' => '/^addon:(.*cpanel.*(block|account|10)|.*(additional|extra).*cpanel)/',
    ];

    /** Items LogicBoxes never prices (fulfilled by hand, or not in the price list): the only manual prices. */
    public const MANUAL_ONLY = ['cloud:dedicated_ip', 'vps:block_storage', 'dedicated:bandwidth'];

    private const TYPE_DROPDOWN = 1;
    private const TYPE_YESNO = 3;
    private const TYPE_QTY = 4;

    /**
     * The LogicBoxes price item an option is priced from, or null when the
     * price list has none for it.
     *
     * @param array<string,string> $bindings logical => item chosen by the admin
     * @return array{item:string, price:float}|null
     */
    public static function priceFor(string $logical, int $accountId, string $key, int $planId, string $kind, array $bindings = []): ?array
    {
        $extras = PriceCatalog::extras($accountId, $key, $planId, $kind);
        $extras = array_filter($extras, static fn ($p) => $p > 0); // listed at 0 = not priced: never created as a free option
        $bound = (string) ($bindings[$logical] ?? '');
        if ($bound !== '' && isset($extras[$bound])) {
            return ['item' => $bound, 'price' => $extras[$bound]];
        }
        foreach (self::PRICE_ITEMS[$logical] ?? [] as $item) {
            if (isset($extras[$item])) {
                return ['item' => $item, 'price' => $extras[$item]];
            }
        }
        if (isset(self::PRICE_PATTERNS[$logical])) {
            foreach ($extras as $item => $p) {
                if (preg_match(self::PRICE_PATTERNS[$logical], $item)) {
                    return ['item' => $item, 'price' => $p];
                }
            }
        }

        return null;
    }

    public static function isManualOnly(string $family, string $logical): bool
    {
        return in_array($family . ':' . $logical, self::MANUAL_ONLY, true);
    }

    /**
     * @param array<string,mixed> $manual cloud dedicated_ip / VPS block_storage => monthly price, bandwidth_tiers => text
     *                                    (prices for anything else are taken from LogicBoxes only)
     * @param string $kind "cost" or "sell" - the LogicBoxes price list the product is priced from
     * @param array<string,string> $bindings logical => LogicBoxes price item chosen by the admin
     * @return array{specs: array<int,array<string,mixed>>, skipped: string[]}
     */
    public static function specs(string $family, string $key, int $accountId, int $planId, array $manual = [], string $kind = 'sell', array $bindings = []): array
    {
        $specs = [];
        $skipped = [];
        $details = PriceCatalog::planDetails($accountId, $key, $planId);
        $price = static function (string $item) use ($accountId, $key, $planId, $kind): ?float {
            $p = PriceCatalog::extra($accountId, $key, $planId, $item, $kind);

            return $p !== null && $p > 0 ? $p : null;
        };
        $manualPrice = static function (string $k) use ($manual): ?float {
            $v = trim((string) ($manual[$k] ?? ''));

            return $v !== '' && is_numeric(str_replace(',', '.', $v)) ? max(0.0, (float) str_replace(',', '.', $v)) : null;
        };
        // From the LogicBoxes price list only (see priceFor()).
        $priced = static function (string $logical) use ($accountId, $key, $planId, $kind, $bindings): ?array {
            $p = self::priceFor($logical, $accountId, $key, $planId, $kind, $bindings);

            return $p === null ? null : ['item' => $p['item'], 'manual' => null, 'value' => $p['price']];
        };
        $listName = $kind === 'cost' ? 'cost price list' : 'customer price list';
        $add = function (string $logical, int $type, array $subs, int $qtyMax = 0) use (&$specs, &$skipped, &$listName) {
            $real = array_values(array_filter($subs, static fn ($s) => $s['code'] !== ''));
            if (!$real) {
                $skipped[] = self::NAMES[$logical] . ': LogicBoxes lists no price for it in the ' . $listName . ' - not created (bind it to the right price item on the product\'s page if LogicBoxes lists it under another name)';

                return;
            }
            $specs[] = ['logical' => $logical, 'name' => self::NAMES[$logical], 'type' => $type, 'qtymax' => $qtyMax, 'subs' => $subs];
        };
        $sub = static fn (string $code, string $label, string $item, ?float $manualValue = null) => ['code' => $code, 'label' => $label, 'item' => $item, 'manual' => $manualValue];

        switch ($family) {
            case ProductFamily::CLOUD:
                foreach (['cpu' => 'cpu_1', 'ram' => 'ram_1'] as $logical => $code) {
                    $p = $priced($logical);
                    $add($logical, self::TYPE_QTY, $p ? [$sub($code, ProductFamily::CLOUD_ADDONS[$code]['label'], $p['item'])] : [], 16);
                }
                // Not in the API (fulfilled by hand): the one Cloud price you enter.
                $m = $manualPrice('dedicated_ip');
                if ($m === null) {
                    $skipped[] = self::NAMES['dedicated_ip'] . ': enter its monthly price to sell it (LogicBoxes has no API price for a Cloud dedicated IP; it is set up by hand)';
                } else {
                    $add('dedicated_ip', self::TYPE_YESNO, [$sub('1', 'Dedicated IP address (set up by our team)', 'manual', $m)]);
                }
                break;

            case ProductFamily::WORDPRESS:
                $wp = ProductFamily::addons($family, $key);
                foreach (['cpu' => 'cpu_1', 'ram' => 'ram_1', 'dedicated_ip' => 'ipaddress'] as $logical => $code) {
                    $p = $priced($logical);
                    $add($logical, self::TYPE_QTY, $p ? [$sub($code, $wp[$code]['label'], $p['item'])] : [], $logical === 'dedicated_ip' ? 4 : 16);
                }
                break;

            case ProductFamily::SINGLEDOMAIN:
            case ProductFamily::MULTIDOMAIN:
            case ProductFamily::BUSINESS:
                $p = $priced('dedicated_ip');
                $add('dedicated_ip', self::TYPE_YESNO, $p ? [$sub('1', 'Dedicated IP address', $p['item'])] : []);
                break;

            case ProductFamily::RESELLER:
                $p = $priced('dedicated_ip');
                $add('dedicated_ip', self::TYPE_QTY, $p ? [$sub('1', 'Dedicated IP address', $p['item'])] : [], 10);
                $p = $priced('whmcs');
                $add('whmcs', self::TYPE_YESNO, $p ? [$sub('1', 'WHMCS licence', $p['item'])] : []);
                if (ProductFamily::keyMeta($family, $key)['os'] === 'linux') {
                    // 1 block = 10 cPanel accounts (cpanel-blocks on renew / modify, KB 1321 / 1322); recurring per block.
                    $p = $priced('cpanel_blocks');
                    $add('cpanel_blocks', self::TYPE_QTY, $p ? [$sub('1', 'Block of 10 extra cPanel accounts', $p['item'])] : [], 20);
                }
                break;

            case ProductFamily::VPS:
            case ProductFamily::DEDICATED:
                $allowed = ProductFamily::addons($family, $key);
                // Operating system (free)
                $osList = $family === ProductFamily::DEDICATED ? (PriceCatalog::supportedOs($details) ?: ProductFamily::osList($family, $key)) : ProductFamily::osList($family, $key);
                if ($osList) {
                    $subs = [];
                    foreach ($osList as $code => $label) {
                        $subs[] = $sub($code, $label, 'free');
                    }
                    $specs[] = ['logical' => 'os', 'name' => self::NAMES['os'], 'type' => self::TYPE_DROPDOWN, 'qtymax' => 0, 'subs' => $subs];
                }
                // Control panel (one choice)
                $panels = array_filter($allowed, static fn ($m) => ($m['group'] ?? '') === 'panel');
                if ($panels) {
                    $subs = [$sub('', 'None', 'free')];
                    foreach ($panels as $code => $m) {
                        $p = $price('addon:' . $code);
                        if ($p !== null) {
                            $subs[] = $sub($code, $m['label'], 'addon:' . $code);
                        }
                    }
                    $add('control_panel', self::TYPE_DROPDOWN, $subs);
                }
                if (isset($allowed['ipaddress'])) {
                    $p = $priced('dedicated_ip');
                    $add('dedicated_ip', self::TYPE_QTY, $p ? [$sub('1', 'Additional IP address', $p['item'])] : [], 8);
                }
                if (isset($allowed['whmcs'])) {
                    $p = $priced('whmcs');
                    $add('whmcs', self::TYPE_YESNO, $p ? [$sub('1', 'WHMCS licence', $p['item'])] : []);
                }
                $storage = array_filter($allowed, static fn ($m) => ($m['group'] ?? '') === 'storage');
                $ssd = $family === ProductFamily::VPS ? PriceCatalog::isSsd($details) : null;
                if ($storage && $ssd !== true) { // VPS storage add-ons exist for HDD plans only (KB 2458)
                    $subs = [$sub('', 'None', 'free')];
                    foreach ($storage as $code => $m) {
                        if ($price('addon:' . $code) !== null) {
                            $subs[] = $sub($code, $m['label'], 'addon:' . $code);
                        }
                    }
                    $add('storage', self::TYPE_DROPDOWN, $subs);
                }
                // VPS Block Storage (SAN): a quantity in GB, US location only (KB 3283). LogicBoxes'
                // price list does not document a per-GB price, so it is sold at the price you enter.
                if ($family === ProductFamily::VPS && $key === 'virtualserverlinuxus' && ($m = $manualPrice('block_storage')) !== null) {
                    $specs[] = ['logical' => 'block_storage', 'name' => self::NAMES['block_storage'], 'type' => self::TYPE_QTY, 'qtymax' => 500,
                        'subs' => [$sub('gb', 'GB of block storage (10 - 500, 0 = none)', 'manual', $m)]];
                }
                if ($family === ProductFamily::DEDICATED && trim((string) ($manual['bandwidth_tiers'] ?? '')) !== '') {
                    $subs = [$sub('', 'Included bandwidth', 'free')];
                    foreach (self::parseTiers((string) $manual['bandwidth_tiers']) as $t) {
                        $subs[] = $sub($t['code'], $t['label'] . ' (set up by our team)', 'manual', $t['price']);
                    }
                    $add('bandwidth', self::TYPE_DROPDOWN, $subs);
                }
                break;
        }

        return ['specs' => $specs, 'skipped' => $skipped];
    }

    /**
     * "10TB|10 TB extra = 25" per line -> code 10TB, label "10 TB extra", 25 / month.
     *
     * @return array<int,array{code:string,label:string,price:float}>
     */
    public static function parseTiers(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if (!preg_match('/^\s*([A-Za-z0-9_.-]{1,32})\s*(?:\|\s*([^=]{1,60}?))?\s*=\s*([0-9]+(?:[.,][0-9]+)?)\s*$/', $line, $m)) {
                continue;
            }
            $out[] = ['code' => $m[1], 'label' => trim($m[2] ?? '') !== '' ? trim($m[2]) : $m[1], 'price' => (float) str_replace(',', '.', $m[3])];
        }

        return $out;
    }

    /**
     * Create (or complete) the product's options from specs, without ever
     * duplicating one:
     *   - an option the module already manages is reused (mod_lbh_option_map);
     *   - an option of the same name already on the product (any of its option
     *     groups) is adopted - its sub-options are matched by code;
     *   - only what is still missing is created.
     * The price item of every managed sub-option is brought up to date, so a
     * product moved to another price list, or an option that was priced by
     * hand before LogicBoxes listed it, follows the LogicBoxes price.
     *
     * @param array<int,array<string,mixed>> $specs
     * @return int number of options created
     */
    /** @var string[] notes from the last apply() (options left alone and why) */
    public static array $notes = [];

    public static function apply(object $map, string $groupName, array $specs, bool $adopt = true): int
    {
        self::$notes = [];
        $mapId = (int) $map->id;
        $created = 0;
        $gid = 0;
        $group = static function () use ($map, $mapId, $groupName, &$gid): int {
            if ($gid > 0) {
                return $gid;
            }
            $gid = (int) ($map->config_group_id ?? 0);
            if ($gid <= 0 || !Capsule::table('tblproductconfiggroups')->where('id', $gid)->exists()) {
                $gid = (int) Capsule::table('tblproductconfiggroups')->insertGetId([
                    'name' => mb_substr($groupName, 0, 250),
                    'description' => 'Managed by the LogicBoxes Hosting module - names are read by the module and prices are synced automatically. Do not rename the options.',
                ]);
                Capsule::table('mod_lbh_product_map')->where('id', $mapId)->update(['config_group_id' => $gid]);
            }
            if (!Capsule::table('tblproductconfiglinks')->where('gid', $gid)->where('pid', $map->product_id)->exists()) {
                Capsule::table('tblproductconfiglinks')->insert(['gid' => $gid, 'pid' => (int) $map->product_id]);
            }

            return $gid;
        };

        foreach ($specs as $spec) {
            $existing = Capsule::table('mod_lbh_option_map')->where('map_id', $mapId)->where('logical', $spec['logical'])->first();
            $configId = $existing && Capsule::table('tblproductconfigoptions')->where('id', $existing->config_option_id)->exists() ? (int) $existing->config_option_id : 0;
            if ($configId === 0) {
                [$found, $why] = self::adoptable((int) $map->product_id, $spec);
                if ($found > 0 && $why === '' && $adopt) {
                    $configId = $found;
                    Logger::log('generator', sprintf('Product #%d: existing option "%s" (#%d) adopted - prices are now synced', $map->product_id, $spec['name'], $configId));
                } elseif ($found > 0) {
                    // An option of that name is already on the product: never add a second one.
                    self::$notes[] = sprintf('Product #%d: "%s" was not created - the product already has an option of that name (#%d)%s.', $map->product_id, $spec['name'], $found,
                        $why !== '' ? ', ' . $why : ', made by hand: press "Create missing options" on the product\'s page to let the module price it');
                    Logger::log('generator', end(self::$notes), 'warning');
                    continue;
                }
            }
            if ($configId === 0) {
                $g = $group();
                $order = (int) Capsule::table('tblproductconfigoptions')->where('gid', $g)->max('order');
                $configId = (int) Capsule::table('tblproductconfigoptions')->insertGetId([
                    'gid' => $g,
                    'optionname' => $spec['name'],
                    'optiontype' => (int) $spec['type'],
                    'qtyminimum' => 0,
                    'qtymaximum' => (int) $spec['qtymax'],
                    'order' => $order + 1,
                    'hidden' => 0,
                ]);
                $created++;
            }
            $sort = (int) Capsule::table('tblproductconfigoptionssub')->where('configid', $configId)->max('sortorder');
            foreach ($spec['subs'] as $s) {
                $have = Capsule::table('mod_lbh_option_map')->where('map_id', $mapId)->where('config_option_id', $configId)->where('code', $s['code'])->first();
                if ($have && Capsule::table('tblproductconfigoptionssub')->where('id', $have->config_sub_id)->exists()) {
                    $upd = [];
                    if ((string) $have->price_item !== (string) $s['item']) {
                        $upd['price_item'] = $s['item']; // e.g. manual -> LogicBoxes item, or another price list
                    }
                    if ($s['item'] === 'manual' && $s['manual'] !== null) {
                        $upd['manual_price'] = $s['manual'];
                    } elseif ($s['item'] !== 'manual' && $have->manual_price !== null) {
                        $upd['manual_price'] = null;
                    }
                    if ($upd) {
                        Capsule::table('mod_lbh_option_map')->where('id', $have->id)->update($upd);
                    }
                    continue;
                }
                $subId = self::matchingSub($configId, (int) $spec['type'], (string) $s['code']);
                if ($subId > 0 && Capsule::table('mod_lbh_option_map')->where('config_sub_id', $subId)->exists()) {
                    $subId = 0; // already managed under another mapping: never share a sub-option
                }
                if ($subId === 0) {
                    $subName = (int) $spec['type'] === self::TYPE_DROPDOWN ? ($s['code'] === '' ? 'none|' . $s['label'] : $s['code'] . '|' . $s['label']) : $s['label'];
                    $subId = (int) Capsule::table('tblproductconfigoptionssub')->insertGetId([
                        'configid' => $configId,
                        'optionname' => $subName,
                        'sortorder' => ++$sort,
                        'hidden' => 0,
                    ]);
                }
                Capsule::table('mod_lbh_option_map')->insert([
                    'map_id' => $mapId,
                    'logical' => $spec['logical'],
                    'config_option_id' => $configId,
                    'config_sub_id' => $subId,
                    'code' => $s['code'],
                    'price_item' => $s['item'],
                    'manual_price' => $s['manual'],
                ]);
            }
        }
        try {
            if ($specs && Capsule::schema()->hasColumn('tblproducts', 'configoptionsupgrade')) {
                // WHMCS's own option-upgrade page only in "whmcs" upgrade mode; otherwise the module's provider-priced flow is used.
                Capsule::table('tblproducts')->where('id', $map->product_id)->update(['configoptionsupgrade' => \LbHosting\Service\UpgradeService::enabled() ? 0 : 1]);
            }
        } catch (\Throwable $e) {
        }
        if ($created) {
            Logger::log('generator', sprintf('Product #%d: %d configurable option(s) created', $map->product_id, $created));
        }

        return $created;
    }

    /**
     * Complete the options of an EXISTING mapped product from the current
     * price list: options LogicBoxes has started to price are created, price
     * items are refreshed, nothing is duplicated. Manual-only items keep the
     * price that was entered; bandwidth tiers are left as they are.
     *
     * @return array{created:int, skipped:string[]}
     */
    public static function ensure(object $map, bool $adopt = true): array
    {
        $manual = [];
        foreach (ProductMap::options((int) $map->id) as $o) {
            if ($o->price_item === 'manual' && $o->manual_price !== null && self::isManualOnly((string) $map->family, (string) $o->logical)) {
                $manual[(string) $o->logical] = (string) $o->manual_price;
            }
        }
        $res = self::specs((string) $map->family, (string) $map->product_key, (int) $map->account_id, (int) $map->plan_id, $manual, PriceCatalog::source($map), ProductMap::bindings($map));
        $label = ProductFamily::label((string) $map->family) . ' - ' . $map->plan_name . ' (' . ProductFamily::keyLabel((string) $map->family, (string) $map->product_key) . ')';
        $created = self::apply($map, $label, $res['specs'], $adopt);
        $notes = self::$notes;
        // Manual-only options that were not re-specced above (no price given now) are simply kept.
        $skipped = array_values(array_filter($res['skipped'], static function ($msg) use ($map) {
            foreach (ProductMap::options((int) $map->id) as $o) {
                if (str_starts_with($msg, self::NAMES[$o->logical] ?? '~')) {
                    return false; // the option exists already (e.g. priced by hand before) - reported by the sync instead
                }
            }

            return true;
        }));

        return ['created' => $created, 'skipped' => array_merge($skipped, $notes)];
    }

    /**
     * An option already on the product (any linked group) with this logical
     * option's name. Returns [option id, reason it cannot be adopted ('' = it can)].
     *
     * Not adoptable: an option in a group shared with other products (one
     * price per plan is impossible there), an option another mapping manages,
     * a dropdown whose choices are not "code|Label" for the codes the module sells.
     *
     * @param array<string,mixed> $spec
     * @return array{0:int,1:string}
     */
    private static function adoptable(int $productId, array $spec): array
    {
        $logical = (string) $spec['logical'];
        $type = (int) $spec['type'];
        $names = array_merge([strtolower(self::NAMES[$logical] ?? $logical)], \LbHosting\WHMCS\OptionMap::ALIASES[$logical] ?? []);
        $gids = Capsule::table('tblproductconfiglinks')->where('pid', $productId)->pluck('gid')->all();
        if (!$gids) {
            return [0, ''];
        }
        foreach (Capsule::table('tblproductconfigoptions')->whereIn('gid', $gids)->orderBy('id')->get() as $o) {
            $n = strtolower(trim(explode('|', (string) $o->optionname)[0]));
            if (!in_array($n, $names, true)) {
                continue;
            }
            if (Capsule::table('mod_lbh_option_map')->where('config_option_id', $o->id)->exists()) {
                return [(int) $o->id, 'which the module already prices for another product (its option group is shared) - give this product its own option group'];
            }
            if (Capsule::table('tblproductconfiglinks')->where('gid', $o->gid)->where('pid', '!=', $productId)->exists()) {
                return [(int) $o->id, 'in an option group shared with other products, so it cannot carry this plan\'s price - give this product its own option group, or delete the option'];
            }
            $isQty = in_array((int) $o->optiontype, [3, 4], true);
            if ($type === self::TYPE_DROPDOWN ? (int) $o->optiontype !== self::TYPE_DROPDOWN : !$isQty) {
                return [(int) $o->id, 'of a different type'];
            }
            if ($type === self::TYPE_DROPDOWN) {
                $codes = array_map(static fn ($s) => $s['code'] === '' ? 'none' : strtolower((string) $s['code']), $spec['subs']);
                foreach (Capsule::table('tblproductconfigoptionssub')->where('configid', $o->id)->get() as $sub) {
                    $c = strtolower(trim(explode('|', (string) $sub->optionname)[0]));
                    if (!in_array($c === '' ? 'none' : $c, $codes, true)) {
                        return [(int) $o->id, 'whose choice "' . $sub->optionname . '" is not one the module sells (name choices "code|Label")'];
                    }
                }
            }

            return [(int) $o->id, ''];
        }

        return [0, ''];
    }

    /** Sub-option of an adopted option that stands for this code (dropdown: "code|Label"; quantity / yes-no: the first one). */
    private static function matchingSub(int $configId, int $type, string $code): int
    {
        $subs = Capsule::table('tblproductconfigoptionssub')->where('configid', $configId)->orderBy('sortorder')->orderBy('id')->get()->all();
        if (!$subs) {
            return 0;
        }
        if ($type !== self::TYPE_DROPDOWN) {
            return (int) $subs[0]->id;
        }
        $want = $code === '' ? ['none', '', '0', 'no'] : [strtolower($code)];
        foreach ($subs as $sub) {
            if (in_array(strtolower(trim(explode('|', (string) $sub->optionname)[0])), $want, true)) {
                return (int) $sub->id;
            }
        }

        return 0;
    }
}
