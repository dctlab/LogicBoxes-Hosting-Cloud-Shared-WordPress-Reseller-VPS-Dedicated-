<?php
/**
 * PSR-4 autoloader for the LbHosting\ namespace (shared core of the eight
 * LogicBoxes hosting / server provisioning modules).
 *
 * WHMCS never runs Composer for a module and this code has no third-party
 * runtime dependencies (Capsule comes from WHMCS itself), so this small
 * loader is all a plain upload needs.
 */

declare(strict_types=1);

if (!defined('LBH_ROOT')) {
    define('LBH_ROOT', __DIR__);
}
if (!defined('LBH_VERSION')) {
    define('LBH_VERSION', '1.3.2');
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'LbHosting\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $path = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require $path;
    }
});
