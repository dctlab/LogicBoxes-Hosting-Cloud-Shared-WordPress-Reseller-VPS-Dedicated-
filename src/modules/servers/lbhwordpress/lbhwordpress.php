<?php
/**
 * WordPress Hosting (LogicBoxes / ResellerClub) - WHMCS provisioning module.
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

    function lbhwordpress_MetaData()
    {
        return \LbHosting\WHMCS\ServerModule::metaData('wordpress');
    }

    function lbhwordpress_ConfigOptions()
    {
        return \LbHosting\WHMCS\ServerModule::configOptions('wordpress');
    }

    /** Dynamic plan dropdown (WHMCS "Loader" config option). */
    function lbhwordpress_LoaderPlans(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::loaderPlans('wordpress', $params);
    }

    function lbhwordpress_CreateAccount(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('wordpress', 'CreateAccount', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->create($x));
    }

    function lbhwordpress_SuspendAccount(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('wordpress', 'SuspendAccount', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->suspend($x));
    }

    function lbhwordpress_UnsuspendAccount(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('wordpress', 'UnsuspendAccount', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->unsuspend($x));
    }

    function lbhwordpress_TerminateAccount(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('wordpress', 'TerminateAccount', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->terminate($x));
    }

    /** Plan change and configurable-option (add-on) changes - WHMCS invoices the upgrade, then calls this. */
    function lbhwordpress_ChangePackage(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('wordpress', 'ChangePackage', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->changePackage($x));
    }

    /** Called by WHMCS when a renewal is paid (the addon's InvoicePaid hook is the fallback; renewals are de-duplicated). */
    function lbhwordpress_Renew(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('wordpress', 'Renew', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->renew($x, 'module'));
    }


    function lbhwordpress_AdminCustomButtonArray()
    {
        return \LbHosting\WHMCS\ServerModule::adminButtons('wordpress');
    }

    function lbhwordpress_AdminSync(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('wordpress', 'AdminSync', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->sync($x));
    }

    function lbhwordpress_AdminLinkOrder(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('wordpress', 'AdminLinkOrder', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->linkByDomain($x));
    }

    function lbhwordpress_AdminRenew(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('wordpress', 'AdminRenew', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->renew($x, 'admin', true));
    }

    function lbhwordpress_AdminReconcile(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::run('wordpress', 'AdminReconcile', $params, static fn (\LbHosting\Service\Provisioner $p, array $x) => $p->reconcile($x));
    }

    function lbhwordpress_AdminDetails(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::adminDetails('wordpress', $params);
    }

    function lbhwordpress_AdminPanelTest(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::adminPanelTest('wordpress', $params);
    }

    function lbhwordpress_AdminServicesTabFields(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::adminServicesTabFields('wordpress', $params);
    }

    function lbhwordpress_AdminServicesTabFieldsSave(array $params)
    {
        \LbHosting\WHMCS\ServerModule::adminServicesTabFieldsSave('wordpress', $params);
    }

    /** Client actions (POST + CSRF token, run through clientarea.php?...&modop=custom&a=NAME). */
    function lbhwordpress_ClientAreaAllowedFunctions()
    {
        return \LbHosting\WHMCS\ClientAreaController::ACTIONS;
    }

    function lbhwordpress_ClientRefresh(array $params)
    {
        return \LbHosting\WHMCS\ClientAreaController::action('wordpress', 'ClientRefresh', $params);
    }

    function lbhwordpress_ClientRenew(array $params)
    {
        return \LbHosting\WHMCS\ClientAreaController::action('wordpress', 'ClientRenew', $params);
    }

    function lbhwordpress_ClientLogin(array $params)
    {
        return \LbHosting\WHMCS\ClientAreaController::action('wordpress', 'ClientLogin', $params);
    }

    function lbhwordpress_ClientPanel(array $params)
    {
        return \LbHosting\WHMCS\ClientAreaController::action('wordpress', 'ClientPanel', $params);
    }

    function lbhwordpress_ClientPassword(array $params)
    {
        return \LbHosting\WHMCS\ClientAreaController::action('wordpress', 'ClientPassword', $params);
    }

    function lbhwordpress_ClientShell(array $params)
    {
        return \LbHosting\WHMCS\ClientAreaController::action('wordpress', 'ClientShell', $params);
    }

    function lbhwordpress_ClientPower(array $params)
    {
        return \LbHosting\WHMCS\ClientAreaController::action('wordpress', 'ClientPower', $params);
    }

    function lbhwordpress_ClientNameservers(array $params)
    {
        return \LbHosting\WHMCS\ClientAreaController::action('wordpress', 'ClientNameservers', $params);
    }

    function lbhwordpress_AdminNameservers(array $params)
    {
        return \LbHosting\WHMCS\ServerModule::adminNameservers('wordpress', $params);
    }

    function lbhwordpress_ClientArea(array $params)
    {
        return \LbHosting\WHMCS\ClientAreaController::render('wordpress', $params);
    }
} else {
    function lbhwordpress_MetaData()
    {
        return ['DisplayName' => 'WordPress Hosting (LogicBoxes) - upload modules/addons/lbhosting', 'APIVersion' => '1.1', 'RequiresServer' => false];
    }

    function lbhwordpress_ConfigOptions()
    {
        return [];
    }
}
