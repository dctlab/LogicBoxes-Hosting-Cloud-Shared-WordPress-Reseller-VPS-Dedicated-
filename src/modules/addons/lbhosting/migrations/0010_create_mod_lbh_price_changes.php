<?php

declare(strict_types=1);

/** mod_lbh_price_changes - audit of every price the sync changed; + mod_lbh_services.priced_amount. */
return [
    'up' => function (\Illuminate\Database\Schema\Builder $schema): void {
        if (!$schema->hasTable('mod_lbh_price_changes')) {
            $schema->create('mod_lbh_price_changes', function (\Illuminate\Database\Schema\Blueprint $t) {
                $t->bigIncrements('id');
                $t->string('target', 16);          // product | configoption | service
                $t->unsignedInteger('relid');
                $t->unsignedInteger('currency_id')->nullable();
                $t->string('cycle', 16)->nullable();
                $t->decimal('old_price', 12, 2)->nullable();
                $t->decimal('new_price', 12, 2);
                $t->string('source', 16);          // cron | manual | generator | change
                $t->dateTime('created_at')->index();
            });
        }
        // The recurring amount the module last wrote / recognised for a
        // service: only amounts equal to it are ever changed again.
        if ($schema->hasTable('mod_lbh_services') && !$schema->hasColumn('mod_lbh_services', 'priced_amount')) {
            $schema->table('mod_lbh_services', function (\Illuminate\Database\Schema\Blueprint $t) {
                $t->decimal('priced_amount', 12, 2)->nullable();
            });
        }
    },
];
