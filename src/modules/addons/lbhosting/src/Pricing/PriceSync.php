<?php

declare(strict_types=1);

namespace LbHosting\Pricing;

use Illuminate\Database\Capsule\Manager as Capsule;
use LbHosting\Accounts\AccountRepository;
use LbHosting\Api\HttpTransport;
use LbHosting\Products\OptionBuilder;
use LbHosting\Products\ProductMap;
use LbHosting\Support\Logger;
use LbHosting\Support\Settings;

/**
 * "Auto Sync for Selling prices and Recurring prices".
 *
 * preview() computes every change without writing; apply() writes exactly what
 * preview() returned. Products and option sub-options go to tblpricing;
 * existing services' recurring amounts (tblhosting.amount) are only touched
 * when the mapping has update_recurring on AND the amount is still
 * module-managed (isManaged()) - a hand-set price is never overwritten.
 */
final class PriceSync
{
    private ?HttpTransport $transport;

    public function __construct(?HttpTransport $transport = null)
    {
        $this->transport = $transport;
    }

    /**
     * @param int[]|null $mapIds null = every mapping with sync_prices on
     * @return array{changes: array<int,array<string,mixed>>, warnings: string[]}
     */
    public function preview(?array $mapIds = null, bool $refreshCatalog = false): array
    {
        $maps = array_values(array_filter((new ProductMap())->all(), static fn ($m) => $mapIds === null ? (bool) $m->sync_prices : in_array((int) $m->id, $mapIds, true)));
        $warnings = [];

        if ($refreshCatalog) {
            $accounts = new AccountRepository();
            foreach (array_unique(array_map(static fn ($m) => (int) $m->account_id, $maps)) as $accId) {
                $acc = $accounts->findActive($accId);
                if (!$acc) {
                    $warnings[] = 'API account #' . $accId . ' is missing or disabled - stored prices used.';
                    continue;
                }
                $res = (new PriceCatalog($this->transport))->refresh($acc);
                if (isset($res['errors']['prices'])) {
                    $warnings[] = sprintf('Could not refresh prices for "%s": %s - stored prices used.', $acc->name, $res['errors']['prices']);
                }
            }
        }

        $changes = [];
        foreach ($maps as $map) {
            $planner = new PricePlanner($map);
            if (!$planner->hasPrices()) {
                $warnings[] = sprintf('No prices found for plan %d (%s) - product #%d left unchanged.', $map->plan_id, $map->product_key, $map->product_id);
                continue;
            }
            $targets = $planner->targets();
            foreach ($targets['product'] as $cid => $cycles) {
                $changes = array_merge($changes, self::diffPricing('product', (int) $map->product_id, $cid, $cycles, (int) $map->id));
            }
            foreach ($targets['options'] as $subId => $byCurrency) {
                foreach ($byCurrency as $cid => $cycles) {
                    $changes = array_merge($changes, self::diffPricing('configoptions', (int) $subId, $cid, $cycles, (int) $map->id));
                }
            }
            foreach (ProductMap::options((int) $map->id) as $opt) {
                if ($opt->price_item === 'manual' && !OptionBuilder::isManualOnly((string) $map->family, (string) $opt->logical)) {
                    $warnings[] = sprintf('Product #%d option "%s" is priced by hand: LogicBoxes lists no price item for it under a known name. Choose the item on the product\'s page so it follows the LogicBoxes price.', $map->product_id, OptionBuilder::NAMES[$opt->logical] ?? $opt->logical);
                }
                if (!isset($targets['options'][(int) $opt->config_sub_id])) {
                    $warnings[] = sprintf('Product #%d option "%s" (%s): LogicBoxes no longer lists a price - its WHMCS price was left unchanged.', $map->product_id, $opt->logical, $opt->code ?: $opt->price_item);
                }
            }
            if ($map->update_recurring) {
                $changes = array_merge($changes, $this->serviceChanges($map, $planner, $targets));
            }
        }

        return ['changes' => $changes, 'warnings' => array_values(array_unique($warnings))];
    }

