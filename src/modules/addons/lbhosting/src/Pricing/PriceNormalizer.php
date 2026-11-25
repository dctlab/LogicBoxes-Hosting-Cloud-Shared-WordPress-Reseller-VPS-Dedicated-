<?php

declare(strict_types=1);

namespace LbHosting\Pricing;

use LbHosting\Catalog\ProductFamily;

/**
 * Turns products/customer-price.json (selling prices) and
 * products/reseller-cost-price.json (your cost prices) into flat rows for
 * this module's product keys. Shapes documented in the ResellerClub KB:
 *
 *   Hosting (864, 1029, 1275, 1330):  key -> planid -> {add|renew -> months -> price, "ssl": price}
 *   Cloud (2659):                     key -> "plans" -> planid -> {add|renew -> months -> price}
 *   Dedicated (2064):                 key -> {"addons": {code: price}, "plans": {planid -> ...}}
 *   Single Domain, COST price (1029): key -> key -> bandid -> {"pricing": {add|renew: price, "ssl": price},
 *                                     "description": {...}} - one price per action, no tenure. It is
 *                                     read as a price per month (months = 1) for plan id = band id
 *                                     (PATTERN: the KB example is from the older one-plan product).
 *
 * The walker is tolerant: an optional "plans" wrapper, "addons" maps at product
 * or plan level, numeric tenures 1..120 under an action. Scalar extras on a
 * plan ("ssl", or any non-action key with a number) become extra rows.
 */
final class PriceNormalizer
{
    private const ACTIONS = ['add' => 'add', 'addnew' => 'add', 'new' => 'add', 'renew' => 'renew', 'renewal' => 'renew'];

    /** @return string[] */
    public static function ourKeys(): array
    {
        $keys = [];
        foreach (ProductFamily::all() as $f) {
            $keys = array_merge($keys, array_keys(ProductFamily::keys($f)));
        }

        return $keys;
    }

    /**
     * @param array<string,mixed> $raw
     * @return array<int, array{product_key:string, plan_id:int, item:string, action:string, months:int, price:float}>
     */
    public static function normalize(array $raw): array
    {
        $wanted = self::ourKeys();
        $rows = [];
        foreach ($raw as $productKey => $node) {
            $productKey = strtolower((string) $productKey);
            if (!is_array($node) || !in_array($productKey, $wanted, true)) {
                continue;
            }
            if (isset($node['addons']) && is_array($node['addons'])) {
                self::extras($rows, $productKey, 0, $node['addons'], 'addon:');
            }
            if (isset($node[$productKey]) && is_array($node[$productKey])) {
                self::bands($rows, $productKey, $node[$productKey]);
                continue;
            }
            $plans = isset($node['plans']) && is_array($node['plans']) ? $node['plans'] : $node;
            foreach ($plans as $planId => $tree) {
                if (!is_numeric($planId) || !is_array($tree)) {
                    continue;
                }
                foreach ($tree as $k => $v) {
                    $lk = strtolower((string) $k);
                    if (isset(self::ACTIONS[$lk]) && is_array($v)) {
                        foreach ($v as $months => $price) {
                            if (ctype_digit((string) $months) && (int) $months >= 1 && (int) $months <= 120 && is_numeric($price)) {
                                $rows[] = ['product_key' => $productKey, 'plan_id' => (int) $planId, 'item' => 'plan', 'action' => self::ACTIONS[$lk], 'months' => (int) $months, 'price' => (float) $price];
                            }
                        }
                    } elseif ($lk === 'addons' && is_array($v)) {
                        self::extras($rows, $productKey, (int) $planId, $v, 'addon:');
                    } elseif (is_numeric($v) && preg_match('/^[a-z][a-z0-9_]{1,40}$/', $lk)) {
                        $rows[] = ['product_key' => $productKey, 'plan_id' => (int) $planId, 'item' => $lk === 'ssl' ? 'ssl' : 'addon:' . $lk, 'action' => '', 'months' => 0, 'price' => (float) $v];
                    }
                }
            }
        }

        return self::dedupe($rows);
    }

    /**
     * Cost-price band shape (KB 1029, Single Domain Hosting).
     *
     * @param array<int,array<string,mixed>> $rows
     * @param array<mixed> $bands bandid => {pricing: {...}, description: {...}}
     */
    private static function bands(array &$rows, string $key, array $bands): void
    {
        foreach ($bands as $bandId => $band) {
            if (!is_numeric($bandId) || !is_array($band) || !isset($band['pricing']) || !is_array($band['pricing'])) {
                continue;
            }
            foreach ($band['pricing'] as $k => $v) {
                $lk = strtolower((string) $k);
                if (isset(self::ACTIONS[$lk]) && is_array($v)) {
                    foreach ($v as $months => $price) {
                        if (ctype_digit((string) $months) && (int) $months >= 1 && (int) $months <= 120 && is_numeric($price)) {
                            $rows[] = ['product_key' => $key, 'plan_id' => (int) $bandId, 'item' => 'plan', 'action' => self::ACTIONS[$lk], 'months' => (int) $months, 'price' => (float) $price];
                        }
                    }
                } elseif (isset(self::ACTIONS[$lk]) && is_numeric($v)) {
                    $rows[] = ['product_key' => $key, 'plan_id' => (int) $bandId, 'item' => 'plan', 'action' => self::ACTIONS[$lk], 'months' => 1, 'price' => (float) $v];
                } elseif (is_numeric($v) && preg_match('/^[a-z][a-z0-9_]{1,40}$/', $lk)) {
                    $rows[] = ['product_key' => $key, 'plan_id' => (int) $bandId, 'item' => $lk === 'ssl' ? 'ssl' : 'addon:' . $lk, 'action' => '', 'months' => 0, 'price' => (float) $v];
                } elseif ($lk === 'addons' && is_array($v)) {
                    self::extras($rows, $key, (int) $bandId, $v, 'addon:');
                }
            }
        }
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param array<mixed> $map code => price (or code => {months => price}: the 1-month price, else the first one)
     */
    private static function extras(array &$rows, string $key, int $planId, array $map, string $prefix): void
    {
        foreach ($map as $code => $price) {
            $code = strtolower((string) $code);
            if (is_array($price)) {
                $price = isset($price['1']) && is_numeric($price['1']) ? $price['1'] : (array_values(array_filter($price, 'is_numeric'))[0] ?? null);
            }
            if (is_numeric($price) && preg_match('/^[a-z][a-z0-9_]{1,40}$/', $code)) {
                $rows[] = ['product_key' => $key, 'plan_id' => $planId, 'item' => $prefix . $code, 'action' => '', 'months' => 0, 'price' => (float) $price];
            }
        }
    }

    /** @param array<string,mixed> $raw @return array<string,mixed> */
    public static function onlyOurs(array $raw): array
    {
        $wanted = self::ourKeys();

        return array_filter($raw, static fn ($k) => in_array(strtolower((string) $k), $wanted, true), ARRAY_FILTER_USE_KEY);
    }

    /** @param array<int,array<string,mixed>> $rows @return array<int,array<string,mixed>> */
    private static function dedupe(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[implode('|', [$r['product_key'], $r['plan_id'], $r['item'], $r['action'], $r['months']])] = $r;
        }

        return array_values($out);
    }
}
