<?php

declare(strict_types=1);

/**
 * mod_lbh_product_map - WHMCS product <-> LogicBoxes plan, with the pricing
 * rules used by the generator and the daily price sync.
 * mod_lbh_option_map  - the configurable options (sub-options) the module
 * created / manages for a mapped product, and where each one's price comes
 * from: a catalogue item ("ssl", "addon:ipaddress"), a manual monthly price
 * (items LogicBoxes does not price through the API), or "free" (OS choice).
 */
return [
    'up' => function (\Illuminate\Database\Schema\Builder $schema): void {
        if (!$schema->hasTable('mod_lbh_product_map')) {
            $schema->create('mod_lbh_product_map', function (\Illuminate\Database\Schema\Blueprint $t) {
                $t->increments('id');
                $t->string('family', 16);
                $t->unsignedInteger('product_id')->unique();
                $t->unsignedInteger('account_id');
                $t->string('product_key', 64);
                $t->unsignedBigInteger('plan_id');
                $t->string('plan_name', 191)->default('');
                $t->unsignedInteger('config_group_id')->nullable();
                $t->string('price_source', 8)->default('sell');
                $t->decimal('markup_pct', 8, 2)->default(0);
                $t->string('rounding', 8)->default('2dp');
                $t->unsignedInteger('source_currency_id');
                $t->boolean('sync_prices')->default(true);
                $t->boolean('update_recurring')->default(false);
                $t->dateTime('last_synced_at')->nullable();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
                $t->unique(['account_id', 'product_key', 'plan_id'], 'lbh_map_plan');
            });
        }
        if (!$schema->hasTable('mod_lbh_option_map')) {
            $schema->create('mod_lbh_option_map', function (\Illuminate\Database\Schema\Blueprint $t) {
                $t->increments('id');
                $t->unsignedInteger('map_id')->index();
                $t->string('logical', 24);                 // dedicated_ip | cpu | ram | whmcs | control_panel | storage | cpanel_blocks | bandwidth | os
                $t->unsignedInteger('config_option_id');
                $t->unsignedInteger('config_sub_id')->unique();
                $t->string('code', 48)->default('');       // provider code of this sub-option ("" = none)
                $t->string('price_item', 48)->default(''); // ssl | addon:<code> | manual | free
                $t->decimal('manual_price', 12, 4)->nullable(); // per month, in the mapping's source currency
            });
        }
    },
];
