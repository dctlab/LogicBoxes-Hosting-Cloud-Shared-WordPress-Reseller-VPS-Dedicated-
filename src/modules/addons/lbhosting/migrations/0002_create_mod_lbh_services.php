<?php

declare(strict_types=1);

/**
 * mod_lbh_services - one row per WHMCS service (tblhosting.id) linked to a
 * LogicBoxes hosting / server order. Panel passwords are NEVER stored here;
 * they are read live from details.json when needed. details_json is the last
 * details payload with every secret removed.
 */
return [
    'up' => function (\Illuminate\Database\Schema\Builder $schema): void {
        if ($schema->hasTable('mod_lbh_services')) {
            return;
        }
        $schema->create('mod_lbh_services', function (\Illuminate\Database\Schema\Blueprint $t) {
            $t->increments('id');
            $t->unsignedInteger('service_id')->unique();
            $t->string('family', 16);                               // cloud | multidomain | singledomain | reseller | vps | dedicated
            $t->unsignedInteger('account_id')->nullable()->index();
            $t->string('product_key', 64)->nullable();
            $t->unsignedBigInteger('plan_id')->nullable();
            $t->string('plan_name', 191)->nullable();
            $t->dateTime('plan_changed_at')->nullable();
            $t->unsignedBigInteger('customer_id')->nullable();
            $t->unsignedBigInteger('order_id')->nullable()->index();
            $t->string('os', 32)->nullable();
            $t->string('ip_address', 64)->nullable();
            $t->string('panel_username', 64)->nullable();
            $t->unsignedTinyInteger('tenure_months')->nullable();
            $t->string('provider_status', 32)->nullable();
            $t->dateTime('expires_at')->nullable();
            $t->mediumText('details_json')->nullable();            // redacted
            $t->dateTime('last_synced_at')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
        });
    },
];
