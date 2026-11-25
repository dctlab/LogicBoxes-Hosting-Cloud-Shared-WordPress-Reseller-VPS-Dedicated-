<?php

declare(strict_types=1);

namespace LbHosting\Pricing;

use Illuminate\Database\Capsule\Manager as Capsule;
use LbHosting\Accounts\Account;
use LbHosting\Api\HostingApi;
use LbHosting\Api\HttpTransport;
use LbHosting\Catalog\ProductFamily;
use LbHosting\Support\Logger;
use LbHosting\Support\Settings;

/**
 * Fetches and stores, per LogicBoxes account:
 *  - cost prices     products/reseller-cost-price.json (VERIFIED call, KB 1029) - kind "cost"
 *  - selling prices  products/customer-price.json (VERIFIED, KB 864)          - kind "sell"
 *  - plan details    products/plan-details.json   (VERIFIED, KB 939): names,
 *                    Dedicated supported_os, VPS isSSDPlan, specs.
 * An empty or failed fetch never wipes what was stored before.
 */
final class PriceCatalog
{
    private ?HttpTransport $transport;

    public function __construct(?HttpTransport $transport = null)
    {
        $this->transport = $transport;
    }

    /** Price lists fetched per account: kind => [endpoint, label]. */
    public const SOURCES = [
        'cost' => ['products/reseller-cost-price.json', 'cost'],      // KB 1029: what LogicBoxes charges you
        'sell' => ['products/customer-price.json', 'selling'],        // KB 864: your selling prices at LogicBoxes
    ];

