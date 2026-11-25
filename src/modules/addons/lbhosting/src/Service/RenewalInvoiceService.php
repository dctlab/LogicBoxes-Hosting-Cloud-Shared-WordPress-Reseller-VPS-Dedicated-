<?php

declare(strict_types=1);

namespace LbHosting\Service;

use Illuminate\Database\Capsule\Manager as Capsule;
use LbHosting\Support\Logger;
use LbHosting\Support\Settings;
use LbHosting\WHMCS\ProductConfig;

/**
 * "Renew now" from the client dashboard: raises the service's next renewal
 * invoice early. The invoice item is typed Hosting/relid=serviceid with the
 * service's current next due date - the same shape WHMCS's own invoice
 * generator writes - so paying it advances the due date through WHMCS's
 * normal renewal processing (PATTERN - confirm on staging), and the provider
 * renewal then happens via the module Renew call / InvoicePaid fallback.
 */
final class RenewalInvoiceService
{
    public const RESULT_EXISTING = 'existing';
    public const RESULT_CREATED = 'created';

    /** @return array{0:string,1:int} [result, invoiceId] */
    public function createOrFind(int $serviceId, int $clientId): array
    {
        $service = Capsule::table('tblhosting')->where('id', $serviceId)->where('userid', $clientId)->first();
        if (!$service) {
            throw new \RuntimeException('service_not_found');
        }
        if ($service->domainstatus !== 'Active') {
            throw new \RuntimeException('service_not_active');
        }
        $months = ProductConfig::monthsForCycle((string) $service->billingcycle);
        if ($months <= 0 || (float) $service->amount <= 0) {
            throw new \RuntimeException('not_renewable');
        }

        $existing = $this->unpaidInvoiceFor($serviceId, $clientId);
        if ($existing) {
            return [self::RESULT_EXISTING, $existing];
        }

        $productName = (string) Capsule::table('tblproducts')->where('id', $service->packageid)->value('name');
        $from = (string) $service->nextduedate;
        $to = date('Y-m-d', strtotime($from . ' +' . $months . ' months'));
        $dateFmt = static fn (string $d): string => function_exists('fromMySQLDate') ? (string) fromMySQLDate($d) : $d;
        $description = sprintf('%s - %s (%s - %s)', $productName, $service->domain, $dateFmt($from), $dateFmt(date('Y-m-d', strtotime($to . ' -1 day'))));

        $days = max(0, (int) Settings::get('renew_invoice_days'));
        $result = localAPI('CreateInvoice', [
            'userid' => $clientId,
            'status' => 'Unpaid',
            'sendinvoice' => true,
            'paymentmethod' => (string) ($service->paymentmethod ?? ''),
            'date' => date('Y-m-d'),
            // Never due before the service itself is due: an early invoice must not trigger overdue notices.
            'duedate' => max($from, date('Y-m-d', strtotime('+' . $days . ' days'))),
            'itemdescription1' => $description,
            'itemamount1' => (string) $service->amount,
            'itemtaxed1' => (bool) Capsule::table('tblproducts')->where('id', $service->packageid)->value('tax'),
        ]);
        if (($result['result'] ?? '') !== 'success' || empty($result['invoiceid'])) {
            Logger::log('renew_invoice', 'CreateInvoice failed: ' . ($result['message'] ?? 'unknown'), 'error', $serviceId);
            throw new \RuntimeException('invoice_failed');
        }
        $invoiceId = (int) $result['invoiceid'];

        // Retype the item so WHMCS treats payment as this service's renewal.
        Capsule::table('tblinvoiceitems')->where('invoiceid', $invoiceId)->update([
            'type' => 'Hosting',
            'relid' => $serviceId,
            'duedate' => $from,
        ]);

        Capsule::table('mod_lbh_renewals')->insert([
            'service_id' => $serviceId,
            'period_key' => 'inv:' . $invoiceId,
            'invoice_id' => $invoiceId,
            'source' => 'ondemand',
            'status' => 'invoiced',
            'months' => $months,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        Logger::log('renew_invoice', 'On-demand renewal invoice #' . $invoiceId . ' created by client', 'info', $serviceId);

        return [self::RESULT_CREATED, $invoiceId];
    }

    public function unpaidInvoiceFor(int $serviceId, int $clientId): ?int
    {
        $id = Capsule::table('tblinvoiceitems')
            ->join('tblinvoices', 'tblinvoices.id', '=', 'tblinvoiceitems.invoiceid')
            ->where('tblinvoiceitems.type', 'Hosting')
            ->where('tblinvoiceitems.relid', $serviceId)
            ->where('tblinvoices.userid', $clientId)
            ->whereIn('tblinvoices.status', ['Unpaid', 'Payment Pending'])
            ->orderBy('tblinvoices.id', 'desc')
            ->value('tblinvoices.id');

        return $id ? (int) $id : null;
    }
}
