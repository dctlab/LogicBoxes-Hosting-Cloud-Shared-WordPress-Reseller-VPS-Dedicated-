<?php

declare(strict_types=1);

/**
 * Cost-price pricing:
 *  - mod_lbh_product_map.price_items: which LogicBoxes price item an option is
 *    priced from when the module cannot tell by name (JSON logical => item),
 *    chosen by the admin on the product's page.
 * Existing mappings keep their price source ("sell"); new ones default to
 * "cost" (reseller cost price + markup).
 */
return [
    'up' => function (\Illuminate\Database\Schema\Builder $schema): void {
        if ($schema->hasTable('mod_lbh_product_map') && !$schema->hasColumn('mod_lbh_product_map', 'price_items')) {
            $schema->table('mod_lbh_product_map', function (\Illuminate\Database\Schema\Blueprint $t) {
                $t->text('price_items')->nullable();
            });
        }
    },
];
