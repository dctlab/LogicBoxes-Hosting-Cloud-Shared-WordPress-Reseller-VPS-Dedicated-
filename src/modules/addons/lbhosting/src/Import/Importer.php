<?php

declare(strict_types=1);

namespace LbHosting\Import;

use Illuminate\Database\Capsule\Manager as Capsule;
use LbHosting\Accounts\AccountRepository;
use LbHosting\Api\HttpTransport;
use LbHosting\Catalog\ProductFamily;
use LbHosting\Pricing\PricePlanner;
use LbHosting\Products\ProductMap;
use LbHosting\Service\AddonManager;
use LbHosting\Service\ServiceRepository;
use LbHosting\Support\Logger;
use LbHosting\WHMCS\OptionMap;
use LbHosting\WHMCS\ProductConfig;
use LbHosting\WHMCS\ServiceParams;

/**
 * Import Manager, step 2: act on staged orders.
 *
 *   link()   - attach the order to an existing WHMCS service (matched by domain).
 *              The service's current options are taken as what the order has.
 *   import() - create the WHMCS service: AddOrder (no invoice, no emails) +
 *              AcceptOrder (no module setup, no emails), then set the next due
 *              date to the LogicBoxes expiry, the status to Active / Suspended,
 *              the configurable options to what is on the order (generator-made
 *              products) and - for mapped products - the recurring amount from
 *              the price map.
 *   ignore() - hide from the list; a later scan never un-ignores it.
 *
 * Nothing is ever sent to LogicBoxes: importing only records what exists. The
 * add-on baseline is written as "already applied", so the first Create /
 * ChangePackage after an import orders or removes nothing by itself.
 */
final class Importer
{
    public const CYCLES = ['Monthly', 'Quarterly', 'Semi-Annually', 'Annually', 'Biennially', 'Triennially'];

    private ?HttpTransport $transport;

    public function __construct(?HttpTransport $transport = null)
    {
        $this->transport = $transport;
    }

    /**
     * @param bool $confirmed an admin confirmed this row by hand; required when the
     *                        service was suggested by name only (match_by "domain")
     */
    public function link(int $importId, ?int $serviceId = null, bool $confirmed = false): int
    {
        $o = $this->row($importId);
        if (!$confirmed && !$serviceId && ($o->match_by ?? null) !== 'email') {
            throw new \InvalidArgumentException('The suggested service only has the same name; its owner does not match the LogicBoxes customer. Check it, then use Link on the row.');
        }
        if ($o->state === 'imported') {
            throw new \RuntimeException('Order ' . $o->order_id . ' is already imported.');
        }
        $serviceId = $serviceId ?: (int) ($o->match_service_id ?? 0);
        if ($serviceId <= 0 || !Capsule::table('tblhosting')->where('id', $serviceId)->exists()) {
            throw new \InvalidArgumentException('No WHMCS service to link to.');
        }
        $family = (string) $o->family;
        $repo = new ServiceRepository();
        $svcModule = (string) Capsule::table('tblhosting')->join('tblproducts', 'tblproducts.id', '=', 'tblhosting.packageid')->where('tblhosting.id', $serviceId)->value('tblproducts.servertype');
        if ($svcModule !== ProductFamily::module($family)) {
            throw new \InvalidArgumentException('Service #' . $serviceId . ' does not use the ' . ProductFamily::label($family) . ' module.');
        }
        if (($other = $repo->orderLinkedElsewhere((int) $o->order_id, $serviceId, (int) $o->account_id)) !== null) {
            throw new \RuntimeException('Order ' . $o->order_id . ' is already linked to service #' . $other . '.');
        }
        $current = $repo->get($serviceId);
        if ($current && !empty($current->order_id) && (int) $current->order_id !== (int) $o->order_id) {
            throw new \RuntimeException('Service #' . $serviceId . ' is already linked to order ' . $current->order_id . '.');
        }
        $this->attach($o, $serviceId);
        $this->baseline($family, $serviceId);
        $this->mark($importId, 'linked', null, $serviceId);
        Logger::log('import', 'Linked LogicBoxes order ' . $o->order_id . ' (' . $o->domain . ')', 'info', $serviceId);

        return $serviceId;
    }

