<?php

declare(strict_types=1);

namespace LbHosting\WHMCS;

use LbHosting\Api\HostingApi;
use LbHosting\Catalog\ProductFamily;

/**
 * Typed view over a product's module settings ($params['configoptionN']).
 * Order MUST match ServerModule::configOptions():
 *
 *   1 API Account     2 Plan ("productkey:planid")     3 Invoice Option
 *   4 Default OS (VPS / Dedicated)                     5 Included add-ons (VPS / Dedicated, comma list)
 */
final class ProductConfig
{
    public string $family;
    public ?int $accountId;
    public ?int $planId;
    public string $productKey;
    public string $invoiceOption;
    public ?string $defaultOs;
    /** @var string[] */
    public array $includedAddons;

    /** @param array<string,mixed> $params */
    public function __construct(string $family, array $params)
    {
        $this->family = ProductFamily::assert($family);
        $acc = self::int($params['configoption1'] ?? null);
        $this->accountId = $acc > 0 ? $acc : null;

        [$key, $plan] = self::parsePlan($family, (string) ($params['configoption2'] ?? ''));
        $this->productKey = $key;
        $this->planId = $plan;

        $opt = trim((string) ($params['configoption3'] ?? ''));
        $this->invoiceOption = in_array($opt, HostingApi::INVOICE_OPTIONS, true) ? $opt : 'NoInvoice';

        $this->defaultOs = null;
        $this->includedAddons = [];
        if (ProductFamily::isServer($family)) {
            $os = strtolower(trim((string) ($params['configoption4'] ?? '')));
            $this->defaultOs = preg_match('/^[a-z0-9_]{2,32}$/', $os) ? $os : null;
            $allowed = ProductFamily::addons($family, $key);
            foreach (preg_split('/[\s,;]+/', strtolower((string) ($params['configoption5'] ?? ''))) ?: [] as $a) {
                if ($a !== '' && isset($allowed[$a])) {
                    $this->includedAddons[] = $a;
                }
            }
            $this->includedAddons = array_values(array_unique($this->includedAddons));
        }
    }

    /**
     * "multidomainhostinglinuxin:812" -> [multidomainhostinglinuxin, 812]. A
     * bare id falls back to the family's first product key.
     *
     * @return array{0:string,1:?int}
     */
    public static function parsePlan(string $family, string $value): array
    {
        $value = strtolower(trim($value));
        $keys = array_keys(ProductFamily::keys($family));
        if (preg_match('/^([a-z0-9]+)\s*:\s*(\d{1,12})$/', $value, $m) && in_array($m[1], $keys, true)) {
            return [$m[1], (int) $m[2]];
        }
        $id = self::int($value);

        return [ProductFamily::defaultKey($family), $id > 0 ? $id : null];
    }

    public static function planValue(string $key, int $planId): string
    {
        return strtolower($key) . ':' . $planId;
    }

    /** Extracts the LAST integer in a value ("Business - 1758" -> 1758). @param mixed $value */
    public static function int($value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (preg_match_all('/\d+/', (string) $value, $m) > 0) {
            return (int) end($m[0]);
        }

        return 0;
    }

    public static function monthsForCycle(string $cycle): int
    {
        switch (strtolower(str_replace([' ', '-'], '', $cycle))) {
            case 'monthly':
                return 1;
            case 'quarterly':
                return 3;
            case 'semiannually':
                return 6;
            case 'annually':
                return 12;
            case 'biennially':
                return 24;
            case 'triennially':
                return 36;
            default:
                return 0;
        }
    }
}
