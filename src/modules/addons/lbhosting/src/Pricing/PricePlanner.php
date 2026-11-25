<?php

declare(strict_types=1);

namespace LbHosting\Pricing;

use Illuminate\Database\Capsule\Manager as Capsule;
use LbHosting\Products\ProductMap;
use LbHosting\Support\Settings;

/**
 * The WHMCS prices a mapped product SHOULD have, in every WHMCS currency:
 *
 *   product (cycle)      = LogicBoxes plan price for that tenure (+ markup, conversion, rounding)
 *   option sub (cycle)   = monthly extras price x months (catalogue "ssl" / "addon:<code>",
 *                          or the manual monthly price; OS = 0)
 *   service amount       = product price + selected option prices (qty x unit)
 *
 * Cycles LogicBoxes has no plan price for are disabled (-1) on the product.
 */
final class PricePlanner
{
    private object $map;

    private PriceCalculator $calc;

    /** "cost" or "sell": the LogicBoxes price list this mapping is priced from. */
    private string $source;

    /** @var array<int,object> id => tblcurrencies row */
    private array $currencies = [];

    private float $sourceRate;

    /** @var object[] */
    private array $options;

    public function __construct(object $map, ?PriceCalculator $calc = null)
    {
        $this->map = $map;
        $this->source = PriceCatalog::source($map);
        $this->calc = $calc ?? PriceCatalog::calculator((int) $map->account_id, (string) $map->product_key, (int) $map->plan_id, $this->source);
        foreach (Capsule::table('tblcurrencies')->get() as $c) {
            $this->currencies[(int) $c->id] = $c;
        }
        $src = $this->currencies[(int) $map->source_currency_id] ?? null;
        $this->sourceRate = $src ? (float) $src->rate : 1.0;
        $this->options = ProductMap::options((int) $map->id);
    }

    public function source(): string
    {
        return $this->source;
    }

    public function calculator(): PriceCalculator
    {
        return $this->calc;
    }

    public function hasPrices(): bool
    {
        return $this->calc->tenures() !== [];
    }

    private function sell(float $base, int $currencyId): float
    {
        return PriceCalculator::sellPrice($base, (float) $this->map->markup_pct, $this->sourceRate, (float) $this->currencies[$currencyId]->rate, (string) $this->map->rounding);
    }

    /** Monthly LogicBoxes price of one option row (before markup), or null when unknown now. */
    public function optionMonthly(object $opt): ?float
    {
        $item = (string) $opt->price_item;
        if ($item === 'free' || $item === '') {
            return 0.0;
        }
        if ($item === 'manual') {
            return $opt->manual_price === null ? null : (float) $opt->manual_price;
        }
        $p = PriceCatalog::extra((int) $this->map->account_id, (string) $this->map->product_key, (int) $this->map->plan_id, $item, $this->source);
        if ($p === null || $p <= 0) {
            return null; // not listed, or listed at 0: never sold free by accident - the WHMCS price is left alone and the sync warns
        }

        return Settings::get('extras_basis') === 'per_year' ? $p / 12 : $p;
    }

    /**
     * @return array{product: array<int,array<string,float>>, options: array<int,array<int,array<string,float>>>}
     *   product: currency => cycle => price; options: sub id => currency => cycle => price
     */
    public function targets(): array
    {
        $product = [];
        $options = [];
        foreach (array_keys($this->currencies) as $cid) {
            foreach (PriceCalculator::CYCLES as $months => $cycle) {
                $base = $this->calc->cyclePrice($months);
                $product[$cid][$cycle] = $base === null ? -1.0 : $this->sell($base, $cid);
            }
        }
        foreach ($this->options as $opt) {
            $monthly = $this->optionMonthly($opt);
            if ($monthly === null) {
                continue; // price disappeared from the catalogue: leave WHMCS as it is
            }
            foreach (array_keys($this->currencies) as $cid) {
                foreach (PriceCalculator::CYCLES as $months => $cycle) {
                    $options[(int) $opt->config_sub_id][$cid][$cycle] = $monthly <= 0 ? 0.0 : $this->sell($monthly * $months, $cid);
                }
            }
        }

        return ['product' => $product, 'options' => $options];
    }

    /** Recurring amount a service of this product should have, or null when it cannot be computed. */
    public function serviceAmount(int $serviceId, string $cycle, int $currencyId, ?array $targets = null): ?float
    {
        $cycle = strtolower(str_replace(['-', ' '], '', $cycle));
        if (!in_array($cycle, PriceCalculator::CYCLES, true) || !isset($this->currencies[$currencyId])) {
            return null;
        }
        $targets = $targets ?? $this->targets();
        $amount = $targets['product'][$currencyId][$cycle] ?? -1.0;
        if ($amount < 0) {
            return null;
        }
        $bySub = [];
        foreach ($this->options as $opt) {
            $bySub[(int) $opt->config_sub_id] = $opt;
        }
        foreach (Capsule::table('tblhostingconfigoptions')->where('relid', $serviceId)->get() as $sel) {
            $subId = (int) $sel->optionid;
            if (!isset($bySub[$subId])) {
                // An option the module does not manage: it keeps its own WHMCS price in the total.
                $own = Capsule::table('tblpricing')->where('type', 'configoptions')->where('relid', $subId)->where('currency', $currencyId)->value($cycle);
                $price = $own === null ? 0.0 : max(0.0, (float) $own);
                $type = (int) Capsule::table('tblproductconfigoptions')->where('id', $sel->configid)->value('optiontype');
            } else {
                $price = $targets['options'][$subId][$currencyId][$cycle] ?? null;
                if ($price === null) {
                    return null; // unknown price: do not guess the total
                }
                $type = (int) Capsule::table('tblproductconfigoptions')->where('id', $bySub[$subId]->config_option_id)->value('optiontype');
            }
            $qty = (int) $sel->qty;
            if ($type === 4) {
                $amount += $price * $qty;
            } elseif ($type === 3) {
                $amount += $qty > 0 ? $price : 0;
            } else {
                $amount += $price;
            }
        }

        return round($amount, 2);
    }
}