    /**
     * @param array{product_id?:int, billingcycle:string, paymentmethod:string, create_client?:bool, set_amount?:bool} $opts
     */
    public function import(int $importId, array $opts): int
    {
        $o = $this->row($importId);
        if (in_array($o->state, ['linked', 'imported'], true)) {
            throw new \RuntimeException('Order ' . $o->order_id . ' is already ' . $o->state . '.');
        }
        try {
            $cycle = (string) $opts['billingcycle'];
            if (!in_array($cycle, self::CYCLES, true)) {
                throw new \InvalidArgumentException('Choose a billing cycle.');
            }
            $gateway = (string) $opts['paymentmethod'];
            if ($gateway === '' || !Capsule::table('tblpaymentgateways')->where('gateway', $gateway)->exists()) {
                throw new \InvalidArgumentException('Choose an active payment method.');
            }
            $family = (string) $o->family;
            $lbStatus = strtolower((string) ($o->lb_status ?? ''));
            if (!in_array($lbStatus, ['active', 'suspended'], true)) {
                throw new \InvalidArgumentException('Only Active and Suspended orders can be imported (this one is "' . ($o->lb_status ?? 'unknown') . '").');
            }
            if (empty($o->expires_at)) {
                // Without it WHMCS would treat the service as due today and invoice it on the next cron.
                throw new \InvalidArgumentException('LogicBoxes did not report an expiry date for this order, so its next due date is unknown. Create the service by hand with the right due date, then Link it.');
            }
            if (trim((string) $o->domain) === '') {
                throw new \InvalidArgumentException('LogicBoxes did not report a domain / hostname for this order.');
            }
            // The product mapped to the order's plan always wins; the chosen product is only for unmapped plans.
            $mapped = !empty($o->plan_id) ? (new ProductMap())->forPlan((int) $o->account_id, (string) $o->product_key, (int) $o->plan_id) : null;
            $productId = (int) ($mapped ? $mapped->product_id : ($opts['product_id'] ?? 0));
            $product = $productId ? Capsule::table('tblproducts')->where('id', $productId)->where('servertype', ProductFamily::module($family))->first() : null;
            if (!$product) {
                throw new \InvalidArgumentException('No ' . ProductFamily::label($family) . ' product for plan ' . (($o->plan_id ?? null) ?: '?') . ' - create one with the Product Generator, or choose a ' . ProductFamily::label($family) . ' product.');
            }
            if (strtolower((string) ($product->autosetup ?? '')) === 'order') {
                // WHMCS would run the module's Create as soon as the order is placed - before this order is linked.
                throw new \InvalidArgumentException('Product #' . $productId . ' is set to "Automatically setup the product as soon as an order is placed". Change it to "on first payment" or "off" before importing, or WHMCS would try to provision a new order.');
            }
            // Order ids are unique per LogicBoxes platform, so check across API accounts too (the same reseller added twice).
            if (Capsule::table('mod_lbh_services')->where('order_id', (int) $o->order_id)->where('product_key', (string) $o->product_key)->exists()) {
                throw new \RuntimeException('This order is already linked to a service.');
            }

            $clientId = (int) ($o->match_client_id ?? 0);
            if ($clientId <= 0 && !empty($o->customer_email)) {
                // The client may have been added (or created by an earlier import) since the scan.
                $clientId = (int) Capsule::table('tblclients')->where('email', (string) $o->customer_email)->value('id');
            }
            if ($clientId <= 0) {
                if (empty($opts['create_client'])) {
                    throw new \InvalidArgumentException('No WHMCS client matches ' . (!empty($o->customer_email) ? $o->customer_email : 'this customer') . ' - tick "create missing clients" or add the client first.');
                }
                $clientId = $this->createClient($o);
            }

            $res = localAPI('AddOrder', [
                'clientid' => $clientId,
                'paymentmethod' => $gateway,
                'pid' => [$productId],
                'domain' => [$o->domain],
                'billingcycle' => [strtolower(str_replace('-', '', $cycle))],
                'noinvoice' => true,
                'noinvoiceemail' => true,
                'noemail' => true,
            ]);
            if (!is_array($res) || ($res['result'] ?? '') !== 'success') {
                throw new \RuntimeException('AddOrder failed: ' . (is_array($res) ? ($res['message'] ?? 'unknown') : 'no response'));
            }
            $serviceId = (int) explode(',', (string) ($res['serviceids'] ?? $res['productids'] ?? ''))[0];
            if ($serviceId <= 0) {
                throw new \RuntimeException('AddOrder did not return a service ID.');
            }
            // Link first: whatever happens next, this order can never be imported twice.
            $this->attach($o, $serviceId);
            $this->mark($importId, 'imported', null, $serviceId, $clientId);

            $acc = localAPI('AcceptOrder', ['orderid' => (int) $res['orderid'], 'autosetup' => false, 'sendemail' => false]);
            if (!is_array($acc) || ($acc['result'] ?? '') !== 'success') {
                Logger::log('import', 'AcceptOrder for imported order ' . $res['orderid'] . ' failed: ' . (is_array($acc) ? ($acc['message'] ?? 'unknown') : 'no response') . ' - service left Pending', 'warning', $serviceId);
            }

            $accepted = is_array($acc) && ($acc['result'] ?? '') === 'success';
            $update = ['nextduedate' => substr((string) $o->expires_at, 0, 10)];
            $update['nextinvoicedate'] = $update['nextduedate'];
            $update['domainstatus'] = !$accepted ? 'Pending' : ($lbStatus === 'suspended' ? 'Suspended' : 'Active');
            if ($update['domainstatus'] === 'Suspended') {
                $update['suspendreason'] = 'Suspended at LogicBoxes (imported)';
            }
            Capsule::table('tblhosting')->where('id', $serviceId)->update($update);

            // Configurable options = what is on the order (generator-made products only).
            $map = (new ProductMap())->forProduct($productId);
            if ($map) {
                $extras = json_decode((string) ($o->extras_json ?? ''), true) ?: [];
                foreach (ProviderExtras::selections($family, (int) $map->id, $extras) as $configId => $sel) {
                    $q = Capsule::table('tblhostingconfigoptions')->where('relid', $serviceId)->where('configid', $configId);
                    if ($q->exists()) {
                        Capsule::table('tblhostingconfigoptions')->where('relid', $serviceId)->where('configid', $configId)->update($sel);
                    } else {
                        Capsule::table('tblhostingconfigoptions')->insert(['relid' => $serviceId, 'configid' => $configId] + $sel);
                    }
                }
            }
            $this->baseline($family, $serviceId);

            if (!empty($opts['set_amount']) && $map) {
                $currency = (int) Capsule::table('tblclients')->where('id', $clientId)->value('currency');
                $amount = (new PricePlanner($map))->serviceAmount($serviceId, $cycle, $currency);
                if ($amount !== null) {
                    Capsule::table('tblhosting')->where('id', $serviceId)->update(['amount' => $amount]);
                    Capsule::table('mod_lbh_services')->where('service_id', $serviceId)->update(['priced_amount' => $amount]);
                }
            }
            Logger::log('import', sprintf('Imported LogicBoxes order %d (%s) as service #%d for client #%d', $o->order_id, $o->domain, $serviceId, $clientId), 'info', $serviceId);

            return $serviceId;
        } catch (\Throwable $e) {
            if (Capsule::table('mod_lbh_import_orders')->where('id', $importId)->value('state') !== 'imported') {
                $this->mark($importId, 'error', $e->getMessage());
            } else {
                Capsule::table('mod_lbh_import_orders')->where('id', $importId)->update(['message' => substr('Imported, but: ' . $e->getMessage(), 0, 1000)]);
            }
            throw $e;
        }
    }