    /** @return array{prices:int, cost:int, plans:int, changed:bool, errors:array<string,string>, discovered?:string[]} */
    public function refresh(Account $account): array
    {
        $client = $account->client($this->transport);
        $now = date('Y-m-d H:i:s');
        $result = ['prices' => 0, 'cost' => 0, 'plans' => 0, 'changed' => false, 'errors' => []];

        foreach (self::SOURCES as $kind => [$endpoint, $label]) {
            try {
                $raw = $client->get($endpoint, [], $kind === 'cost' ? 'CostPriceCatalog' : 'PriceCatalog');
                if (!is_array($raw)) {
                    throw new \RuntimeException('unexpected response');
                }
                $found = ProductFamily::discover(array_map('strval', array_keys($raw)));
                if ($found) {
                    $result['discovered'] = array_values(array_unique(array_merge($result['discovered'] ?? [], $found)));
                    Logger::log('prices', 'New location(s) found on "' . $account->name . '": ' . implode(', ', $found));
                }
                $rows = PriceNormalizer::normalize($raw);
                $payload = (string) json_encode(PriceNormalizer::onlyOurs($raw), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
                if ($rows) {
                    if (self::fingerprint($account->id, $kind) !== self::rowsFingerprint($rows)) {
                        $result['changed'] = true;
                    }
                    Capsule::table('mod_lbh_prices')->where('account_id', $account->id)->where('kind', $kind)->delete();
                    foreach ($rows as $r) {
                        Capsule::table('mod_lbh_prices')->insert($r + ['account_id' => $account->id, 'kind' => $kind, 'fetched_at' => $now]);
                    }
                    Capsule::table('mod_lbh_price_snapshots')->updateOrInsert(['account_id' => $account->id, 'kind' => $kind], ['payload' => $payload, 'fetched_at' => $now]);
                }
                $result[$kind === 'cost' ? 'cost' : 'prices'] = count($rows);
            } catch (\Throwable $e) {
                $result['errors'][$kind === 'cost' ? 'cost prices' : 'prices'] = $e->getMessage();
                Logger::log('prices', 'Fetching ' . $label . ' prices for "' . $account->name . '" failed: ' . $e->getMessage() . ' - the stored ' . $label . ' prices are kept.', 'error');
            }
        }

        try {
            $byKey = (new HostingApi($client))->plansForKeys(PriceNormalizer::ourKeys());
            foreach ($byKey as $key => $plans) {
                foreach ($plans as $planId => $plan) {
                    Capsule::table('mod_lbh_plans')->updateOrInsert(
                        ['account_id' => $account->id, 'product_key' => $key, 'plan_id' => $planId],
                        ['plan_name' => mb_substr(HostingApi::planName($plan, $planId), 0, 191), 'details_json' => json_encode($plan, JSON_UNESCAPED_SLASHES), 'fetched_at' => $now]
                    );
                    $result['plans']++;
                }
            }
        } catch (\Throwable $e) {
            $result['errors']['plans'] = $e->getMessage();
            Logger::log('prices', 'Fetching plan details for "' . $account->name . '" failed: ' . $e->getMessage(), 'warning');
        }
        if ($result['changed']) {
            // Cleared only once a sync has applied it, so a failed sync is retried at the next check.
            Settings::set('price_sync_pending', '1');
        }
        Logger::log('prices', sprintf('Catalogue refreshed for "%s": %d cost and %d selling price rows, %d plans%s', $account->name, $result['cost'], $result['prices'], $result['plans'], $result['changed'] ? ' - prices CHANGED' : ''));

        return $result;
    }

    /**
     * Remove stored prices and plans of an account - one product type, or everything (then the raw price
     * snapshots too). WHMCS products are not touched; price sync skips products whose prices are gone.
     *
     * @return array{prices:int, plans:int}
     */
    public static function clear(int $accountId, ?string $family = null): array
    {
        $prices = Capsule::table('mod_lbh_prices')->where('account_id', $accountId);
        $plans = Capsule::table('mod_lbh_plans')->where('account_id', $accountId);
        if ($family !== null) {
            $keys = array_keys(ProductFamily::keys($family));
            if ($keys === []) {
                return ['prices' => 0, 'plans' => 0];
            }
            $prices->whereIn('product_key', $keys);
            $plans->whereIn('product_key', $keys);
        }
        $out = ['prices' => (int) $prices->count(), 'plans' => (int) $plans->count()];
        $prices->delete();
        $plans->delete();
        if ($family === null) {
            Capsule::table('mod_lbh_price_snapshots')->where('account_id', $accountId)->delete();
        }

        return $out;
    }

    /** Fingerprint of the stored rows of one price list (detects a price change between fetches). */
    private static function fingerprint(int $accountId, string $kind): string
    {
        $rows = array_map(static fn ($r) => ['product_key' => (string) $r->product_key, 'plan_id' => (int) $r->plan_id, 'item' => (string) $r->item, 'action' => (string) $r->action, 'months' => (int) $r->months, 'price' => (float) $r->price],
            Capsule::table('mod_lbh_prices')->where('account_id', $accountId)->where('kind', $kind)->get()->all());

        return self::rowsFingerprint($rows);
    }

    /** @param array<int,array<string,mixed>> $rows */
    private static function rowsFingerprint(array $rows): string
    {
        $flat = array_map(static fn ($r) => implode('|', [$r['product_key'], (int) $r['plan_id'], $r['item'], $r['action'], (int) $r['months'], number_format((float) $r['price'], 4, '.', '')]), $rows);
        sort($flat);

        return sha1(implode("\n", $flat));
    }

    /** The price list a mapping is priced from: "cost" (reseller cost price) or "sell" (customer price). */
    public static function source(?object $map): string
    {
        $s = $map ? (string) ($map->price_source ?? 'sell') : 'sell';

        return in_array($s, ['cost', 'sell'], true) ? $s : 'sell';
    }

    /** @return array<int,array<string,mixed>> plan rows of one plan */
    public static function planRows(int $accountId, string $key, int $planId, string $kind = 'sell'): array
    {
        return array_map(static fn ($r) => ['action' => (string) $r->action, 'months' => (int) $r->months, 'price' => (float) $r->price],
            array_filter(Capsule::table('mod_lbh_prices')->where('account_id', $accountId)->where('product_key', $key)->where('plan_id', $planId)
                ->where('kind', $kind)->where('item', 'plan')->get()->all(), static fn ($r) => (float) $r->price > 0)); // a 0 price (free trial / promo) is never sold
    }

    /** Whether a plan has a price in this list. */
    public static function hasPlanPrice(int $accountId, string $key, int $planId, string $kind): bool
    {
        return self::planRows($accountId, $key, $planId, $kind) !== [];
    }

    /** Flat extras price (plan level first, then product level), or null when LogicBoxes lists none. */
    public static function extra(int $accountId, string $key, int $planId, string $item, string $kind = 'sell'): ?float
    {
        foreach ([$planId, 0] as $pid) {
            $v = Capsule::table('mod_lbh_prices')->where('account_id', $accountId)->where('product_key', $key)->where('plan_id', $pid)
                ->where('kind', $kind)->where('item', $item)->value('price');
            if ($v !== null) {
                return (float) $v;
            }
        }

        return null;
    }

    /**
     * Every extras item listed for a plan (plan level wins over product level).
     *
     * @return array<string,float> item => price
     */
    public static function extras(int $accountId, string $key, int $planId, string $kind = 'sell'): array
    {
        $out = [];
        foreach (Capsule::table('mod_lbh_prices')->where('account_id', $accountId)->where('product_key', $key)->whereIn('plan_id', [0, $planId])
            ->where('kind', $kind)->where('item', '!=', 'plan')->orderBy('plan_id')->get() as $r) {
            $out[(string) $r->item] = (float) $r->price; // plan_id 0 first, the plan's own value overwrites it
        }
        ksort($out);

        return $out;
    }

    /**
     * Price maths for one plan from one price list. A cost list that gives a
     * single per-month figure (the band shape) is extended to every tenure the
     * selling list offers for that plan, at that monthly rate.
     */
    public static function calculator(int $accountId, string $key, int $planId, string $kind = 'sell'): PriceCalculator
    {
        $rows = self::planRows($accountId, $key, $planId, $kind);
        if ($kind === 'cost' && $rows && !array_filter($rows, static fn ($r) => (int) $r['months'] !== 1)) {
            $tenures = array_values(array_unique(array_map(static fn ($r) => (int) $r['months'], self::planRows($accountId, $key, $planId, 'sell'))));
            $monthly = [];
            foreach ($rows as $r) {
                $monthly[$r['action']] = (float) $r['price'];
            }
            foreach ($monthly as $action => $p) {
                foreach ($tenures as $m) {
                    if ($m > 1) {
                        $rows[] = ['action' => $action, 'months' => $m, 'price' => Settings::get('price_basis') === 'per_term' ? $p * $m : $p];
                    }
                }
            }
        }

        return new PriceCalculator($rows, Settings::get('price_basis'), Settings::get('price_action'));
    }

    /**
     * Plans known for a family on an account (from plan details and prices).
     *
     * @return array<int, array{product_key:string, plan_id:int, plan_name:string, priced:bool}>
     */
    public static function plans(int $accountId, string $family): array
    {
        $keys = array_keys(ProductFamily::keys($family));
        $out = [];
        foreach (Capsule::table('mod_lbh_plans')->where('account_id', $accountId)->whereIn('product_key', $keys)->get() as $p) {
            $out[$p->product_key . ':' . $p->plan_id] = ['product_key' => (string) $p->product_key, 'plan_id' => (int) $p->plan_id, 'plan_name' => (string) $p->plan_name, 'priced' => false];
        }
        foreach (Capsule::table('mod_lbh_prices')->where('account_id', $accountId)->whereIn('product_key', $keys)->where('item', 'plan')->whereIn('kind', ['cost', 'sell'])->get() as $r) {
            $k = $r->product_key . ':' . $r->plan_id;
            $out[$k] = ($out[$k] ?? ['product_key' => (string) $r->product_key, 'plan_id' => (int) $r->plan_id, 'plan_name' => 'Plan ' . $r->plan_id]);
            $out[$k]['priced'] = true;
        }
        uasort($out, static fn ($a, $b) => [$a['product_key'], $a['plan_id']] <=> [$b['product_key'], $b['plan_id']]);

        return array_values($out);
    }

    /** @return array<string,mixed> stored plan-details payload */
    public static function planDetails(int $accountId, string $key, int $planId): array
    {
        $json = Capsule::table('mod_lbh_plans')->where('account_id', $accountId)->where('product_key', $key)->where('plan_id', $planId)->value('details_json');
        $d = $json ? json_decode((string) $json, true) : null;

        return is_array($d) ? $d : [];
    }

    /**
     * Dedicated plan-details carry supported_os[{os_name, os_display_name, addons[]}]
     * (KB 2061 / 2772). Returns code => label, or [] when the plan does not say.
     *
     * @param array<string,mixed> $details
     * @return array<string,string>
     */
    public static function supportedOs(array $details): array
    {
        $out = [];
        foreach ((array) ($details['supported_os'] ?? []) as $os) {
            if (is_array($os) && !empty($os['os_name']) && preg_match('/^[a-z0-9_]{2,32}$/', (string) $os['os_name'])) {
                $out[(string) $os['os_name']] = (string) ($os['os_display_name'] ?? $os['os_name']);
            }
        }

        return $out;
    }

    /** VPS isSSDPlan flag (KB 2977); null when the plan does not say. @param array<string,mixed> $details */
    public static function isSsd(array $details): ?bool
    {
        foreach (['isSSDPlan', 'isssdplan', 'is_ssd_plan'] as $k) {
            if (array_key_exists($k, $details)) {
                return in_array(strtolower(var_export($details[$k], true)), ['true', "'true'", '1', "'1'"], true);
            }
        }

        return null;
    }
}
