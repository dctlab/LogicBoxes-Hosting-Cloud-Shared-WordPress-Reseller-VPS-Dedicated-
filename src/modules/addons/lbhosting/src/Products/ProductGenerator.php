<?php

declare(strict_types=1);

namespace LbHosting\Products;

use Illuminate\Database\Capsule\Manager as Capsule;
use LbHosting\Catalog\ProductFamily;
use LbHosting\Pricing\PriceCatalog;
use LbHosting\Pricing\PriceSync;
use LbHosting\Support\Logger;
use LbHosting\WHMCS\ProductConfig;

/**
 * "Fully automated Products Setup with Selling Price Import".
 *
 * For each selected plan: create (or, when already mapped, update) one WHMCS
 * product with its module settings, its configurable options (OptionBuilder),
 * prices in every WHMCS currency, and optional upgrade paths between plans of
 * the same location. Re-running never duplicates anything.
 */
final class ProductGenerator
{
    public const HEADLINES = [
        ProductFamily::CLOUD => 'Fast, scalable cloud hosting with cPanel',
        ProductFamily::MULTIDOMAIN => 'Host all your websites on one plan',
        ProductFamily::SINGLEDOMAIN => 'Simple, reliable hosting for one website',
        ProductFamily::RESELLER => 'Start your own hosting business',
        ProductFamily::WORDPRESS => 'Managed WordPress hosting, tuned for speed',
        ProductFamily::BUSINESS => 'High-performance hosting for growing businesses',
        ProductFamily::VPS => 'KVM virtual private servers with full root access',
        ProductFamily::DEDICATED => 'Dedicated and fully managed servers',
    ];

    private ProductMap $maps;

    public function __construct(?ProductMap $maps = null)
    {
        $this->maps = $maps ?? new ProductMap();
    }