    /** @param array<int,array<string,mixed>> $changes */
    public function apply(array $changes, string $source): int
    {
        $now = date('Y-m-d H:i:s');
        $n = 0;
        foreach ($changes as $c) {
            if ($c['target'] === 'service') {
                $current = Capsule::table('tblhosting')->where('id', $c['relid'])->value('amount');
                if ($current === null || abs((float) $current - (float) $c['old']) > 0.001) {
                    continue; // edited since the preview
                }
                Capsule::table('tblhosting')->where('id', $c['relid'])->update(['amount' => $c['new']]);
                Capsule::table('mod_lbh_services')->where('service_id', $c['relid'])->update(['priced_amount' => $c['new'], 'updated_at' => $now]);
            } else {
                $q = static fn () => Capsule::table('tblpricing')->where('type', $c['target'])->where('relid', $c['relid'])->where('currency', $c['currency']);
                if ($q()->exists()) {
                    $q()->update([$c['cycle'] => $c['new']]);
                } else {
                    Capsule::table('tblpricing')->insert(array_merge(['type' => $c['target'], 'relid' => $c['relid'], 'currency' => $c['currency']], self::emptyPricingRow($c['target']), [$c['cycle'] => $c['new']]));
                }
            }
            Capsule::table('mod_lbh_price_changes')->insert([
                'target' => $c['target'] === 'configoptions' ? 'configoption' : $c['target'],
                'relid' => $c['relid'],
                'currency_id' => $c['currency'] ?? null,
                'cycle' => $c['cycle'] ?? null,
                'old_price' => $c['old'],
                'new_price' => $c['new'],
                'source' => $source,
                'created_at' => $now,
            ]);
            $n++;
        }
        foreach (array_unique(array_filter(array_map(static fn ($c) => $c['map_id'] ?? null, $changes))) as $mapId) {
            Capsule::table('mod_lbh_product_map')->where('id', $mapId)->update(['last_synced_at' => $now]);
        }
        if ($n) {
            Logger::log('price_sync', sprintf('Price sync (%s) wrote %d change(s)', $source, $n));
        }

        return $n;
    }

    /** Daily cron entry point (kept for compatibility). @return array{written:int, warnings:string[]} */
    public function runScheduled(bool $refresh): array
    {
        $r = $this->autoSync(true, $refresh);

        return ['written' => $r['written'], 'warnings' => $r['warnings']];
    }

    /**
     * Every mapping with price sync on: create options LogicBoxes now prices
     * (Settings > Create missing options), then write every price that differs.
     *
     * @return array{written:int, options:int, warnings:string[]}
     */
    public function syncAll(string $source): array
    {
        $options = 0;
        $warnings = [];
        // The automatic run never takes over options made by hand; an admin action does (generator, "Create missing options").
        $adopt = $source !== 'auto';
        if (Settings::bool('price_sync_options')) {
            foreach ((new ProductMap())->all() as $map) {
                if (!$map->sync_prices || !Capsule::table('tblproducts')->where('id', $map->product_id)->exists()) {
                    continue;
                }
                try {
                    $r = OptionBuilder::ensure($map, $adopt);
                    $options += $r['created'];
                    $warnings = array_merge($warnings, array_values(array_filter($r['skipped'], static fn ($m) => str_contains($m, 'already has an option'))));
                } catch (\Throwable $e) {
                    $warnings[] = sprintf('Product #%d: options could not be completed: %s', $map->product_id, $e->getMessage());
                }
            }
        }
        $preview = $this->preview(null, false);
        $written = $this->apply($preview['changes'], $source);
        $warnings = array_values(array_unique(array_merge($warnings, $preview['warnings'])));
        $now = date('Y-m-d H:i:s');
        foreach ((new ProductMap())->all() as $map) {
            if ($map->sync_prices) {
                Capsule::table('mod_lbh_product_map')->where('id', $map->id)->update(['last_synced_at' => $now]); // checked, even when nothing changed
            }
        }
        Settings::set('price_sync_pending', '0');

        return ['written' => $written, 'options' => $options, 'warnings' => $warnings];
    }

    /**
     * "Synchronise whenever the source prices change": fetch the LogicBoxes
     * price lists every N hours (Settings > Price check interval; WHMCS cron),
     * and when any price changed - or a product was never synced - apply the
     * new prices to products, options and (where enabled) existing services.
     *
     * @return array{ran:bool, changed:bool, written:int, options:int, warnings:string[]}
     */
    public function autoSync(bool $force = false, bool $refresh = true): array
    {
        $out = ['ran' => false, 'changed' => false, 'written' => 0, 'options' => 0, 'warnings' => []];
        if (!Settings::bool('price_sync_enabled')) {
            return $out;
        }
        $hours = max(1, (int) Settings::get('price_sync_interval'));
        $last = strtotime((string) Settings::get('price_sync_last')) ?: 0;
        if (!$force && $last > time() - $hours * 3600 + 60) {
            return $out;
        }
        Settings::set('price_sync_last', date('Y-m-d H:i:s'));
        $out['ran'] = true;
        $maps = (new ProductMap())->all();
        if ($refresh && Settings::bool('price_sync_refresh')) {
            $accounts = new AccountRepository();
            foreach (array_unique(array_map(static fn ($m) => (int) $m->account_id, $maps)) as $accId) {
                $acc = $accounts->findActive($accId);
                if (!$acc) {
                    $out['warnings'][] = 'API account #' . $accId . ' is missing or disabled - stored prices used.';
                    continue;
                }
                $res = (new PriceCatalog($this->transport))->refresh($acc);
                $out['changed'] = $out['changed'] || $res['changed'];
                foreach ($res['errors'] as $what => $err) {
                    $out['warnings'][] = sprintf('Could not refresh %s for "%s": %s - stored prices used.', $what, $acc->name, $err);
                }
            }
        }
        $never = (bool) array_filter($maps, static fn ($m) => $m->sync_prices && empty($m->last_synced_at));
        $pending = Settings::bool('price_sync_pending'); // a change found earlier whose sync did not finish
        if ($force || $out['changed'] || $never || $pending) {
            $r = $this->syncAll('auto');
            $out['written'] = $r['written'];
            $out['options'] = $r['options'];
            $out['warnings'] = array_merge($out['warnings'], $r['warnings']);
        }
        foreach ($out['warnings'] as $w) {
            Logger::log('price_sync', $w, 'warning');
        }

        return $out;
    }

