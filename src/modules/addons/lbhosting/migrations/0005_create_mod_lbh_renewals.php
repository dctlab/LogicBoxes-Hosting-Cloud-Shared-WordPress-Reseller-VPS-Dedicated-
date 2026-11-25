<?php

declare(strict_types=1);

/**
 * mod_lbh_renewals - provider renewals. UNIQUE(service_id, period_key) is the
 * idempotency guard: the module Renew call and the InvoicePaid fallback hook
 * can both fire for one payment, but only one provider renewal per billing
 * period can ever be recorded.
 */
return [
    'up' => function (\Illuminate\Database\Schema\Builder $schema): void {
        if ($schema->hasTable('mod_lbh_renewals')) {
            return;
        }
        $schema->create('mod_lbh_renewals', function (\Illuminate\Database\Schema\Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedInteger('service_id')->index();
            $t->string('period_key', 32);
            $t->unsignedInteger('invoice_id')->nullable()->index();
            $t->string('source', 16);          // module | hook | admin | ondemand
            $t->string('status', 16);          // invoiced | processing | renewed | failed
            $t->unsignedTinyInteger('months')->nullable();
            $t->text('message')->nullable();
            $t->dateTime('created_at');
            $t->dateTime('updated_at')->nullable();
            $t->unique(['service_id', 'period_key']);
        });
    },
];
