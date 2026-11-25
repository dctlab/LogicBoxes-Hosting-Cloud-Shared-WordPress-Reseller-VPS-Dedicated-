<?php

declare(strict_types=1);

/**
 * mod_lbh_manual_tasks - items the LogicBoxes API cannot provision (Cloud
 * Hosting dedicated IP, Dedicated Server bandwidth, ...). Each requested
 * change becomes one row plus a WHMCS To-Do item and/or a support ticket.
 * UNIQUE(service_id, item, target) stops a re-run of Create / ChangePackage
 * from opening duplicate tickets.
 */
return [
    'up' => function (\Illuminate\Database\Schema\Builder $schema): void {
        if ($schema->hasTable('mod_lbh_manual_tasks')) {
            return;
        }
        $schema->create('mod_lbh_manual_tasks', function (\Illuminate\Database\Schema\Blueprint $t) {
            $t->increments('id');
            $t->unsignedInteger('service_id')->index();
            $t->string('item', 48);                     // cloud_dedicated_ip | bandwidth | ...
            $t->string('target', 64);                   // requested value, e.g. "1" or "10TB"
            $t->string('previous', 64)->nullable();
            $t->string('status', 16)->default('open');  // open | done | cancelled
            $t->unsignedInteger('todo_id')->nullable();
            $t->unsignedInteger('ticket_id')->nullable();
            $t->text('note')->nullable();
            $t->dateTime('created_at');
            $t->dateTime('updated_at')->nullable();
            $t->unique(['service_id', 'item', 'target']);
        });
    },
];