    /**
     * @param array<string,float> $target
     * @return array<int,array<string,mixed>>
     */
    private static function diffPricing(string $type, int $relid, int $currencyId, array $target, int $mapId): array
    {
        $row = Capsule::table('tblpricing')->where('type', $type)->where('relid', $relid)->where('currency', $currencyId)->first();
        $out = [];
        foreach ($target as $cycle => $price) {
            $old = $row && isset($row->$cycle) ? (float) $row->$cycle : null;
            if ($old === null || abs($old - $price) > 0.001) {
                $out[] = ['target' => $type, 'relid' => $relid, 'currency' => $currencyId, 'cycle' => $cycle, 'old' => $old, 'new' => $price, 'map_id' => $mapId];
            }
        }

        return $out;
    }

    /** @param array<string,mixed> $targets @return array<int,array<string,mixed>> */
    private function serviceChanges(object $map, PricePlanner $planner, array $targets): array
    {
        $out = [];
        $services = Capsule::table('tblhosting')
            ->join('tblclients', 'tblclients.id', '=', 'tblhosting.userid')
            ->where('tblhosting.packageid', $map->product_id)
            ->whereIn('tblhosting.domainstatus', ['Active', 'Suspended'])
            ->select(['tblhosting.id', 'tblhosting.amount', 'tblhosting.billingcycle', 'tblclients.currency'])
            ->get();
        foreach ($services as $s) {
            $serviceId = (int) $s->id;
            $new = $planner->serviceAmount($serviceId, (string) $s->billingcycle, (int) $s->currency, $targets);
            if ($new === null || abs((float) $s->amount - $new) < 0.005) {
                continue;
            }
            if (!self::isManaged($serviceId, (float) $s->amount, (string) $s->billingcycle, (int) $s->currency, $map)) {
                continue;
            }
            $out[] = ['target' => 'service', 'relid' => $serviceId, 'currency' => (int) $s->currency, 'cycle' => strtolower((string) $s->billingcycle), 'old' => (float) $s->amount, 'new' => $new, 'map_id' => (int) $map->id];
        }

        return $out;
    }

    /**
     * Module-managed = equal to what the module last wrote (priced_amount) or,
     * never written yet, to what the product's CURRENT WHMCS prices give for
     * the service (product price + its selected options). Anything else is a
     * manual price and is left alone.
     */
    public static function isManaged(int $serviceId, float $amount, string $cycle, int $currencyId, object $map): bool
    {
        $priced = Capsule::table('mod_lbh_services')->where('service_id', $serviceId)->value('priced_amount');
        if ($priced !== null) {
            return abs((float) $priced - $amount) < 0.005;
        }
        $cycle = strtolower(str_replace(['-', ' '], '', $cycle));
        if (!in_array($cycle, PriceCalculator::CYCLES, true)) {
            return false;
        }
        $p = Capsule::table('tblpricing')->where('type', 'product')->where('relid', $map->product_id)->where('currency', $currencyId)->value($cycle);
        if ($p === null || (float) $p < 0) {
            return false;
        }
        $total = (float) $p;
        foreach (Capsule::table('tblhostingconfigoptions')->where('relid', $serviceId)->get() as $sel) {
            $unit = Capsule::table('tblpricing')->where('type', 'configoptions')->where('relid', $sel->optionid)->where('currency', $currencyId)->value($cycle);
            if ($unit === null) {
                continue;
            }
            $type = (int) Capsule::table('tblproductconfigoptions')->where('id', $sel->configid)->value('optiontype');
            $total += $type === 4 ? (float) $unit * (int) $sel->qty : ($type === 3 ? ((int) $sel->qty > 0 ? (float) $unit : 0) : (float) $unit);
        }

        return abs($total - $amount) < 0.005;
    }

    /** @return array<string,float> */
    private static function emptyPricingRow(string $type): array
    {
        $row = ['msetupfee' => 0, 'qsetupfee' => 0, 'ssetupfee' => 0, 'asetupfee' => 0, 'bsetupfee' => 0, 'tsetupfee' => 0];
        foreach (PriceCalculator::CYCLES as $cycle) {
            $row[$cycle] = $type === 'product' ? -1 : 0;
        }

        return $row;
    }
}