    /**
     * @param array<int, array{product_key:string, plan_id:int, plan_name:string}> $plans
     * @param array<string,mixed> $opts group_id|new_group, name_pattern, hidden, create_options,
     *                                  manual[], bandwidth_tiers, upgrade_paths + ProductMap::validateSettings() keys
     * @return array{created:int[], updated:int[], options:int, prices:int, upgrades:int, messages:string[]}
     */
    public function generate(string $family, int $accountId, array $plans, array $opts): array
    {
        ProductFamily::assert($family);
        if (!$plans) {
            throw new \InvalidArgumentException('Select at least one plan.');
        }
        $groupId = $this->resolveGroup($family, $opts);
        $settings = ProductMap::validateSettings($opts);
        $pattern = trim((string) ($opts['name_pattern'] ?? '')) ?: '{plan} ({location})';
        $manual = (array) ($opts['manual'] ?? []);
        $manual['bandwidth_tiers'] = (string) ($opts['bandwidth_tiers'] ?? '');
        $report = ['created' => [], 'updated' => [], 'options' => 0, 'prices' => 0, 'upgrades' => 0, 'messages' => []];
        $mapIds = [];

        foreach ($plans as $p) {
            $key = strtolower((string) $p['product_key']);
            if (ProductFamily::forKey($key) !== $family) {
                throw new \InvalidArgumentException('Plan ' . $p['plan_id'] . ' (' . $key . ') does not belong to ' . ProductFamily::label($family) . '.');
            }
            $planId = (int) $p['plan_id'];
            $planName = trim((string) ($p['plan_name'] ?? '')) ?: 'Plan ' . $planId;
            $existing = $this->maps->forPlan($accountId, $key, $planId);
            $kind = $existing ? PriceCatalog::source($existing) : (string) $settings['price_source'];
            if (!PriceCatalog::hasPlanPrice($accountId, $key, $planId, $kind)) {
                $report['messages'][] = sprintf('%s (%s, plan %d): no price in the LogicBoxes %s list - skipped, no product created or changed. Fetch prices, or choose the other price source.', $planName, ProductFamily::keyLabel($family, $key), $planId, $kind === 'cost' ? 'cost price' : 'customer price');
                continue;
            }
            if ($existing && Capsule::table('tblproducts')->where('id', $existing->product_id)->exists()) {
                // An existing product keeps its price source (switch it with "Switch to cost price"); the rest of the settings follow the form.
                $keep = $settings;
                unset($keep['price_source']);
                if (PriceCatalog::source($existing) === 'sell' || (float) $keep['markup_pct'] > 0) {
                    $this->maps->save((int) $existing->id, $keep + ['plan_name' => $planName]);
                }
                $mapId = (int) $existing->id;
                $report['updated'][] = (int) $existing->product_id;
            } else {
                if ($existing) {
                    $this->maps->delete((int) $existing->id); // product deleted in WHMCS - recreate
                }
                $name = trim((string) preg_replace('/\s+/', ' ', strtr($pattern, [
                    '{plan}' => $planName,
                    '{location}' => ProductFamily::keyLabel($family, $key),
                    '{product}' => ProductFamily::label($family),
                ])));
                $pid = $this->createProduct($family, $groupId, $name, $accountId, $key, $planId, !empty($opts['hidden']));
                $mapId = $this->maps->save(null, $settings + [
                    'family' => $family, 'product_id' => $pid, 'account_id' => $accountId,
                    'product_key' => $key, 'plan_id' => $planId, 'plan_name' => $planName,
                ]);
                $report['created'][] = $pid;
                Logger::log('generator', sprintf('Created product #%d "%s" (%s, plan %d)', $pid, $name, $key, $planId));
            }
            if (!empty($opts['create_options'])) {
                $map = $this->maps->find($mapId);
                $res = OptionBuilder::specs($family, $key, $accountId, $planId, $manual, (string) $settings['price_source'], ProductMap::bindings($map));
                $report['options'] += OptionBuilder::apply($map, ProductFamily::label($family) . ' - ' . $planName . ' (' . ProductFamily::keyLabel($family, $key) . ')', $res['specs']);
                foreach ($res['skipped'] as $s) {
                    $report['messages'][] = $planName . ' - option skipped: ' . $s;
                }
            }
            $mapIds[] = $mapId;
        }

        $sync = new PriceSync();
        $preview = $sync->preview($mapIds, false);
        $report['prices'] = $sync->apply(array_values(array_filter($preview['changes'], static fn ($c) => $c['target'] !== 'service')), 'generator');
        $report['messages'] = array_merge($report['messages'], $preview['warnings']);
        if (ProductFamily::can($family, 'plan_change') && !empty($opts['upgrade_paths'])) {
            $report['upgrades'] = self::setUpgradePaths($family, $accountId);
        }
        \LbHosting\Service\UpgradeService::enforceMode();

        return $report;
    }

    /**
     * Attach an existing product (plan set in its module settings) to price
     * sync; optionally build its configurable options too.
     *
     * @param array<string,mixed> $opts
     */
    public function mapExisting(int $productId, array $opts): int
    {
        $p = Capsule::table('tblproducts')->where('id', $productId)->whereIn('servertype', ProductFamily::modules())->first();
        if (!$p) {
            throw new \InvalidArgumentException('Product not found or not using a LogicBoxes Hosting module.');
        }
        $family = (string) ProductFamily::forModule((string) $p->servertype);
        [$key, $planId] = ProductConfig::parsePlan($family, (string) $p->configoption2);
        if (!$planId) {
            throw new \InvalidArgumentException('The product has no plan selected in its Module Settings.');
        }
        $accountId = (int) $p->configoption1 ?: (int) Capsule::table('mod_lbh_accounts')->orderBy('is_default', 'desc')->value('id');
        if ($this->maps->forProduct($productId)) {
            throw new \InvalidArgumentException('This product is already mapped.');
        }
        if ($this->maps->forPlan($accountId, $key, $planId)) {
            throw new \InvalidArgumentException('Another product is already mapped to this plan.');
        }
        $mapId = $this->maps->save(null, ProductMap::validateSettings($opts) + [
            'family' => $family, 'product_id' => $productId, 'account_id' => $accountId, 'product_key' => $key, 'plan_id' => $planId, 'plan_name' => (string) $p->name,
        ]);
        if (!empty($opts['create_options'])) {
            $map = $this->maps->find($mapId);
            $res = OptionBuilder::specs($family, $key, $accountId, $planId, (array) ($opts['manual'] ?? []) + ['bandwidth_tiers' => (string) ($opts['bandwidth_tiers'] ?? '')], PriceCatalog::source($map), ProductMap::bindings($map));
            OptionBuilder::apply($map, ProductFamily::label($family) . ' - ' . $p->name, $res['specs']);
        }
        Logger::log('generator', 'Mapped existing product #' . $productId . ' to ' . $key . ' plan ' . $planId);

        return $mapId;
    }

