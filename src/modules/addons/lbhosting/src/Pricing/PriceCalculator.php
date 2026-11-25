<?php

declare(strict_types=1);

namespace LbHosting\Pricing;

/**
 * Pure price maths for one plan: price per WHMCS billing cycle, markup,
 * rounding and currency conversion. No DB, fully unit-tested.
 *
 * "Basis" says what a LogicBoxes plan price number means:
 *   per_month - price per month (12-month cycle = p x 12)   <- ResellerClub hosting default
 *   per_term  - price for the whole tenure
 *   auto      - detect from the 1-month vs 12-month ratio, else per_month
 */
final class PriceCalculator
{
    public const CYCLES = [1 => 'monthly', 3 => 'quarterly', 6 => 'semiannually', 12 => 'annually', 24 => 'biennially', 36 => 'triennially'];

    /** @var array<int,array<string,mixed>> */
    private array $rows;

    private string $basis;

    private string $action;

    /**
     * @param array<int,array<string,mixed>> $rows plan rows (action, months, price) of ONE plan
     * @param string $action preferred action (add|renew); falls back to the other
     */
    public function __construct(array $rows, string $basis = 'auto', string $action = 'renew')
    {
        $this->rows = array_values(array_filter($rows, static fn ($r) => in_array($r['action'], ['add', 'renew'], true) && (int) $r['months'] > 0));
        $this->action = in_array($action, ['add', 'renew'], true) ? $action : 'renew';
        $this->basis = in_array($basis, ['per_month', 'per_term'], true) ? $basis : self::detectBasis($this->rows);
    }

    public function basis(): string
    {
        return $this->basis;
    }

    /** @param array<int,array<string,mixed>> $rows */
    public static function detectBasis(array $rows): string
    {
        $by = [];
        foreach ($rows as $r) {
            $by[$r['action']][(int) $r['months']] = (float) $r['price'];
        }
        foreach ($by as $tenures) {
            if (isset($tenures[1], $tenures[12]) && $tenures[1] > 0) {
                return $tenures[12] / $tenures[1] > 4 ? 'per_term' : 'per_month';
            }
        }

        return 'per_month';
    }

    /** @return int[] tenures that have a price and map to a WHMCS cycle */
    public function tenures(): array
    {
        $m = [];
        foreach ($this->rowsForAction() as $r) {
            if (isset(self::CYCLES[(int) $r['months']])) {
                $m[(int) $r['months']] = true;
            }
        }
        ksort($m);

        return array_keys($m);
    }

    /** LogicBoxes price for the whole cycle (before markup), or null. */
    public function cyclePrice(int $months): ?float
    {
        foreach ($this->rowsForAction() as $r) {
            if ((int) $r['months'] === $months) {
                return $this->basis === 'per_month' ? (float) $r['price'] * $months : (float) $r['price'];
            }
        }

        return null;
    }

    /** Monthly equivalent of the cheapest-per-month tenure, for display. */
    public function perMonth(int $months): ?float
    {
        $p = $this->cyclePrice($months);

        return $p === null ? null : $p / $months;
    }

    /** @return array<int,array<string,mixed>> */
    private function rowsForAction(): array
    {
        $preferred = array_values(array_filter($this->rows, fn ($r) => $r['action'] === $this->action));

        return $preferred ?: $this->rows;
    }

    // ---------------------------------------------------------------- money

    /** An extras price (ssl / add-on / manual) for a cycle of $months. */
    public static function extraForCycle(float $price, int $months, string $extrasBasis): float
    {
        return $extrasBasis === 'per_year' ? $price / 12 * $months : $price * $months;
    }

    public static function markup(float $price, float $pct): float
    {
        return $price * (1 + $pct / 100);
    }

    /** Convert between WHMCS currencies using tblcurrencies.rate (relative to the default currency). */
    public static function convert(float $price, float $fromRate, float $toRate): float
    {
        if ($fromRate <= 0) {
            return $price;
        }

        return $price / $fromRate * $toRate;
    }

    public static function round(float $price, string $mode): float
    {
        switch ($mode) {
            case 'none':
                return round($price, 4);
            case 'whole':
                return (float) ceil(round($price, 4));
            case '99':
                $p = floor(round($price, 4)) + 0.99;

                return round($p < $price ? $p + 1 : $p, 2);
            default:
                return round($price, 2);
        }
    }

    public static function sellPrice(float $base, float $markupPct, float $fromRate, float $toRate, string $rounding): float
    {
        return self::round(self::convert(self::markup($base, $markupPct), $fromRate, $toRate), $rounding);
    }
}
