<?php

declare(strict_types=1);

/**
 * mod_lbh_import_orders - Import Manager staging table: every order found at
 * LogicBoxes, its WHMCS match and the admin's decision. Nothing reaches
 * tblhosting until an admin links or imports it.
 */
return [
    'up' => function (\Illuminate\Database\Schema\Builder $schema): void {
        if ($schema->hasTable('mod_lbh_import_orders')) {
            return;
        }
        $schema->create('mod_lbh_import_orders', function (\Illuminate\Database\Schema\Blueprint $t) {
            $t->increments('id');
            $t->unsignedInteger('account_id');
            $t->string('family', 16);
            $t->string('product_key', 64);
            $t->unsignedBigInteger('order_id');
            $t->string('domain', 191);
            $t->unsignedBigInteger('customer_id')->nullable();
            $t->string('customer_email', 191)->nullable();
            $t->string('customer_name', 191)->nullable();
            $t->string('lb_status', 32)->nullable();
            $t->unsignedBigInteger('plan_id')->nullable();
            $t->string('plan_name', 191)->nullable();
            $t->text('extras_json')->nullable();       // what is on the order besides the plan (redacted details subset)
            $t->dateTime('expires_at')->nullable();
            $t->unsignedInteger('match_client_id')->nullable();
            $t->unsignedInteger('match_service_id')->nullable();
            $t->unsignedInteger('match_product_id')->nullable();
            $t->string('match_by', 8)->nullable();     // linked | email | domain - how match_service_id was found
            $t->string('state', 16)->default('new');   // new | linked | imported | ignored | error
            $t->text('message')->nullable();
            $t->dateTime('scanned_at');
            $t->unique(['account_id', 'product_key', 'order_id'], 'lbh_import_order');
            $t->index('state');
        });
    },
];
