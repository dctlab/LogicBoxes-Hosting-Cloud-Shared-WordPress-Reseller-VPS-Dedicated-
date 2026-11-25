<?php

declare(strict_types=1);

namespace LbHosting\Products;

use Illuminate\Database\Capsule\Manager as Capsule;

/** mod_lbh_product_map / mod_lbh_option_map access. */
final class ProductMap
{
    private const TABLE = 'mod_lbh_product_map';

    public const ROUNDING = ['2dp' => '2 decimals', '99' => 'Ends in .99', 'whole' => 'Whole number (round up)', 'none' => 'No rounding'];

    public function forProduct(int $productId): ?object
    {
        return Capsule::table(self::TABLE)->where('product_id', $productId)->first();
    }

    public function forPlan(int $accountId, string $productKey, int $planId): ?object
    {
        return Capsule::table(self::TABLE)->where('account_id', $accountId)->where('product_key', $productKey)->where('plan_id', $planId)->first();
    }

    public function find(int $id): ?object
    {
        return Capsule::table(self::TABLE)->where('id', $id)->first();
    }

    /** @return object[] */
    public function all(?string $family = null): array
    {
        $q = Capsule::table(self::TABLE)->orderBy('family')->orderBy('product_key')->orderBy('plan_id');
        if ($family !== null) {
            $q->where('family', $family);
        }

        return $q->get()->all();
    }

    /** @param array<string,mixed> $fields */
    public function save(?int $id, array $fields): int
    {
        $fields['updated_at'] = date('Y-m-d H:i:s');
        if ($id) {
            Capsule::table(self::TABLE)->where('id', $id)->update($fields);

            return $id;
        }
        $fields['created_at'] = $fields['updated_at'];

        return (int) Capsule::table(self::TABLE)->insertGetId($fields);
    }

    /** Unmapping never deletes the WHMCS product or its options - only the module's link to them. */
    public function delete(int $id): void
    {
        Capsule::table('mod_lbh_option_map')->where('map_id', $id)->delete();
        Capsule::table(self::TABLE)->where('id', $id)->delete();
    }

    /** @return object[] option-map rows of a mapping */
    public static function options(int $mapId): array
    {
        return Capsule::table('mod_lbh_option_map')->where('map_id', $mapId)->orderBy('id')->get()->all();
    }

    public const SOURCES = ['cost' => 'LogicBoxes cost price + markup (recommended)', 'sell' => 'LogicBoxes customer (selling) price + markup'];

    /** @return array<string,string> logical option => LogicBoxes price item chosen by the admin */
    public static function bindings(?object $map): array
    {
        $b = $map && !empty($map->price_items) ? json_decode((string) $map->price_items, true) : null;

        return is_array($b) ? array_map('strval', $b) : [];
    }

    /**
     * @param array<string,mixed> $in
     * @return array<string,mixed> validated pricing settings
     */
    public static function validateSettings(array $in): array
    {
        $markup = (float) str_replace(',', '.', (string) ($in['markup_pct'] ?? '0'));
        if ($markup < -90 || $markup > 1000) {
            throw new \InvalidArgumentException('Markup must be between -90% and 1000%.');
        }
        $source = (string) ($in['price_source'] ?? 'cost');
        $source = isset(self::SOURCES[$source]) ? $source : 'cost';
        if ($source === 'cost' && $markup <= 0) {
            throw new \InvalidArgumentException('With the cost price as source the markup is your margin: enter a markup above 0% (selling at cost or below would lose money on every order and renewal).');
        }
        $rounding = (string) ($in['rounding'] ?? '2dp');
        $currency = (int) ($in['source_currency_id'] ?? 0);
        if ($currency <= 0 || !Capsule::table('tblcurrencies')->where('id', $currency)->exists()) {
            throw new \InvalidArgumentException('Choose the WHMCS currency your LogicBoxes prices are in.');
        }

        return [
            'price_source' => $source,
            'markup_pct' => round($markup, 2),
            'rounding' => isset(self::ROUNDING[$rounding]) ? $rounding : '2dp',
            'source_currency_id' => $currency,
            'sync_prices' => !empty($in['sync_prices']) ? 1 : 0,
            'update_recurring' => !empty($in['update_recurring']) ? 1 : 0,
        ];
    }
}
