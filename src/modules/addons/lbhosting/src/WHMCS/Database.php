<?php

declare(strict_types=1);

namespace LbHosting\WHMCS;

use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Idempotent, file-based migrations (same approach as ovhserver): applied
 * migrations are recorded in mod_lbh_migrations; each migration guards
 * itself with hasTable()/hasColumn(). No transaction wrapper - MySQL DDL
 * commits implicitly. Data is never dropped on deactivate/upgrade.
 */
final class Database
{
    private static bool $checked = false;

    public static function migrate(): void
    {
        $schema = Capsule::schema();
        if (!$schema->hasTable('mod_lbh_migrations')) {
            $schema->create('mod_lbh_migrations', function ($t) {
                $t->increments('id');
                $t->string('migration', 191)->unique();
                $t->dateTime('applied_at');
            });
        }
        $applied = array_flip(Capsule::table('mod_lbh_migrations')->pluck('migration')->all());
        $files = glob(dirname(__DIR__, 2) . '/migrations/*.php') ?: [];
        sort($files);
        foreach ($files as $file) {
            $name = basename($file, '.php');
            if (isset($applied[$name])) {
                continue;
            }
            $m = require $file;
            if (is_array($m) && isset($m['up']) && is_callable($m['up'])) {
                ($m['up'])(Capsule::schema());
                Capsule::table('mod_lbh_migrations')->insert(['migration' => $name, 'applied_at' => date('Y-m-d H:i:s')]);
            }
        }
        self::$checked = true;
    }

    /** Cheap guard for server-module entry points (module used before addon activation). */
    public static function ensure(): void
    {
        if (self::$checked) {
            return;
        }
        $files = glob(dirname(__DIR__, 2) . '/migrations/*.php') ?: [];
        $schema = Capsule::schema();
        if (!$schema->hasTable('mod_lbh_migrations')
            || Capsule::table('mod_lbh_migrations')->count() < count($files)) {
            self::migrate();
        }
        self::$checked = true;
    }
}
