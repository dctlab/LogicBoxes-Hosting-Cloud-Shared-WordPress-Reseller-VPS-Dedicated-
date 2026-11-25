<?php
/**
 * Cloud Hosting (LogicBoxes / ResellerClub) - WHMCS provisioning module.
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

    function lbhcloud_MetaData()
    {
        return \LbHosting\WHMCS\ServerModule::metaData('cloud');
    }

    function lbhcloud_ConfigOptions()
    {
        return \LbHosting\WHMCS\ServerModule::configOptions('cloud');
    }

    /** Dynamic plan dropdown (WHMCS "Loader" config option). */
    function lbhcloud_LoaderPlans(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::loaderPlans('cloud', $params);
    }

    function lbhcloud_CreateAccount(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('cloud', 'CreateAccount', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->create($x));
    }

    function lbhcloud_SuspendAccount(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('cloud', 'SuspendAccount', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->suspend($x));
    }

    function lbhcloud_UnsuspendAccount(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('cloud', 'UnsuspendAccount', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->unsuspend($x));
    }

    function lbhcloud_TerminateAccount(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('cloud', 'TerminateAccount', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->terminate($x));
    }

    /** Plan change and configurable-option (add-on) changes - WHMCS invoices the upgrade, then calls this. */
    function lbhcloud_ChangePackage(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('cloud', 'ChangePackage', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->changePackage($x));
    }

    /** Called by WHMCS when a renewal is paid (the addon's InvoicePaid hook is the fallback; renewals are de-duplicated). */
    function lbhcloud_Renew(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('cloud', 'Renew', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->renew($x, 'module'));
    }

    /** Control panel password (change-password.json). */
    function lbhcloud_ChangePassword(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('cloud', 'ChangePassword', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->changePassword($x));
    }

    function lbhcloud_AdminCustomButtonArray()
    {
        return \LbHosting\WHMCS\ServerModule::adminButtons('cloud');
    }

    function lbhcloud_AdminSync(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('cloud', 'AdminSync', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->sync($x));
    }

    function lbhcloud_AdminLinkOrder(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('cloud', 'AdminLinkOrder', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->linkByDomain($x));
    }

    function lbhcloud_AdminRenew(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('cloud', 'AdminRenew', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->renew($x, 'admin', true));
    }

    function lbhcloud_AdminReconcile(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('cloud', 'AdminReconcile', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->reconcile($x));
    }

    function lbhcloud_AdminDetails(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::adminDetails('cloud', $params);
    }

    function lbhcloud_AdminPanelTest(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::adminPanelTest('cloud', $params);
    }

    function lbhcloud_AdminServicesTabFields(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::adminServicesTabFields('cloud', $params);
    }

    function lbhcloud_AdminServicesTabFieldsSave(array $params)
    {
        \LbHosting\WHMCS\ServerModule::adminServicesTabFieldsSave('cloud', $params);
    }

    /** Client actions (POST + CSRF token, run through clientarea.php?...&modop=custom&a=NAME). */
    function lbhcloud_ClientAreaAllowedFunctions()
    {
        return \LbHosting\WHMCS\ClientAreaController::ACTIONS;
    }

    function lbhcloud_ClientRefresh(array $params)
    {
        return \LbHosting\WHMCS\ClientAreaController::action('cloud', 'ClientRefresh', $params);
    }

    function lbhcloud_ClientRenew(array $params)
    {
        return \LbHosting\WHMCS\ClientAreaController::action('cloud', 'ClientRenew', $params);
    }

    function lbhcloud_ClientLogin(array $params)
    {
        return \LbHosting\WHMCS\ClientAreaController::action('cloud', 'ClientLogin', $params);
    }

    function lbhcloud_ClientPanel(array $params)
    {
        return \LbHosting\WHMCS\ClientAreaController::action('cloud', 'ClientPanel', $params);
    }

    function lbhcloud_ClientPassword(array $params)
    {
        return \LbHosting\WHMCS\ClientAreaController::action('cloud', 'ClientPassword', $params);
    }

    function lbhcloud_ClientShell(array $params)
    {
        return \LbHosting\WHMCS\ClientAreaController::action('cloud', 'ClientShell', $params);
    }

    function lbhcloud_ClientPower(array $params)
    {
        return \LbHosting\WHMCS\ClientAreaController::action('cloud', 'ClientPower', $params);
    }

    function lbhcloud_ClientNameservers(array $params)
    {
        return \LbHosting\WHMCS\ClientAreaController::action('cloud', 'ClientNameservers', $params);
    }

    function lbhcloud_AdminNameservers(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::adminNameservers('cloud', $params);
    }

    function lbhcloud_ClientArea(array $params)
    {
        return \LbHosting\WHMCS\ClientAreaController::render('cloud', $params);
    }
} else {
    function lbhcloud_MetaData()
    {
        return ['DisplayName' => 'Cloud Hosting (LogicBoxes) - upload modules/addons/lbhosting', 'APIVersion' => '1.1', 'RequiresServer' => false];
    }

    function lbhcloud_ConfigOptions()
    {
        return [];
    }
}
