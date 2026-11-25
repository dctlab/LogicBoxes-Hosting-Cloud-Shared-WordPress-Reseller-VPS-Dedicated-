<?php

declare(strict_types=1);

/** mod_lbh_accounts - LogicBoxes reseller accounts (multi-brand). api_key_enc is WHMCS-encrypted. */
return [
    'up' => function (\Illuminate\Database\Schema\Builder $schema): void {
        if ($schema->hasTable('mod_lbh_accounts')) {
            return;
        }
        $schema->create('mod_lbh_accounts', function (\Illuminate\Database\Schema\Blueprint $t) {
            $t->increments('id');
            $t->string('name', 100)->unique();
            $t->string('brand', 32)->default('resellerclub');
            $t->string('reseller_id', 32);
            $t->text('api_key_enc');
            $t->boolean('test_mode')->default(false);
            $t->string('api_url', 191)->default('');
            $t->string('branding_url', 191)->default('');
            $t->text('nameservers')->nullable();          // reseller-branded NS for the NS automation
            $t->boolean('is_default')->default(false);
            $t->string('status', 16)->default('active');
            $t->dateTime('last_test_at')->nullable();
            $t->boolean('last_test_ok')->nullable();
            $t->text('last_test_error')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
        });
    },
];
