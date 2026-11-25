<?php
/**
 * Single Domain Hosting (LogicBoxes / ResellerClub) - WHMCS provisioning module.
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

    function lbhsingledomain_MetaData()
    {
        return \LbHosting\WHMCS\ServerModule::metaData('singledomain');
    }

    function lbhsingledomain_ConfigOptions()
    {
        return \LbHosting\WHMCS\ServerModule::configOptions('singledomain');
    }

    /** Dynamic plan dropdown (WHMCS "Loader" config option). */
    function lbhsingledomain_LoaderPlans(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::loaderPlans('singledomain', $params);
    }

    function lbhsingledomain_CreateAccount(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('singledomain', 'CreateAccount', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->create($x));
    }

    function lbhsingledomain_SuspendAccount(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('singledomain', 'SuspendAccount', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->suspend($x));
    }

    function lbhsingledomain_UnsuspendAccount(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('singledomain', 'UnsuspendAccount', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->unsuspend($x));
    }

    function lbhsingledomain_TerminateAccount(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('singledomain', 'TerminateAccount', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->terminate($x));
    }

    /** Plan change and configurable-option (add-on) changes - WHMCS invoices the upgrade, then calls this. */
    function lbhsingledomain_ChangePackage(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('singledomain', 'ChangePackage', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->changePackage($x));
    }

    /** Called by WHMCS when a renewal is paid (the addon's InvoicePaid hook is the fallback; renewals are de-duplicated). */
    function lbhsingledomain_Renew(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('singledomain', 'Renew', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->renew($x, 'module'));
    }

    /** Control panel password (change-password.json). */
    function lbhsingledomain_ChangePassword(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('singledomain', 'ChangePassword', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->changePassword($x));
    }

    function lbhsingledomain_AdminCustomButtonArray()
    {
        return \LbHosting\WHMCS\ServerModule::adminButtons('singledomain');
    }

    function lbhsingledomain_AdminSync(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('singledomain', 'AdminSync', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->sync($x));
    }

    function lbhsingledomain_AdminLinkOrder(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('singledomain', 'AdminLinkOrder', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->linkByDomain($x));
    }

    function lbhsingledomain_AdminRenew(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('singledomain', 'AdminRenew', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->renew($x, 'admin', true));
    }

    function lbhsingledomain_AdminReconcile(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('singledomain', 'AdminReconcile', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->reconcile($x));
    }

    function lbhsingledomain_AdminDetails(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::adminDetails('singledomain', $params);
    }

    function lbhsingledomain_AdminPanelTest(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::adminPanelTest('singledomain', $params);
    }

    function lbhsingledomain_AdminServicesTabFields(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::adminServicesTabFields('singledomain', $params);
    }

    function lbhsingledomain_AdminServicesTabFieldsSave(array $params)
    {
        \LbHosting\WHMCS\ServerModule::adminServicesTabFieldsSave('singledomain', $params);
    }

    /** Client actions (POST + CSRF token, run through clientarea.php?...&modop=custom&a=NAME). */
    function lbhsingledomain_ClientAreaAllowedFunctions()
    {
        return \LbHosting\WHMCS\ClientAreaController::ACTIONS;
    }

    function lbhsingledomain_ClientRefresh(array $params)
    {
        return \LbHosting\WHMCS\ClientAreaController::action('singledomain', 'ClientRefresh', $params);
    }

    function lbhsingledomain_ClientRenew(array $params)
    {
        return \LbHosting\WHMCS\ClientAreaController::action('singledomain', 'ClientRenew', $params);
    }

    function lbhsingledomain_ClientLogin(array $params)
    {
        return \LbHosting\WHMCS\ClientAreaController::action('singledomain', 'ClientLogin', $params);
    }

    function lbhsingledomain_ClientPanel(array $params)
    {
        return \LbHosting\WHMCS\ClientAreaController::action('singledomain', 'ClientPanel', $params);
    }

    function lbhsingledomain_ClientPassword(array $params)
    {
        return \LbHosting\WHMCS\ClientAreaController::action('singledomain', 'ClientPassword', $params);
    }

    function lbhsingledomain_ClientShell(array $params)
    {
        return \LbHosting\WHMCS\ClientAreaController::action('singledomain', 'ClientShell', $params);
    }

    function lbhsingledomain_ClientPower(array $params)
    {
        return \LbHosting\WHMCS\ClientAreaController::action('singledomain', 'ClientPower', $params);
    }

    function lbhsingledomain_ClientNameservers(array $params)
    {
        return \LbHosting\WHMCS\ClientAreaController::action('singledomain', 'ClientNameservers', $params);
    }

    function lbhsingledomain_AdminNameservers(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::adminNameservers('singledomain', $params);
    }

    function lbhsingledomain_ClientArea(array $params)
    {
        return \LbHosting\WHMCS\ClientAreaController::render('singledomain', $params);
    }
} else {
    function lbhsingledomain_MetaData()
    {
        return ['DisplayName' => 'Single Domain Hosting (LogicBoxes) - upload modules/addons/lbhosting', 'APIVersion' => '1.1', 'RequiresServer' => false];
    }

    function lbhsingledomain_ConfigOptions()
    {
        return [];
    }
}
