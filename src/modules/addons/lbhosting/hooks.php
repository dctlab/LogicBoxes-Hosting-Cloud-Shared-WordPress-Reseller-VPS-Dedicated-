<?php
/**
 * LogicBoxes Hosting hooks. WHMCS loads this only while the addon is active.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/autoload.php';

/**
 * Provider renewal fallback. If this WHMCS version does not invoke the
 * module's Renew function when a renewal invoice is paid, this hook renews
 * at LogicBoxes instead. Renewals are de-duplicated per billing period.
 */
add_hook('InvoicePaid', 1, function ($vars) {
    try {
        \LbHosting\Hooks\RenewalHook::onInvoicePaid((int) ($vars['invoiceid'] ?? 0));
    } catch (\Throwable $e) {
        \LbHosting\Support\Logger::log('hook', 'InvoicePaid hook error: ' . $e->getMessage(), 'error');
    }
});

/**
 * Provider-priced plan / add-on changes: the change is applied at LogicBoxes
 * when its invoice is paid, and dropped when the invoice is cancelled.
 * UpgradeService::apply() never throws and can never apply twice.
 */
add_hook('InvoicePaid', 2, function ($vars) {
    try {
        \LbHosting\WHMCS\Database::ensure();
        (new \LbHosting\Service\UpgradeService())->onInvoicePaid((int) ($vars['invoiceid'] ?? 0));
    } catch (\Throwable $e) {
        \LbHosting\Support\Logger::log('upgrade', 'InvoicePaid (upgrade) hook error: ' . $e->getMessage(), 'error');
    }
});
add_hook('InvoiceCancelled', 1, function ($vars) {
    try {
        \LbHosting\WHMCS\Database::ensure();
        (new \LbHosting\Service\UpgradeService())->onInvoiceCancelled((int) ($vars['invoiceid'] ?? 0));
    } catch (\Throwable $e) {
        \LbHosting\Support\Logger::log('upgrade', 'InvoiceCancelled hook error: ' . $e->getMessage(), 'error');
    }
});

/**
 * Name server automation: finish what could not be done at Create (domain
 * not Active yet, order not set up yet).
 */
add_hook('DailyCronJob', 2, function ($vars) {
    try {
        \LbHosting\WHMCS\Database::ensure();
        (new \LbHosting\Service\NameserverAutomation())->retryPending();
    } catch (\Throwable $e) {
        \LbHosting\Support\Logger::log('nameservers', 'Daily name server retry failed: ' . $e->getMessage(), 'error');
    }
});

/**
 * Price sync: every WHMCS cron run checks whether the price check is due
 * (Settings > Price check interval). When it is, the LogicBoxes price lists
 * are fetched and, if any price changed, products, configurable options and
 * (where enabled per product) existing services' recurring amounts follow.
 */
add_hook('AfterCronJob', 1, function ($vars) {
    try {
        \LbHosting\WHMCS\Database::ensure();
        (new \LbHosting\Pricing\PriceSync())->autoSync();
    } catch (\Throwable $e) {
        \LbHosting\Support\Logger::log('price_sync', 'Price sync failed: ' . $e->getMessage(), 'error');
    }
});

/** Signed self-update: daily check, optional automatic install (Addons > LogicBoxes Hosting > Updates). */
add_hook('DailyCronJob', 3, function ($vars) {
    try {
        \LbHosting\WHMCS\Database::ensure();
        if (!\LbHosting\Support\Settings::bool('update_check') || \LbHosting\Support\Settings::get('update_url') === '') {
            return;
        }
        $updater = new \LbHosting\Update\Updater();
        $state = $updater->check();
        if (!empty($state['available']) && !empty($state['php_ok']) && \LbHosting\Support\Settings::bool('update_auto')) {
            $updater->install();
        }
    } catch (\Throwable $e) {
        \LbHosting\Support\Logger::log('update', 'Scheduled update failed: ' . $e->getMessage(), 'error');
    }
});
