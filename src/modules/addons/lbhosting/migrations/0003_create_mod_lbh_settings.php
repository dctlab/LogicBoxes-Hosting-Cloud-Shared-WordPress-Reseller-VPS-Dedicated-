<?php

declare(strict_types=1);

return [
    'up' => function (\Illuminate\Database\Schema\Builder $schema): void {
        if ($schema->hasTable('mod_lbh_settings')) {
            return;
        }
        $schema->create('mod_lbh_settings', function (\Illuminate\Database\Schema\Blueprint $t) {
            $t->string('setting', 64)->primary();
            $t->text('value')->nullable();
        });
    },
];