    public function ignore(int $importId): void
    {
        $o = $this->row($importId);
        if (in_array($o->state, ['new', 'error'], true)) {
            $this->mark($importId, 'ignored', null);
        }
    }

    public function restore(int $importId): void
    {
        $o = $this->row($importId);
        if ($o->state === 'ignored' || $o->state === 'error') {
            $this->mark($importId, 'new', null);
        }
    }

    private function attach(object $o, int $serviceId): void
    {
        $repo = new ServiceRepository();
        $isNew = $repo->get($serviceId) === null;
        $repo->upsert($serviceId, [
            'account_id' => (int) $o->account_id,
            'family' => (string) $o->family,
            'product_key' => (string) $o->product_key,
            'order_id' => (int) $o->order_id,
            'customer_id' => $o->customer_id ?? null,
            'plan_id' => $o->plan_id ?? null,
            'plan_name' => $o->plan_name ?? null,
            'provider_status' => $o->lb_status,
            'expires_at' => $o->expires_at ?? null,
            'last_synced_at' => null, // refresh on the first dashboard view
        ]);
        if ($isNew) {
            // An existing order, not one placed minutes ago: the "created < 20 hours ago" renewal guard must not apply.
            Capsule::table('mod_lbh_services')->where('service_id', $serviceId)->update(['created_at' => date('Y-m-d H:i:s', time() - 2 * 86400)]);
        }
    }