    /**
     * WHMCS upgrade paths (tblproduct_upgrade_products, PATTERN WHMCS 7-9
     * schema) between mapped products of the SAME location (product key):
     * Single/Multi Domain and Reseller both ways, VPS only to plans with a
     * higher annual price (LogicBoxes refuses VPS downgrades). Cloud and
     * Dedicated have no plan-change API, so no paths.
     */
    public static function setUpgradePaths(string $family, int $accountId): int
    {
        if (!ProductFamily::can($family, 'plan_change') || \LbHosting\Service\UpgradeService::enabled()) {
            return 0; // provider-priced mode: plan changes go through the module's own flow, not WHMCS upgrade paths
        }
        try {
            if (!Capsule::schema()->hasTable('tblproduct_upgrade_products')) {
                return 0;
            }
        } catch (\Throwable $e) {
            return 0;
        }
        $maps = array_values(array_filter((new ProductMap())->all($family), static fn ($m) => (int) $m->account_id === $accountId));
        $rank = [];
        foreach ($maps as $m) {
            $calc = PriceCatalog::calculator($accountId, (string) $m->product_key, (int) $m->plan_id, PriceCatalog::source($m));
            $rank[(int) $m->id] = $calc->cyclePrice(12) ?? ($calc->tenures() ? $calc->cyclePrice($calc->tenures()[0]) / $calc->tenures()[0] * 12 : null);
        }
        $n = 0;
        foreach ($maps as $from) {
            foreach ($maps as $to) {
                if ((int) $to->product_id === (int) $from->product_id || $to->product_key !== $from->product_key) {
                    continue;
                }
                if (!ProductFamily::can($family, 'downgrade')) {
                    $a = $rank[(int) $from->id];
                    $b = $rank[(int) $to->id];
                    if ($a === null || $b === null || $b <= $a) {
                        continue;
                    }
                }
                if (!Capsule::table('tblproduct_upgrade_products')->where('product_id', $from->product_id)->where('upgrade_product_id', $to->product_id)->exists()) {
                    Capsule::table('tblproduct_upgrade_products')->insert(['product_id' => (int) $from->product_id, 'upgrade_product_id' => (int) $to->product_id]);
                    $n++;
                }
            }
        }
        if ($n) {
            Logger::log('generator', sprintf('Added %d %s upgrade path(s)', $n, ProductFamily::label($family)));
        }

        return $n;
    }

    /** @param array<string,mixed> $opts */
    private function resolveGroup(string $family, array $opts): int
    {
        $gid = (int) ($opts['group_id'] ?? 0);
        if ($gid > 0) {
            if (!Capsule::table('tblproductgroups')->where('id', $gid)->exists()) {
                throw new \InvalidArgumentException('Product group not found.');
            }

            return $gid;
        }
        $name = trim((string) ($opts['new_group'] ?? ''));
        if ($name === '' || mb_strlen($name) > 150) {
            throw new \InvalidArgumentException('Choose a product group or enter a name for a new one.');
        }
        $schema = Capsule::schema();
        $row = [
            'name' => $name,
            'headline' => self::HEADLINES[$family],
            'tagline' => '',
            'orderfrmtpl' => '',
            'disabledgateways' => '',
            'hidden' => 0,
            'order' => (int) Capsule::table('tblproductgroups')->max('order') + 1,
        ];
        if ($schema->hasColumn('tblproductgroups', 'slug')) {
            $row['slug'] = self::uniqueSlug($name);
        }
        if ($schema->hasColumn('tblproductgroups', 'created_at')) {
            $row['created_at'] = $row['updated_at'] = date('Y-m-d H:i:s');
        }

        return (int) Capsule::table('tblproductgroups')->insertGetId($row);
    }

