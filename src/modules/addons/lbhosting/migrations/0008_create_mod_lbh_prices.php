<?php

declare(strict_types=1);

/**
 * mod_lbh_prices    - normalised LogicBoxes price catalogue, rebuilt on every fetch.
 *                     item "plan" rows carry action + tenure; extras rows
 *                     ("ssl", "addon:<code>") carry one flat price (months = 0).
 *                     plan_id 0 = product-level extra (e.g. Dedicated "addons").
 * mod_lbh_price_snapshots - raw JSON of the last fetch (this module's keys only).
 * mod_lbh_plans     - plan-details.json per plan: name, OS list, SSD flag...
 */
return [
    'up' => function (\Illuminate\Database\Schema\Builder $schema): void {
        if (!$schema->hasTable('mod_lbh_prices')) {
            $schema->create('mod_lbh_prices', function (\Illuminate\Database\Schema\Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedInteger('account_id');
                $t->string('product_key', 64);
                $t->unsignedBigInteger('plan_id')->default(0);
                $t->string('kind', 8)->default('sell');   // sell | cost
                $t->string('item', 48)->default('plan');   // plan | ssl | addon:<code>
                $t->string('action', 16)->default('');     // add | renew (plan rows)
                $t->unsignedSmallInteger('months')->default(0);
                $t->decimal('price', 12, 4);
                $t->dateTime('fetched_at');
                $t->index(['account_id', 'product_key', 'plan_id', 'kind'], 'lbh_prices_lookup');
            });
        }
        if (!$schema->hasTable('mod_lbh_price_snapshots')) {
            $schema->create('mod_lbh_price_snapshots', function (\Illuminate\Database\Schema\Blueprint $t) {
                $t->increments('id');
                $t->unsignedInteger('account_id');
                $t->string('kind', 8);
                $t->longText('payload');
                $t->dateTime('fetched_at');
                $t->unique(['account_id', 'kind']);
            });
        }
        if (!$schema->hasTable('mod_lbh_plans')) {
            $schema->create('mod_lbh_plans', function (\Illuminate\Database\Schema\Blueprint $t) {
                $t->increments('id');
                $t->unsignedInteger('account_id');
                $t->string('product_key', 64);
                $t->unsignedBigInteger('plan_id');
                $t->string('plan_name', 191)->default('');
                $t->mediumText('details_json')->nullable();
                $t->dateTime('fetched_at');
                $t->unique(['account_id', 'product_key', 'plan_id'], 'lbh_plans_unique');
            });
        }
    },
];
