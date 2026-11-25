<?php
/**
 * Business Hosting (LogicBoxes / ResellerClub) - WHMCS provisioning module.
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

    function lbhbusiness_MetaData()
    {
        return \LbHosting\WHMCS\ServerModule::metaData('business');
    }

    function lbhbusiness_ConfigOptions()
    {
        return \LbHosting\WHMCS\ServerModule::configOptions('business');
    }

    /** Dynamic plan dropdown (WHMCS "Loader" config option). */
    function lbhbusiness_LoaderPlans(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::loaderPlans('business', $params);
    }

    function lbhbusiness_CreateAccount(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('business', 'CreateAccount', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->create($x));
    }

    function lbhbusiness_SuspendAccount(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('business', 'SuspendAccount', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->suspend($x));
    }

    function lbhbusiness_UnsuspendAccount(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('business', 'UnsuspendAccount', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->unsuspend($x));
    }

    function lbhbusiness_TerminateAccount(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('business', 'TerminateAccount', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->terminate($x));
    }

    /** Plan change and configurable-option (add-on) changes - WHMCS invoices the upgrade, then calls this. */
    function lbhbusiness_ChangePackage(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('business', 'ChangePackage', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->changePackage($x));
    }

    /** Called by WHMCS when a renewal is paid (the addon's InvoicePaid hook is the fallback; renewals are de-duplicated). */
    function lbhbusiness_Renew(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('business', 'Renew', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->renew($x, 'module'));
    }

    /** Control panel password (change-password.json). */
    function lbhbusiness_ChangePassword(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('business', 'ChangePassword', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->changePassword($x));
    }

    function lbhbusiness_AdminCustomButtonArray()
    {
        return \LbHosting\WHMCS\ServerModule::adminButtons('business');
    }

    function lbhbusiness_AdminSync(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('business', 'AdminSync', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->sync($x));
    }

    function lbhbusiness_AdminLinkOrder(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('business', 'AdminLinkOrder', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->linkByDomain($x));
    }

    function lbhbusiness_AdminRenew(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('business', 'AdminRenew', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->renew($x, 'admin', true));
    }

    function lbhbusiness_AdminReconcile(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('business', 'AdminReconcile', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->reconcile($x));
    }

    function lbhbusiness_AdminDetails(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::adminDetails('business', $params);
    }

    function lbhbusiness_AdminPanelTest(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::adminPanelTest('business', $params);
    }

    function lbhbusiness_AdminServicesTabFields(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::adminServicesTabFields('business', $params);
    }

    function lbhbusiness_AdminServicesTabFieldsSave(array $params)
    {
        \LbHosting\WHMCS\ServerModule::adminServicesTabFieldsSave('business', $params);
    }

    /** Client actions (POST + CSRF token, run through clientarea.php?...&modop=custom&a=NAME). */
    function lbhbusiness_ClientAreaAllowedFunctions()
    {
        return \LbHosting\WHMCS\ClientAreaController::ACTIONS;
    }

    function lbhbusiness_ClientRefresh(array $params)
    {
        return \LbHosting\WHMCS\ClientAreaController::action('business', 'ClientRefresh', $params);
    }

    function lbhbusiness_ClientRenew(array $params)
    {
        return \LbHosting\WHMCS\ClientAreaController::action('business', 'ClientRenew', $params);
    }

    function lbhbusiness_ClientLogin(array $params)
    {
        return \LbHosting\WHMCS\ClientAreaController::action('business', 'ClientLogin', $params);
    }

    function lbhbusiness_ClientPanel(array $params)
    {
        return \LbHosting\WHMCS\ClientAreaController::action('business', 'ClientPanel', $params);
    }

    function lbhbusiness_ClientPassword(array $params)
    {
        return \LbHosting\WHMCS\ClientAreaController::action('business', 'ClientPassword', $params);
    }

    function lbhbusiness_ClientShell(array $params)
    {
        return \LbHosting\WHMCS\ClientAreaController::action('business', 'ClientShell', $params);
    }

    function lbhbusiness_ClientPower(array $params)
    {
        return \LbHosting\WHMCS\ClientAreaController::action('business', 'ClientPower', $params);
    }

    function lbhbusiness_ClientNameservers(array $params)
    {
        return \LbHosting\WHMCS\ClientAreaController::action('business', 'ClientNameservers', $params);
    }

    function lbhbusiness_AdminNameservers(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::adminNameservers('business', $params);
    }

    function lbhbusiness_ClientArea(array $params)
    {
        return \LbHosting\WHMCS\ClientAreaController::render('business', $params);
    }
} else {
    function lbhbusiness_MetaData()
    {
        return ['DisplayName' => 'Business Hosting (LogicBoxes) - upload modules/addons/lbhosting', 'APIVersion' => '1.1', 'RequiresServer' => false];
    }

    function lbhbusiness_ConfigOptions()
    {
        return [];
    }
}
