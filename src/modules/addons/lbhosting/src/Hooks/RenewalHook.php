<?php

declare(strict_types=1);

namespace LbHosting\Hooks;

use Illuminate\Database\Capsule\Manager as Capsule;
use LbHosting\Catalog\ProductFamily;
use LbHosting\Service\Provisioner;
use LbHosting\Support\Logger;
use LbHosting\WHMCS\Database;

/**
 * InvoicePaid -> renew at LogicBoxes for every service of the eight modules on
 * the invoice, EXCEPT the initial order invoice (that one provisions via Create).
 * Provisioner::renew() is idempotent per billing period, so when WHMCS also
 * calls the module's Renew function only one renewal happens.
 */
final class RenewalHook
{
    public static function onInvoicePaid(int $invoiceId): void
    {
        if ($invoiceId <= 0) {
            return;
        }
        Database::ensure();
        if (Capsule::table('tblorders')->where('invoiceid', $invoiceId)->exists()) {
            return;
        }

        $services = Capsule::table('tblinvoiceitems')
            ->join('tblhosting', 'tblhosting.id', '=', 'tblinvoiceitems.relid')
            ->join('tblproducts', 'tblproducts.id', '=', 'tblhosting.packageid')
            ->where('tblinvoiceitems.invoiceid', $invoiceId)
            ->where('tblinvoiceitems.type', 'Hosting')
            ->whereIn('tblproducts.servertype', ProductFamily::modules())
            ->select(['tblhosting.id as serviceid', 'tblhosting.userid as userid', 'tblhosting.domain as domain', 'tblproducts.*'])
            ->get();

        foreach ($services as $s) {
            $serviceId = (int) $s->serviceid;
            $linked = Capsule::table('mod_lbh_services')->where('service_id', $serviceId)->whereNotNull('order_id')->first();
            if (!$linked) {
                continue;
            }
            if ($linked->created_at && strtotime((string) $linked->created_at) > time() - 20 * 3600) {
                continue;
            }
            $family = ProductFamily::forModule((string) $s->servertype);
            if ($family === null) {
                continue;
            }
            $params = ['serviceid' => $serviceId, 'userid' => (int) $s->userid, 'domain' => (string) $s->domain];
            for ($i = 1; $i <= 5; $i++) {
                $params['configoption' . $i] = $s->{'configoption' . $i} ?? '';
            }
            try {
                $result = (new Provisioner($family, null, null, \LbHosting\WHMCS\ServerModule::$transport))->renew($params, 'hook');
                Logger::log('hook', 'InvoicePaid #' . $invoiceId . ': provider renewal ' . $result, 'info', $serviceId);
            } catch (\Throwable $e) {
                Logger::log('hook', 'InvoicePaid #' . $invoiceId . ': provider renewal failed - ' . $e->getMessage() . '. Use "Renew at Provider" on the service after fixing.', 'error', $serviceId);
            }
        }
    }
}
