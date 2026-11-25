<?php
/**
 * Dedicated Server (LogicBoxes / ResellerClub) - WHMCS provisioning module.
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

    function lbhdedicated_MetaData()
    {
        return \LbHosting\WHMCS\ServerModule::metaData('dedicated');
    }

    function lbhdedicated_ConfigOptions()
    {
        return \LbHosting\WHMCS\ServerModule::configOptions('dedicated');
    }

    /** Dynamic plan dropdown (WHMCS "Loader" config option). */
    function lbhdedicated_LoaderPlans(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::loaderPlans('dedicated', $params);
    }

    function lbhdedicated_CreateAccount(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('dedicated', 'CreateAccount', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->create($x));
    }

    function lbhdedicated_SuspendAccount(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('dedicated', 'SuspendAccount', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->suspend($x));
    }

    function lbhdedicated_UnsuspendAccount(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('dedicated', 'UnsuspendAccount', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->unsuspend($x));
    }

    function lbhdedicated_TerminateAccount(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('dedicated', 'TerminateAccount', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->terminate($x));
    }

    /** Plan change and configurable-option (add-on) changes - WHMCS invoices the upgrade, then calls this. */
    function lbhdedicated_ChangePackage(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('dedicated', 'ChangePackage', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->changePackage($x));
    }

    /** Called by WHMCS when a renewal is paid (the addon's InvoicePaid hook is the fallback; renewals are de-duplicated). */
    function lbhdedicated_Renew(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('dedicated', 'Renew', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->renew($x, 'module'));
    }

    function lbhdedicated_AdminCustomButtonArray()
    {
        return \LbHosting\WHMCS\ServerModule::adminButtons('dedicated');
    }

    function lbhdedicated_AdminSync(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('dedicated', 'AdminSync', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->sync($x));
    }

    function lbhdedicated_AdminLinkOrder(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('dedicated', 'AdminLinkOrder', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->linkByDomain($x));
    }

    function lbhdedicated_AdminRenew(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('dedicated', 'AdminRenew', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->renew($x, 'admin', true));
    }

    function lbhdedicated_AdminReconcile(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('dedicated', 'AdminReconcile', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->reconcile($x));
    }

    function lbhdedicated_AdminDetails(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::adminDetails('dedicated', $params);
    }

    function lbhdedicated_AdminPanelTest(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::adminPanelTest('dedicated', $params);
    }

    function lbhdedicated_AdminServicesTabFields(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::adminServicesTabFields('dedicated', $params);
    }

    function lbhdedicated_AdminServicesTabFieldsSave(array $params)
    {
        \LbHosting\WHMCS\ServerModule::adminServicesTabFieldsSave('dedicated', $params);
    }

    /** Client actions (POST + CSRF token, run through clientarea.php?...&modop=custom&a=NAME). */
    function lbhdedicated_ClientAreaAllowedFunctions()
    {
        return \LbHosting\WHMCS\ClientAreaController::ACTIONS;
    }

    function lbhdedicated_ClientRefresh(array $params)
    {
        return \LbHosting\WHMCS\ClientAreaController::action('dedicated', 'ClientRefresh', $params);
    }

    function lbhdedicated_ClientRenew(array $params)
    {
        return \LbHosting\WHMCS\ClientAreaController::action('dedicated', 'ClientRenew', $params);
    }

    function lbhdedicated_ClientLogin(array $params)
    {
        return \LbHosting\WHMCS\ClientAreaController::action('dedicated', 'ClientLogin', $params);
    }

    function lbhdedicated_ClientPanel(array $params)
    {
        return \LbHosting\WHMCS\ClientAreaController::action('dedicated', 'ClientPanel', $params);
    }

    function lbhdedicated_ClientPassword(array $params)
    {
        return \LbHosting\WHMCS\ClientAreaController::action('dedicated', 'ClientPassword', $params);
    }

    function lbhdedicated_ClientShell(array $params)
    {
        return \LbHosting\WHMCS\ClientAreaController::action('dedicated', 'ClientShell', $params);
    }

    function lbhdedicated_ClientPower(array $params)
    {
        return \LbHosting\WHMCS\ClientAreaController::action('dedicated', 'ClientPower', $params);
    }

    function lbhdedicated_ClientNameservers(array $params)
    {
        return \LbHosting\WHMCS\ClientAreaController::action('dedicated', 'ClientNameservers', $params);
    }

    function lbhdedicated_AdminNameservers(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::adminNameservers('dedicated', $params);
    }

    function lbhdedicated_ClientArea(array $params)
    {
        return \LbHosting\WHMCS\ClientAreaController::render('dedicated', $params);
    }
} else {
    function lbhdedicated_MetaData()
    {
        return ['DisplayName' => 'Dedicated Server (LogicBoxes) - upload modules/addons/lbhosting', 'APIVersion' => '1.1', 'RequiresServer' => false];
    }

    function lbhdedicated_ConfigOptions()
    {
        return [];
    }
}