    private function createProduct(string $family, int $groupId, string $name, int $accountId, string $key, int $planId, bool $hidden): int
    {
        $server = ProductFamily::isServer($family);
        $defaultOs = '';
        if ($server) {
            $os = ProductFamily::osList($family, $key);
            if ($family === ProductFamily::DEDICATED) {
                $os = PriceCatalog::supportedOs(PriceCatalog::planDetails($accountId, $key, $planId)) ?: $os;
            }
            $defaultOs = (string) (ProductFamily::defaultOs($family, $key) ?? (array_key_first($os) ?? ''));
        }
        $module = [
            'configoption1' => (string) $accountId,
            'configoption2' => ProductConfig::planValue($key, $planId),
            'configoption3' => 'NoInvoice',
            'configoption4' => $defaultOs,
            'configoption5' => '',
        ];
        $moduleName = ProductFamily::module($family);
        // WHMCS product types: hostingaccount | reselleraccount | server | other (VERIFIED)
        $type = $server ? 'server' : ($family === ProductFamily::RESELLER ? 'reselleraccount' : 'hostingaccount');
        $res = localAPI('AddProduct', array_merge([
            'type' => $type,
            'gid' => $groupId,
            'name' => $name,
            'paytype' => 'recurring',
            'module' => $moduleName,
            'autosetup' => 'payment',
            'showdomainoptions' => !$server,
            'hidden' => $hidden,
            'description' => self::description($family, $key, $accountId, $planId),
        ], $module));
        if (($res['result'] ?? '') !== 'success' || empty($res['pid'])) {
            throw new \RuntimeException('WHMCS AddProduct failed for "' . $name . '": ' . ($res['message'] ?? 'unknown error'));
        }
        $pid = (int) $res['pid'];
        // Make sure module + settings are stored even if this WHMCS version's AddProduct ignores some parameters.
        Capsule::table('tblproducts')->where('id', $pid)->update($module + ['servertype' => $moduleName, 'showdomainoptions' => $server ? 0 : 1, 'autosetup' => 'payment', 'paytype' => 'recurring']);

        return $pid;
    }

    /** Short product description from the plan details (servers: CPU / RAM / disk / bandwidth). */
    public static function description(string $family, string $key, int $accountId, int $planId): string
    {
        $d = PriceCatalog::planDetails($accountId, $key, $planId);
        $lines = [ProductFamily::label($family) . ' - ' . ProductFamily::keyLabel($family, $key)];
        $cpu = $d['cpu']['cpu_cores'] ?? ($d['cpu'] ?? null);
        $ram = $d['ram']['ram_memory'] ?? ($d['ram'] ?? null);
        $disk = $d['storage']['storage_space'] ?? ($d['space'] ?? ($d['diskspace'] ?? null));
        $bw = $d['bandwidth'] ?? null;
        foreach (['CPU cores' => $cpu, 'RAM' => $ram, 'Storage' => $disk, 'Bandwidth' => $bw] as $label => $v) {
            if (is_scalar($v) && (string) $v !== '') {
                $lines[] = $label . ': ' . $v;
            }
        }

        return implode("\n", $lines);
    }

    private static function uniqueSlug(string $name): string
    {
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-') ?: 'logicboxes';
        $slug = $base;
        $i = 2;
        while (Capsule::table('tblproductgroups')->where('slug', $slug)->exists()) {
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }
}
