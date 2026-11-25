<?php

declare(strict_types=1);

/**
 * mod_lbh_upgrades      - provider-priced plan / add-on changes requested from
 *                         the client area: quote -> WHMCS invoice -> applied at
 *                         LogicBoxes when the invoice is paid. Idempotent and
 *                         retryable from the admin queue.
 * mod_lbh_block_volumes - VPS Block Storage (SAN) volume of a service: a
 *                         separate LogicBoxes order attached to the VPS order.
 */
return [
    'up' => function (\Illuminate\Database\Schema\Builder $schema): void {
        if (!$schema->hasTable('mod_lbh_upgrades')) {
            $schema->create('mod_lbh_upgrades', function (\Illuminate\Database\Schema\Blueprint $t) {
                $t->increments('id');
                $t->unsignedInteger('service_id')->index();
                $t->unsignedInteger('client_id');
                $t->string('kind', 8);                       // plan | options
                $t->text('payload');                         // JSON: new_product_id / option selections
                $t->string('summary', 255)->default('');
                $t->unsignedSmallInteger('months')->default(0);          // remaining months priced
                $t->decimal('provider_cost', 12, 4)->default(0);        // LogicBoxes price, source currency
                $t->decimal('amount', 12, 2)->default(0);               // invoiced to the client
                $t->unsignedInteger('currency_id')->nullable();
                $t->unsignedInteger('invoice_id')->nullable()->index();
                $t->string('status', 12)->default('invoiced');           // invoiced | paid | applying | applied | failed | cancelled
                $t->text('error')->nullable();
                $t->dateTime('created_at');
                $t->dateTime('updated_at')->nullable();
            });
        }
        if (!$schema->hasTable('mod_lbh_block_volumes')) {
            $schema->create('mod_lbh_block_volumes', function (\Illuminate\Database\Schema\Blueprint $t) {
                $t->increments('id');
                $t->unsignedInteger('service_id')->unique();
                $t->unsignedBigInteger('order_id')->nullable();
                $t->unsignedInteger('size_gb')->default(0);
                $t->string('status', 32)->nullable();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
            });
        }
    },
];
