<?php
/**
 * LogicBoxes Hosting - Cloud, Multi Domain, Single Domain, Reseller, WordPress
 * and Business hosting, VPS and Dedicated / Managed servers for WHMCS
 * (ResellerClub, NetEarthOne and other LogicBoxes reseller accounts).
 *
 * This addon is the shared core: admin pages, hooks and the code used by the
 * eight provisioning modules modules/servers/lbhcloud, lbhmultidomain,
 * lbhsingledomain, lbhreseller, lbhwordpress, lbhbusiness, lbhvps and lbhdedicated.
 *
 * @author Indian Server Hosting
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/autoload.php';

function lbhosting_config()
{
    return [
        'name' => 'LogicBoxes Hosting (Cloud, Shared, WordPress, Reseller, VPS, Dedicated)',
        'description' => 'Sell ResellerClub / LogicBoxes Cloud, Multi Domain, Single Domain, Reseller, WordPress and Business hosting, VPS and Dedicated servers through one or more reseller accounts.',
        'version' => LBH_VERSION,
        'author' => 'Indian Server Hosting',
        'language' => 'english',
        'fields' => [],
    ];
}

/** "Auto install": creates every mod_lbh_* table idempotently. */
function lbhosting_activate()
{
    try {
        \LbHosting\WHMCS\Database::migrate();

        return ['status' => 'success', 'description' => 'LogicBoxes Hosting installed. Next: add a LogicBoxes API account under Addons > LogicBoxes Hosting.'];
    } catch (\Throwable $e) {
        return ['status' => 'error', 'description' => 'Activation failed: ' . $e->getMessage()];
    }
}

/** Never drops data - deactivating must not destroy service links. */
function lbhosting_deactivate()
{
    return ['status' => 'success', 'description' => 'Deactivated. Module tables and data were kept.'];
}

/** Runs new migrations when the version number increases. */
function lbhosting_upgrade($vars)
{
    try {
        \LbHosting\WHMCS\Database::migrate();
    } catch (\Throwable $e) {
        if (function_exists('logActivity')) {
            logActivity('LogicBoxes Hosting: upgrade migration failed - ' . $e->getMessage());
        }
    }
}

function lbhosting_output($vars)
{
    try {
        echo (new \LbHosting\Admin\AddonController($vars))->dispatch();
    } catch (\Throwable $e) {
        \LbHosting\Support\Logger::log('admin', 'Addon page error: ' . $e->getMessage(), 'error');
        echo '<div class="alert alert-danger">' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>';
    }
}
