<?php
/**
 * VPS (Linux KVM) (LogicBoxes / ResellerClub) - WHMCS provisioning module.
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

    function lbhvps_MetaData()
    {
        return \LbHosting\WHMCS\ServerModule::metaData('vps');
    }

    function lbhvps_ConfigOptions()
    {
        return \LbHosting\WHMCS\ServerModule::configOptions('vps');
    }

    /** Dynamic plan dropdown (WHMCS "Loader" config option). */
    function lbhvps_LoaderPlans(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::loaderPlans('vps', $params);
    }

    function lbhvps_CreateAccount(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('vps', 'CreateAccount', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->create($x));
    }

    function lbhvps_SuspendAccount(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('vps', 'SuspendAccount', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->suspend($x));
    }

    function lbhvps_UnsuspendAccount(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('vps', 'UnsuspendAccount', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->unsuspend($x));
    }

    function lbhvps_TerminateAccount(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('vps', 'TerminateAccount', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->terminate($x));
    }

    /** Plan change and configurable-option (add-on) changes - WHMCS invoices the upgrade, then calls this. */
    function lbhvps_ChangePackage(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('vps', 'ChangePackage', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->changePackage($x));
    }

    /** Called by WHMCS when a renewal is paid (the addon's InvoicePaid hook is the fallback; renewals are de-duplicated). */
    function lbhvps_Renew(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('vps', 'Renew', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->renew($x, 'module'));
    }

    function lbhvps_AdminCustomButtonArray()
    {
        return \LbHosting\WHMCS\ServerModule::adminButtons('vps');
    }

    function lbhvps_AdminSync(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('vps', 'AdminSync', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->sync($x));
    }

    function lbhvps_AdminLinkOrder(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('vps', 'AdminLinkOrder', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->linkByDomain($x));
    }

    function lbhvps_AdminRenew(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('vps', 'AdminRenew', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->renew($x, 'admin', true));
    }

    function lbhvps_AdminReconcile(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('vps', 'AdminReconcile', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->reconcile($x));
    }

    function lbhvps_AdminDetails(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::adminDetails('vps', $params);
    }

    function lbhvps_AdminPanelTest(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::adminPanelTest('vps', $params);
    }

    function lbhvps_AdminServicesTabFields(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::adminServicesTabFields('vps', $params);
    }

    function lbhvps_AdminServicesTabFieldsSave(array $params)
    {
        \LbHosting\WHMCS\ServerModule::adminServicesTabFieldsSave('vps', $params);
    }

    /** Client actions (POST + CSRF token, run through clientarea.php?...&modop=custom&a=NAME). */
    function lbhvps_ClientAreaAllowedFunctions()
    {
        return \LbHosting\WHMCS\ClientAreaController::ACTIONS;
    }

    function lbhvps_ClientRefresh(array $params)
    {
        return \LbHosting\WHMCS\ClientAreaController::action('vps', 'ClientRefresh', $params);
    }

    function lbhvps_ClientRenew(array $params)
    {
        return \LbHosting\WHMCS\ClientAreaController::action('vps', 'ClientRenew', $params);
    }

    function lbhvps_ClientLogin(array $params)
    {
        return \LbHosting\WHMCS\ClientAreaController::action('vps', 'ClientLogin', $params);
    }

    function lbhvps_ClientPanel(array $params)
    {
        return \LbHosting\WHMCS\ClientAreaController::action('vps', 'ClientPanel', $params);
    }

    function lbhvps_ClientPassword(array $params)
    {
        return \LbHosting\WHMCS\ClientAreaController::action('vps', 'ClientPassword', $params);
    }

    function lbhvps_ClientShell(array $params)
    {
        return \LbHosting\WHMCS\ClientAreaController::action('vps', 'ClientShell', $params);
    }

    function lbhvps_ClientPower(array $params)
    {
        return \LbHosting\WHMCS\ClientAreaController::action('vps', 'ClientPower', $params);
    }

    function lbhvps_ClientNameservers(array $params)
    {
        return \LbHosting\WHMCS\ClientAreaController::action('vps', 'ClientNameservers', $params);
    }

    function lbhvps_AdminNameservers(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::adminNameservers('vps', $params);
    }

    function lbhvps_ClientArea(array $params)
    {
        return \LbHosting\WHMCS\ClientAreaController::render('vps', $params);
    }
} else {
    function lbhvps_MetaData()
    {
        return ['DisplayName' => 'VPS (Linux KVM) (LogicBoxes) - upload modules/addons/lbhosting', 'APIVersion' => '1.1', 'RequiresServer' => false];
    }

    function lbhvps_ConfigOptions()
    {
        return [];
    }
}
