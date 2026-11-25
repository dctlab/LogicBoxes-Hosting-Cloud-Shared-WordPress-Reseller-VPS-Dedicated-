<?php
/**
 * Multi Domain Hosting (LogicBoxes / ResellerClub) - WHMCS provisioning module.
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

    function lbhmultidomain_MetaData()
    {
        return \LbHosting\WHMCS\ServerModule::metaData('multidomain');
    }

    function lbhmultidomain_ConfigOptions()
    {
        return \LbHosting\WHMCS\ServerModule::configOptions('multidomain');
    }

    /** Dynamic plan dropdown (WHMCS "Loader" config option). */
    function lbhmultidomain_LoaderPlans(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::loaderPlans('multidomain', $params);
    }

    function lbhmultidomain_CreateAccount(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('multidomain', 'CreateAccount', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->create($x));
    }

    function lbhmultidomain_SuspendAccount(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('multidomain', 'SuspendAccount', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->suspend($x));
    }

    function lbhmultidomain_UnsuspendAccount(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('multidomain', 'UnsuspendAccount', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->unsuspend($x));
    }

    function lbhmultidomain_TerminateAccount(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('multidomain', 'TerminateAccount', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->terminate($x));
    }

    /** Plan change and configurable-option (add-on) changes - WHMCS invoices the upgrade, then calls this. */
    function lbhmultidomain_ChangePackage(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('multidomain', 'ChangePackage', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->changePackage($x));
    }

    /** Called by WHMCS when a renewal is paid (the addon's InvoicePaid hook is the fallback; renewals are de-duplicated). */
    function lbhmultidomain_Renew(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('multidomain', 'Renew', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->renew($x, 'module'));
    }

    /** Control panel password (change-password.json). */
    function lbhmultidomain_ChangePassword(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('multidomain', 'ChangePassword', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->changePassword($x));
    }

    function lbhmultidomain_AdminCustomButtonArray()
    {
        return \LbHosting\WHMCS\ServerModule::adminButtons('multidomain');
    }

    function lbhmultidomain_AdminSync(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('multidomain', 'AdminSync', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->sync($x));
    }

    function lbhmultidomain_AdminLinkOrder(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('multidomain', 'AdminLinkOrder', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->linkByDomain($x));
    }

    function lbhmultidomain_AdminRenew(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('multidomain', 'AdminRenew', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->renew($x, 'admin', true));
    }

    function lbhmultidomain_AdminReconcile(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('multidomain', 'AdminReconcile', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->reconcile($x));
    }

    function lbhmultidomain_AdminDetails(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::adminDetails('multidomain', $params);
    }

    function lbhmultidomain_AdminPanelTest(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::adminPanelTest('multidomain', $params);
    }

    function lbhmultidomain_AdminServicesTabFields(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::adminServicesTabFields('multidomain', $params);
    }

    function lbhmultidomain_AdminServicesTabFieldsSave(array $params)
    {
        \LbHosting\WHMCS\ServerModule::adminServicesTabFieldsSave('multidomain', $params);
    }

    /** Client actions (POST + CSRF token, run through clientarea.php?...&modop=custom&a=NAME). */
    function lbhmultidomain_ClientAreaAllowedFunctions()
    {
        return \LbHosting\WHMCS\ClientAreaController::ACTIONS;
    }

    function lbhmultidomain_ClientRefresh(array $params)
    {
        return \LbHosting\WHMCS\ClientAreaController::action('multidomain', 'ClientRefresh', $params);
    }

    function lbhmultidomain_ClientRenew(array $params)
    {
        return \LbHosting\WHMCS\ClientAreaController::action('multidomain', 'ClientRenew', $params);
    }

    function lbhmultidomain_ClientLogin(array $params)
    {
        return \LbHosting\WHMCS\ClientAreaController::action('multidomain', 'ClientLogin', $params);
    }

    function lbhmultidomain_ClientPanel(array $params)
    {
        return \LbHosting\WHMCS\ClientAreaController::action('multidomain', 'ClientPanel', $params);
    }

    function lbhmultidomain_ClientPassword(array $params)
    {
        return \LbHosting\WHMCS\ClientAreaController::action('multidomain', 'ClientPassword', $params);
    }

    function lbhmultidomain_ClientShell(array $params)
    {
        return \LbHosting\WHMCS\ClientAreaController::action('multidomain', 'ClientShell', $params);
    }

    function lbhmultidomain_ClientPower(array $params)
    {
        return \LbHosting\WHMCS\ClientAreaController::action('multidomain', 'ClientPower', $params);
    }

    function lbhmultidomain_ClientNameservers(array $params)
    {
        return \LbHosting\WHMCS\ClientAreaController::action('multidomain', 'ClientNameservers', $params);
    }

    function lbhmultidomain_AdminNameservers(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::adminNameservers('multidomain', $params);
    }

    function lbhmultidomain_ClientArea(array $params)
    {
        return \LbHosting\WHMCS\ClientAreaController::render('multidomain', $params);
    }
} else {
    function lbhmultidomain_MetaData()
    {
        return ['DisplayName' => 'Multi Domain Hosting (LogicBoxes) - upload modules/addons/lbhosting', 'APIVersion' => '1.1', 'RequiresServer' => false];
    }

    function lbhmultidomain_ConfigOptions()
    {
        return [];
    }
}
