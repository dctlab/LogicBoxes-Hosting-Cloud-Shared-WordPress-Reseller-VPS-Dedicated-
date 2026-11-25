<?php
/**
 * Reseller Bulk Hosting (LogicBoxes / ResellerClub) - WHMCS provisioning module.
 *
 * Thin entry points only. All logic lives in the shared LogicBoxes Hosting
 * core: modules/addons/lbhosting/src (LbHosting\ namespace). Upload both folders.
 *
 * @author Indian Server Hosting
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

$lbhAutoload = dirname(__DIR__, 2) . '/addons/lbhosting/autoload.php';

// Functions are declared conditionally so a missing core folder shows a clear
// module name in WHMCS instead of a fatal error.
if (is_file($lbhAutoload)) {
    require_once $lbhAutoload;

    function lbhreseller_MetaData()
    {
        return \LbHosting\WHMCS\ServerModule::metaData('reseller');
    }

    function lbhreseller_ConfigOptions()
    {
        return \LbHosting\WHMCS\ServerModule::configOptions('reseller');
    }

    /** Dynamic plan dropdown (WHMCS "Loader" config option). */
    function lbhreseller_LoaderPlans(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::loaderPlans('reseller', $params);
    }

    function lbhreseller_CreateAccount(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('reseller', 'CreateAccount', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->create($x));
    }

    function lbhreseller_SuspendAccount(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('reseller', 'SuspendAccount', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->suspend($x));
    }

    function lbhreseller_UnsuspendAccount(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('reseller', 'UnsuspendAccount', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->unsuspend($x));
    }

    function lbhreseller_TerminateAccount(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('reseller', 'TerminateAccount', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->terminate($x));
    }

    /** Plan change and configurable-option (add-on) changes - WHMCS invoices the upgrade, then calls this. */
    function lbhreseller_ChangePackage(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('reseller', 'ChangePackage', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->changePackage($x));
    }

    /** Called by WHMCS when a renewal is paid (the addon's InvoicePaid hook is the fallback; renewals are de-duplicated). */
    function lbhreseller_Renew(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('reseller', 'Renew', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->renew($x, 'module'));
    }

    /** Control panel password (change-password.json). */
    function lbhreseller_ChangePassword(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('reseller', 'ChangePassword', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->changePassword($x));
    }

    function lbhreseller_AdminCustomButtonArray()
    {
        return \LbHosting\WHMCS\ServerModule::adminButtons('reseller');
    }

    function lbhreseller_AdminSync(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('reseller', 'AdminSync', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->sync($x));
    }

    function lbhreseller_AdminLinkOrder(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('reseller', 'AdminLinkOrder', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->linkByDomain($x));
    }

    function lbhreseller_AdminRenew(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('reseller', 'AdminRenew', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->renew($x, 'admin', true));
    }

    function lbhreseller_AdminReconcile(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('reseller', 'AdminReconcile', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->reconcile($x));
    }

    function lbhreseller_AdminDetails(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::adminDetails('reseller', $params);
    }

    function lbhreseller_AdminPanelTest(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::adminPanelTest('reseller', $params);
    }

    function lbhreseller_AdminServicesTabFields(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::adminServicesTabFields('reseller', $params);
    }

    function lbhreseller_AdminServicesTabFieldsSave(array $params)
    {
        \LbHosting\WHMCS\ServerModule::adminServicesTabFieldsSave('reseller', $params);
    }

    /** Client actions (POST + CSRF token, run through clientarea.php?...&modop=custom&a=NAME). */
    function lbhreseller_ClientAreaAllowedFunctions()
    {
        return \LbHosting\WHMCS\ClientAreaController::ACTIONS;
    }

    function lbhreseller_ClientRefresh(array $params)
    {
        return \LbHosting\WHMCS\ClientAreaController::action('reseller', 'ClientRefresh', $params);
    }

    function lbhreseller_ClientRenew(array $params)
    {
        return \LbHosting\WHMCS\ClientAreaController::action('reseller', 'ClientRenew', $params);
    }

    function lbhreseller_ClientLogin(array $params)
    {
        return \LbHosting\WHMCS\ClientAreaController::action('reseller', 'ClientLogin', $params);
    }

    function lbhreseller_ClientPanel(array $params)
    {
        return \LbHosting\WHMCS\ClientAreaController::action('reseller', 'ClientPanel', $params);
    }

    function lbhreseller_ClientPassword(array $params)
    {
        return \LbHosting\WHMCS\ClientAreaController::action('reseller', 'ClientPassword', $params);
    }

    function lbhreseller_ClientShell(array $params)
    {
        return \LbHosting\WHMCS\ClientAreaController::action('reseller', 'ClientShell', $params);
    }

    function lbhreseller_ClientPower(array $params)
    {
        return \LbHosting\WHMCS\ClientAreaController::action('reseller', 'ClientPower', $params);
    }

    function lbhreseller_ClientNameservers(array $params)
    {
        return \LbHosting\WHMCS\ClientAreaController::action('reseller', 'ClientNameservers', $params);
    }

    function lbhreseller_AdminNameservers(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::adminNameservers('reseller', $params);
    }

    function lbhreseller_ClientArea(array $params)
    {
        return \LbHosting\WHMCS\ClientAreaController::render('reseller', $params);
    }
} else {
    function lbhreseller_MetaData()
    {
        return ['DisplayName' => 'Reseller Bulk Hosting (LogicBoxes) - upload modules/addons/lbhosting', 'APIVersion' => '1.1', 'RequiresServer' => false];
    }

    function lbhreseller_ConfigOptions()
    {
        return [];
    }
}
