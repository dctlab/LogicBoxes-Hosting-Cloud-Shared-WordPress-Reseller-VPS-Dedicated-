<?php

declare(strict_types=1);

/**
 * mod_lbh_addons - provider-side add-ons of a service, as last applied by the
 * module: one row per (service, addon code). "quantity" is what the module
 * believes is active at LogicBoxes; provider_ids keeps the addon ids LogicBoxes
 * returned (needed for addon/delete.json). This is what makes add-on changes
 * idempotent: Create / ChangePackage only send the difference.
 */
return [
    'up' => function (\Illuminate\Database\Schema\Builder $schema): void {
        if ($schema->hasTable('mod_lbh_addons')) {
            return;
        }
        $schema->create('mod_lbh_addons', function (\Illuminate\Database\Schema\Blueprint $t) {
            $t->increments('id');
            $t->unsignedInteger('service_id')->index();
            $t->string('addon', 48);                    // ipaddress | cpu_1 | ram_1 | whmcs | cpanel | storage_2 | dedicated_ip | cpanel_blocks ...
            $t->unsignedInteger('quantity')->default(0);
            $t->text('provider_ids')->nullable();       // JSON list of LogicBoxes addon ids / IPs / licence keys
            $t->dateTime('updated_at')->nullable();
            $t->unique(['service_id', 'addon']);
        });
    },
];
