<?php

declare(strict_types=1);

/**
 * Phase 5 (client dashboards):
 *  - mod_lbh_services.dns_json / dns_fetched_at: the DNS records LogicBoxes
 *    says the domain needs (dns-record.json), cached for the dashboard.
 *  - mod_lbh_dns_jobs: outcome of the name server / DNS automation per
 *    service, so the automatic run happens once and is visible to admins.
 */
return [
    'up' => function (\Illuminate\Database\Schema\Builder $schema): void {
        if ($schema->hasTable('mod_lbh_services') && !$schema->hasColumn('mod_lbh_services', 'dns_json')) {
            $schema->table('mod_lbh_services', function (\Illuminate\Database\Schema\Blueprint $t) {
                $t->text('dns_json')->nullable();
                $t->dateTime('dns_fetched_at')->nullable();
            });
        }
        if (!$schema->hasTable('mod_lbh_dns_jobs')) {
            $schema->create('mod_lbh_dns_jobs', function (\Illuminate\Database\Schema\Blueprint $t) {
                $t->increments('id');
                $t->unsignedInteger('service_id')->unique();
                $t->string('mode', 16);                    // branded | server
                $t->string('status', 16);                  // done | skipped | failed
                $t->string('source', 16)->nullable();      // create | client | admin
                $t->text('nameservers')->nullable();       // JSON list that was set
                $t->unsignedSmallInteger('records_added')->default(0);
                $t->text('message')->nullable();
                $t->dateTime('created_at');
                $t->dateTime('updated_at')->nullable();
            });
        }
    },
];