    /** Record the service's options as already applied at LogicBoxes (no API call). */
    private function baseline(string $family, int $serviceId): void
    {
        try {
            $params = ServiceParams::build($serviceId);
            AddonManager::recordDesired($serviceId, (new OptionMap($params))->desired(new ProductConfig($family, $params)));
        } catch (\Throwable $e) {
            Logger::log('import', 'Add-on baseline could not be recorded: ' . $e->getMessage() . ' - use "Reconcile Add-ons" on the service after checking its options.', 'warning', $serviceId);
        }
    }

    private function createClient(object $o): int
    {
        $acc = (new AccountRepository())->find((int) $o->account_id);
        if (!$acc || empty($o->customer_id)) {
            throw new \RuntimeException('Cannot create client: LogicBoxes customer unknown.');
        }
        $d = (array) $acc->client($this->transport)->get('customers/details-by-id.json', ['customer-id' => (int) $o->customer_id], 'CustomerById');
        $email = strtolower((string) ($d['username'] ?? $d['useremail'] ?? $o->customer_email ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('LogicBoxes customer has no valid email.');
        }
        if ($existing = Capsule::table('tblclients')->where('email', $email)->value('id')) {
            return (int) $existing;
        }
        $name = trim((string) ($d['name'] ?? $o->customer_name ?? ''));
        $parts = preg_split('/\s+/', $name, 2) ?: [];
        $res = localAPI('AddClient', [
            'firstname' => ($parts[0] ?? '') !== '' ? $parts[0] : 'Customer',
            'lastname' => $parts[1] ?? '-',
            'companyname' => (string) ($d['company'] ?? ''),
            'email' => $email,
            'address1' => (string) ($d['address1'] ?? 'N/A'),
            'city' => (string) ($d['city'] ?? 'N/A'),
            'state' => (string) ($d['state'] ?? 'N/A'),
            'postcode' => (string) ($d['zip'] ?? '000000'),
            'country' => strtoupper((string) ($d['country'] ?? 'US')),
            'phonenumber' => trim('+' . ($d['telnocc'] ?? '') . '.' . ($d['telno'] ?? ''), '+.'),
            'password2' => bin2hex(random_bytes(12)),
            'noemail' => true,
            'skipvalidation' => true,
        ]);
        if (!is_array($res) || ($res['result'] ?? '') !== 'success' || empty($res['clientid'])) {
            throw new \RuntimeException('AddClient failed: ' . (is_array($res) ? ($res['message'] ?? 'unknown') : 'no response'));
        }
        Logger::log('import', 'Created WHMCS client #' . $res['clientid'] . ' for ' . $email . ' (import)');

        return (int) $res['clientid'];
    }

    private function row(int $id): object
    {
        $o = Capsule::table('mod_lbh_import_orders')->where('id', $id)->first();
        if (!$o) {
            throw new \InvalidArgumentException('Import row not found.');
        }

        return $o;
    }

    private function mark(int $id, string $state, ?string $message, ?int $serviceId = null, ?int $clientId = null): void
    {
        $upd = ['state' => $state, 'message' => $message === null ? null : substr($message, 0, 1000)];
        if ($serviceId) {
            $upd['match_service_id'] = $serviceId;
        }
        if ($clientId) {
            $upd['match_client_id'] = $clientId;
        }
        Capsule::table('mod_lbh_import_orders')->where('id', $id)->update($upd);
    }
}
