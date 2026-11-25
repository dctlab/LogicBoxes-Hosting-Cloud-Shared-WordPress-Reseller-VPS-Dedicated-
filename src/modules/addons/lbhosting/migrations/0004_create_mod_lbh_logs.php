<?php

declare(strict_types=1);

/** mod_lbh_logs - module audit trail (context JSON is redacted before insert). */
return [
    'up' => function (\Illuminate\Database\Schema\Builder $schema): void {
        if ($schema->hasTable('mod_lbh_logs')) {
            return;
        }
        $schema->create('mod_lbh_logs', function (\Illuminate\Database\Schema\Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedInteger('service_id')->nullable()->index();
            $t->string('action', 64);
            $t->string('level', 16)->default('info');
            $t->text('message');
            $t->text('context')->nullable();
            $t->dateTime('created_at')->index();
        });
    },
];
