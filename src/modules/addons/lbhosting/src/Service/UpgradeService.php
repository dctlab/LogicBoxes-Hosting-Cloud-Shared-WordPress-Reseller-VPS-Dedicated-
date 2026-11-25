<?php

declare(strict_types=1);

namespace LbHosting\Service;

use Illuminate\Database\Capsule\Manager as Capsule;
use LbHosting\Accounts\AccountRepository;
use LbHosting\Api\HttpTransport;
use LbHosting\Catalog\ProductFamily;
use LbHosting\Pricing\PriceCalculator;
use LbHosting\Pricing\PriceCatalog;
use LbHosting\Pricing\PricePlanner;
use LbHosting\Products\ProductMap;
use LbHosting\Support\Logger;
use LbHosting\Support\Settings;
use LbHosting\WHMCS\ServiceParams;

/**
 * "Upgrade/Downgrade ... with correct Invoicing as per the Providers standard".
 *
 * WHMCS's own upgrade pages prorate from WHMCS prices, which does not match
 * what LogicBoxes charges. This flow prices a change the provider's way:
 *
 *   plan change   LogicBoxes' own figure from modify-pricing.json (upgradecost
 *                 for the remaining months); VPS has no such call, so the
 *                 catalogue difference x remaining months is used.
 *   add-on change monthly add-on price x units added x remaining months.
 *                 Removing units costs nothing and gives no refund (LogicBoxes
 *                 gives none either).
 *
 * quote() -> confirm() raises a WHMCS invoice -> the InvoicePaid hook calls
 * apply(), which changes the order at LogicBoxes, moves the WHMCS service to
 * the new product / options and sets the new recurring amount. A change that
 * costs nothing is applied at once. One open change per service; a failed one
 * stays in the admin queue and can be retried - it is never applied twice.
 */
final class UpgradeService
{
    public const OPEN = ['invoiced', 'paid', 'applying', 'failed'];

    /** An "applying" row older than this was interrupted (PHP died mid-change) and may be retried by an admin. */
    public const STALE_SECONDS = 600;

    private ?HttpTransport $transport;

    private ProductMap $maps;

    public function __construct(?HttpTransport $transport = null)
    {
        $this->transport = $transport;
        $this->maps = new ProductMap();
    }

    public static function enabled(): bool
    {
        return Settings::get('upgrade_mode') !== 'whmcs';
    }

    // ------------------------------------------------------------- choices

    /**
     * What the client may change on this service.
     *
     * @return array{plans: array<int,array<string,mixed>>, options: array<int,array<string,mixed>>, pending: ?object}
     */
    public function choices(int $serviceId): array
    {
        $out = ['plans' => [], 'options' => [], 'pending' => $this->pending($serviceId)];
        [$service, $map] = $this->context($serviceId);
        if (!$map) {
            return $out;
        }
        $family = (string) $map->family;
        $currency = $this->clientCurrency((int) $service->userid);
        $cycle = strtolower(str_replace(['-', ' '], '', (string) $service->billingcycle));

        if (ProductFamily::can($family, 'plan_change')) {
            foreach ($this->maps->all($family) as $m) {
                if ((int) $m->id === (int) $map->id || (int) $m->account_id !== (int) $map->account_id || $m->product_key !== $map->product_key) {
                    continue;
                }
                $p = Capsule::table('tblproducts')->where('id', $m->product_id)->first();
                if (!$p || !empty($p->hidden) || !empty($p->retired)) {
                    continue;
                }
                if (!ProductFamily::can($family, 'downgrade')) {
                    // Both plans from the SAME price list, or a cost price would be compared with a customer price.
                    $list = PriceCatalog::source($m);
                    $ownRank = $this->rank($map, $list);
                    $r = $this->rank($m, $list);
                    if ($ownRank === null || $r === null || $r <= $ownRank) {
                        continue; // LogicBoxes refuses downgrades for this product
                    }
                }
                $price = (new PricePlanner($m))->targets()['product'][$currency][$cycle] ?? -1.0;
                if ($price < 0) {
                    continue; // the new plan is not sold on this billing cycle
                }
                $out['plans'][] = ['product_id' => (int) $m->product_id, 'name' => (string) $p->name, 'price' => (float) $price];
            }
        }

        $selected = $this->selections($serviceId);
        foreach ($this->managedOptions((int) $map->id) as $configId => $opt) {
            if ($opt['logical'] === 'os') {
                continue; // an OS change is a reinstall, not an upgrade
            }
            $sel = $selected[$configId] ?? null;
            $out['options'][] = [
                'config_id' => $configId,
                'logical' => $opt['logical'],
                'name' => $opt['name'],
                'type' => $opt['type'],
                'max' => $opt['max'],
                'current' => $opt['type'] === 1 ? (int) ($sel->optionid ?? ($opt['subs'][0]['sub_id'] ?? 0)) : (int) ($sel->qty ?? 0),
                'subs' => $opt['subs'],
            ];
        }

        return $out;
    }

    public function pending(int $serviceId): ?object
    {
        return Capsule::table('mod_lbh_upgrades')->where('service_id', $serviceId)->whereIn('status', self::OPEN)->orderBy('id', 'desc')->first();
    }

    // --------------------------------------------------------------- quote

    /**
     * @param array<string,mixed> $request ['kind' => 'plan', 'product_id' => int] or ['kind' => 'options', 'options' => [configId => value]]
     * @return array<string,mixed> kind, payload, lines[], months, cost, amount, currency_id, currency_code, recurring
     */
    public function quote(int $serviceId, array $request): array
    {
        if (!self::enabled()) {
            throw new UpgradeException('Changes are not available for this service.');
        }
        [$service, $map] = $this->context($serviceId);
        if (!$map) {
            throw new UpgradeException('Changes are not available for this service.');
        }
        if ((string) $service->domainstatus !== 'Active') {
            throw new UpgradeException('Only active services can be changed.');
        }
        if ($this->pending($serviceId)) {
            throw new UpgradeException('A change for this service is already in progress.');
        }
        $family = (string) $map->family;
        $params = ServiceParams::build($serviceId);
        $prov = new Provisioner($family, null, null, $this->transport);
        $prov->sync($params); // fresh expiry / plan from LogicBoxes
        $row = (new ServiceRepository())->get($serviceId);
        $months = Provisioner::remainingMonths(!empty($row->expires_at) ? (string) $row->expires_at : null) ?? max(1, (int) ($row->tenure_months ?? 1));
        $currency = $this->clientCurrency((int) $service->userid);
        $rates = $this->rates((int) $map->source_currency_id, $currency);
        $kind = ($request['kind'] ?? '') === 'plan' ? 'plan' : 'options';

        if ($kind === 'plan') {
            $newProductId = (int) ($request['product_id'] ?? 0);
            $allowed = array_column($this->choices($serviceId)['plans'], null, 'product_id');
            if (!isset($allowed[$newProductId])) {
                throw new UpgradeException('That plan is not available for this service.');
            }
            $newMap = $this->maps->forProduct($newProductId);
            $cost = null;
            if ($family !== ProductFamily::VPS) {
                try {
                    [, $api] = $prov->linked($params);
                    $price = $api->modifyPricing($family, (string) $row->product_key, (int) $row->order_id, (int) $newMap->plan_id, $months, $serviceId);
                    if (isset($price['upgradecost']) && is_numeric($price['upgradecost'])) {
                        $cost = (float) $price['upgradecost'];
                        if (isset($price['remainingmonths']) && (int) $price['remainingmonths'] > 0) {
                            $months = (int) $price['remainingmonths'];
                        }
                    }
                } catch (\Throwable $e) {
                    Logger::log('upgrade', 'modify-pricing failed, using the catalogue difference: ' . $e->getMessage(), 'warning', $serviceId);
                }
            }
            if ($cost === null) {
                $list = PriceCatalog::source($newMap); // one price list for both plans
                $old = $this->monthly($map, $list);
                $new = $this->monthly($newMap, $list);
                if ($old === null || $new === null) {
                    throw new UpgradeException('The price of this change is not available right now. Please contact support.');
                }
                $cost = ($new - $old) * $months;
            }
            $cost = max(0.0, $cost);
            $selections = $this->migratedSelections($serviceId, (int) $map->id, (int) $newMap->id);
            $recurring = $this->recurring($newMap, $selections, (string) $service->billingcycle, $currency);
            $lines = [sprintf('%s -> %s', (string) Capsule::table('tblproducts')->where('id', $service->packageid)->value('name'), $allowed[$newProductId]['name'])];
            $payload = ['new_product_id' => $newProductId];
            $priceMap = $newMap;
        } else {
            [$changes, $cost, $lines] = $this->optionChanges($serviceId, $map, (array) ($request['options'] ?? []), $months);
            if (!$changes) {
                throw new UpgradeException('Nothing was changed.');
            }
            $selections = $this->currentSelections($serviceId, (int) $map->id);
            foreach ($changes as $configId => $c) {
                $selections[$configId] = $c['new'];
            }
            $recurring = $this->recurring($map, $selections, (string) $service->billingcycle, $currency);
            $payload = ['options' => array_map(static fn ($c) => $c['new'], $changes)];
            $priceMap = $map;
        }

        $amount = $cost <= 0 ? 0.0 : PriceCalculator::sellPrice($cost, (float) $priceMap->markup_pct, $rates[0], $rates[1], (string) $priceMap->rounding);

        return [
            'kind' => $kind,
            'payload' => $payload,
            'lines' => $lines,
            'months' => $months,
            'cost' => round($cost, 4),
            'amount' => $amount,
            'currency_id' => $currency,
            'currency_code' => (string) Capsule::table('tblcurrencies')->where('id', $currency)->value('code'),
            'recurring' => $recurring,
        ];
    }

    // ------------------------------------------------------------- confirm

    /**
     * Re-quotes (prices may have moved since the preview) and either raises the
     * invoice or, when nothing is due, applies the change at once.
     *
     * @param array<string,mixed> $request
     * @return array{upgrade_id:int, invoice_id:?int, applied:bool, amount:float}
     */
    public function confirm(int $serviceId, array $request): array
    {
        $q = $this->quote($serviceId, $request);
        [$service] = $this->context($serviceId);
        $now = date('Y-m-d H:i:s');
        $id = (int) Capsule::table('mod_lbh_upgrades')->insertGetId([
            'service_id' => $serviceId,
            'client_id' => (int) $service->userid,
            'kind' => $q['kind'],
            'payload' => json_encode($q['payload']),
            'summary' => mb_substr(implode('; ', $q['lines']), 0, 255),
            'months' => $q['months'],
            'provider_cost' => $q['cost'],
            'amount' => $q['amount'],
            'currency_id' => $q['currency_id'],
            'status' => $q['amount'] > 0 ? 'invoiced' : 'paid',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($q['amount'] <= 0) {
            $this->apply($id);
            $status = (string) Capsule::table('mod_lbh_upgrades')->where('id', $id)->value('status');

            return ['upgrade_id' => $id, 'invoice_id' => null, 'applied' => $status === 'applied', 'amount' => 0.0];
        }

        $product = Capsule::table('tblproducts')->where('id', $service->packageid)->first();
        $desc = sprintf('%s - %s (%s): %s [%d month(s) remaining]', (string) ($product->name ?? 'Service'), ProductFamily::label(ServiceParams::family($serviceId)), (string) $service->domain, implode('; ', $q['lines']), $q['months']);
        $res = localAPI('CreateInvoice', [
            'userid' => (int) $service->userid,
            'status' => 'Unpaid',
            'sendinvoice' => '1',
            'paymentmethod' => (string) ($service->paymentmethod ?? ''),
            'date' => date('Y-m-d'),
            'duedate' => date('Y-m-d'),
            'itemdescription1' => mb_substr($desc, 0, 500),
            'itemamount1' => $q['amount'],
            'itemtaxed1' => !empty($product->tax) ? 1 : 0,
        ]);
        $invoiceId = is_array($res) && ($res['result'] ?? '') === 'success' ? (int) ($res['invoiceid'] ?? 0) : 0;
        if ($invoiceId <= 0) {
            Capsule::table('mod_lbh_upgrades')->where('id', $id)->update(['status' => 'cancelled', 'error' => 'CreateInvoice failed: ' . (is_array($res) ? ($res['message'] ?? 'unknown') : 'no response'), 'updated_at' => $now]);
            throw new \RuntimeException('WHMCS could not create the invoice: ' . (is_array($res) ? ($res['message'] ?? 'unknown error') : 'no response'));
        }
        Capsule::table('mod_lbh_upgrades')->where('id', $id)->update(['invoice_id' => $invoiceId]);
        Logger::log('upgrade', sprintf('Change #%d invoiced (invoice #%d, %.2f %s): %s', $id, $invoiceId, $q['amount'], $q['currency_code'], implode('; ', $q['lines'])), 'info', $serviceId);

        return ['upgrade_id' => $id, 'invoice_id' => $invoiceId, 'applied' => false, 'amount' => $q['amount']];
    }

    // ------------------------------------------------------ invoice hooks

    public function onInvoicePaid(int $invoiceId): void
    {
        foreach (Capsule::table('mod_lbh_upgrades')->where('invoice_id', $invoiceId)->where('status', 'invoiced')->get() as $u) {
            Capsule::table('mod_lbh_upgrades')->where('id', $u->id)->where('status', 'invoiced')->update(['status' => 'paid', 'updated_at' => date('Y-m-d H:i:s')]);
            $this->apply((int) $u->id);
        }
    }

    public function onInvoiceCancelled(int $invoiceId): void
    {
        $n = Capsule::table('mod_lbh_upgrades')->where('invoice_id', $invoiceId)->where('status', 'invoiced')
            ->update(['status' => 'cancelled', 'error' => 'Invoice cancelled', 'updated_at' => date('Y-m-d H:i:s')]);
        if ($n) {
            Logger::log('upgrade', 'Invoice #' . $invoiceId . ' cancelled - pending change dropped');
        }
    }

    public function cancel(int $upgradeId): void
    {
        $n = Capsule::table('mod_lbh_upgrades')->where('id', $upgradeId)->whereIn('status', ['invoiced', 'failed'])
            ->update(['status' => 'cancelled', 'updated_at' => date('Y-m-d H:i:s')]);
        if (!$n) {
            throw new \RuntimeException('Only invoiced or failed changes can be cancelled.');
        }
    }

    // --------------------------------------------------------------- apply

    /**
     * Apply a paid change. Claims the row first (paid/failed -> applying), so
     * the InvoicePaid hook, a retry and a double click can never apply twice.
     * Never throws: a failure is recorded on the row and shown in the queue.
     *
     * $allowStale (admin Retry only) also picks up a row left in "applying" by
     * an interrupted request. Re-running is safe: the plan change is a modify
     * to the same plan and add-ons are reconciled against what was recorded.
     */
    public function apply(int $upgradeId, bool $allowStale = false): void
    {
        $now = date('Y-m-d H:i:s');
        $claimed = Capsule::table('mod_lbh_upgrades')->where('id', $upgradeId)->whereIn('status', ['paid', 'failed'])->update(['status' => 'applying', 'updated_at' => $now]);
        if (!$claimed && $allowStale) {
            $claimed = Capsule::table('mod_lbh_upgrades')->where('id', $upgradeId)->where('status', 'applying')
                ->where('updated_at', '<', date('Y-m-d H:i:s', time() - self::STALE_SECONDS))->update(['updated_at' => $now]);
        }
        if (!$claimed) {
            return;
        }
        $u = Capsule::table('mod_lbh_upgrades')->where('id', $upgradeId)->first();
        $serviceId = (int) $u->service_id;
        try {
            $payload = json_decode((string) $u->payload, true) ?: [];
            [$service, $map] = $this->context($serviceId);
            if (!$map) {
                throw new \RuntimeException('The service\'s product is no longer mapped.');
            }
            $family = (string) $map->family;

            if ($u->kind === 'plan') {
                $newProductId = (int) ($payload['new_product_id'] ?? 0);
                $newMap = $this->maps->forProduct($newProductId);
                if ((int) $service->packageid !== $newProductId) {
                    if (!$newMap) {
                        throw new \RuntimeException('The new product is no longer mapped.');
                    }
                    // Move the WHMCS service first: the provisioning code reads the plan from the product.
                    $selections = $this->migratedSelections($serviceId, (int) $map->id, (int) $newMap->id);
                    Capsule::table('tblhosting')->where('id', $serviceId)->update(['packageid' => $newProductId]);
                    $this->writeSelections($serviceId, (int) $newMap->id, $selections, true);
                }
                (new Provisioner($family, null, null, $this->transport))->changePackage(ServiceParams::build($serviceId));
                $finalMap = $newMap ?: $map;
            } else {
                $this->writeSelections($serviceId, (int) $map->id, (array) ($payload['options'] ?? []), false);
                (new Provisioner($family, null, null, $this->transport))->reconcile(ServiceParams::build($serviceId));
                $finalMap = $map;
            }

            $this->setRecurring($serviceId, $finalMap);
            Capsule::table('mod_lbh_upgrades')->where('id', $upgradeId)->update(['status' => 'applied', 'error' => null, 'updated_at' => date('Y-m-d H:i:s')]);
            Logger::log('upgrade', 'Change #' . $upgradeId . ' applied: ' . $u->summary, 'info', $serviceId);
        } catch (\Throwable $e) {
            Capsule::table('mod_lbh_upgrades')->where('id', $upgradeId)->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000), 'updated_at' => date('Y-m-d H:i:s')]);
            Logger::log('upgrade', 'Change #' . $upgradeId . ' is PAID but could not be applied: ' . $e->getMessage() . ' - fix the cause, then Retry under Addons > LogicBoxes Hosting > Upgrades.', 'error', $serviceId);
        }
    }

    /**
     * In provider mode WHMCS's own (prorata) upgrade pages must not compete
     * with this flow: remove upgrade paths between mapped products and switch
     * off configurable-option upgrades on them.
     */
    public static function enforceMode(): int
    {
        if (!self::enabled()) {
            return 0;
        }
        $ids = array_map(static fn ($m) => (int) $m->product_id, (new ProductMap())->all());
        if (!$ids) {
            return 0;
        }
        $n = 0;
        try {
            if (Capsule::schema()->hasTable('tblproduct_upgrade_products')) {
                $n = (int) Capsule::table('tblproduct_upgrade_products')->whereIn('product_id', $ids)->whereIn('upgrade_product_id', $ids)->delete();
            }
            if (Capsule::schema()->hasColumn('tblproducts', 'configoptionsupgrade')) {
                Capsule::table('tblproducts')->whereIn('id', $ids)->update(['configoptionsupgrade' => 0]);
            }
        } catch (\Throwable $e) {
        }

        return $n;
    }

    // ------------------------------------------------------------- helpers

    /** @return array{0:object, 1:?object} tblhosting row, product map */
    private function context(int $serviceId): array
    {
        $service = Capsule::table('tblhosting')->where('id', $serviceId)->first();
        if (!$service) {
            throw new UpgradeException('Service not found.');
        }

        return [$service, $this->maps->forProduct((int) $service->packageid)];
    }

    private function clientCurrency(int $clientId): int
    {
        $c = (int) Capsule::table('tblclients')->where('id', $clientId)->value('currency');

        return $c > 0 ? $c : (int) Capsule::table('tblcurrencies')->orderBy('id')->value('id');
    }

    /** @return array{0:float,1:float} source rate, client rate */
    private function rates(int $sourceCurrencyId, int $clientCurrencyId): array
    {
        $src = (float) (Capsule::table('tblcurrencies')->where('id', $sourceCurrencyId)->value('rate') ?? 1.0);
        $dst = (float) (Capsule::table('tblcurrencies')->where('id', $clientCurrencyId)->value('rate') ?? 1.0);

        return [$src > 0 ? $src : 1.0, $dst > 0 ? $dst : 1.0];
    }

    /** Annual LogicBoxes price of a mapped plan, for ordering plans (upgrade vs downgrade). */
    private function rank(object $map, ?string $kind = null): ?float
    {
        $m = $this->monthly($map, $kind);

        return $m === null ? null : $m * 12;
    }

    /** LogicBoxes price per month of a mapped plan (12-month tenure preferred). */
    private function monthly(object $map, ?string $kind = null): ?float
    {
        $calc = PriceCatalog::calculator((int) $map->account_id, (string) $map->product_key, (int) $map->plan_id, $kind ?? PriceCatalog::source($map));
        $tenures = $calc->tenures();
        if (!$tenures) {
            return null;
        }
        $t = in_array(12, $tenures, true) ? 12 : $tenures[0];

        return $calc->perMonth($t);
    }

    /**
     * Options of a mapping the module manages.
     *
     * @return array<int,array<string,mixed>> config option id => [logical, name, type, max, subs[]]
     */
    private function managedOptions(int $mapId): array
    {
        $out = [];
        foreach (ProductMap::options($mapId) as $o) {
            $cid = (int) $o->config_option_id;
            if (!isset($out[$cid])) {
                $opt = Capsule::table('tblproductconfigoptions')->where('id', $cid)->first();
                if (!$opt || !empty($opt->hidden)) {
                    continue;
                }
                $out[$cid] = ['logical' => (string) $o->logical, 'name' => trim(explode('|', (string) $opt->optionname)[0]), 'type' => (int) $opt->optiontype, 'max' => (int) ($opt->qtymaximum ?? 0), 'subs' => []];
            }
            $subName = (string) Capsule::table('tblproductconfigoptionssub')->where('id', $o->config_sub_id)->value('optionname');
            $parts = explode('|', $subName, 2);
            $out[$cid]['subs'][] = ['sub_id' => (int) $o->config_sub_id, 'code' => (string) $o->code, 'label' => trim($parts[1] ?? $parts[0]), 'row' => $o];
        }

        return $out;
    }

    /** @return array<int,object> config option id => tblhostingconfigoptions row */
    private function selections(int $serviceId): array
    {
        $out = [];
        foreach (Capsule::table('tblhostingconfigoptions')->where('relid', $serviceId)->get() as $r) {
            $out[(int) $r->configid] = $r;
        }

        return $out;
    }

    /**
     * Current choice per managed option: quantity for qty / yes-no, sub id for dropdowns.
     *
     * @return array<int,int>
     */
    private function currentSelections(int $serviceId, int $mapId): array
    {
        $sel = $this->selections($serviceId);
        $out = [];
        foreach ($this->managedOptions($mapId) as $cid => $opt) {
            if (isset($sel[$cid])) {
                $out[$cid] = $opt['type'] === 1 ? (int) $sel[$cid]->optionid : (int) $sel[$cid]->qty;
            }
        }

        return $out;
    }

    /**
     * The service's current choices expressed in ANOTHER product's options
     * (matched by logical option and provider code). Choices the new product
     * does not offer are dropped.
     *
     * @return array<int,int> new config option id => qty / sub id
     */
    private function migratedSelections(int $serviceId, int $oldMapId, int $newMapId): array
    {
        $old = $this->managedOptions($oldMapId);
        $new = $this->managedOptions($newMapId);
        $out = [];
        foreach ($this->currentSelections($serviceId, $oldMapId) as $cid => $value) {
            $o = $old[$cid];
            foreach ($new as $ncid => $n) {
                if ($n['logical'] !== $o['logical'] || $n['type'] !== $o['type']) {
                    continue;
                }
                if ($o['type'] !== 1) {
                    $out[$ncid] = $n['max'] > 0 ? min($value, $n['max']) : $value;
                    break;
                }
                $code = null;
                foreach ($o['subs'] as $s) {
                    if ($s['sub_id'] === $value) {
                        $code = $s['code'];
                    }
                }
                foreach ($n['subs'] as $s) {
                    if ($code !== null && $s['code'] === $code) {
                        $out[$ncid] = $s['sub_id'];
                    }
                }
                break;
            }
        }

        return $out;
    }

    /**
     * Write choices to tblhostingconfigoptions.
     *
     * @param array<int,int> $selections config option id => qty / sub id
     */
    private function writeSelections(int $serviceId, int $mapId, array $selections, bool $replace): void
    {
        $managed = $this->managedOptions($mapId);
        if ($replace) {
            // plan change: the old product's option rows no longer apply
            Capsule::table('tblhostingconfigoptions')->where('relid', $serviceId)->delete();
        }
        foreach ($selections as $cid => $value) {
            $cid = (int) $cid;
            if (!isset($managed[$cid])) {
                continue;
            }
            $opt = $managed[$cid];
            $row = $opt['type'] === 1
                ? ['optionid' => (int) $value, 'qty' => 0]
                : ['optionid' => (int) $opt['subs'][0]['sub_id'], 'qty' => max(0, (int) $value)];
            $q = Capsule::table('tblhostingconfigoptions')->where('relid', $serviceId)->where('configid', $cid);
            if ($q->exists()) {
                Capsule::table('tblhostingconfigoptions')->where('relid', $serviceId)->where('configid', $cid)->update($row);
            } else {
                Capsule::table('tblhostingconfigoptions')->insert(['relid' => $serviceId, 'configid' => $cid] + $row);
            }
        }
    }

    /**
     * Validate requested option values and price the difference.
     *
     * @param array<int|string,mixed> $requested config option id => value
     * @return array{0: array<int,array{old:int,new:int}>, 1: float, 2: string[]}
     */
    private function optionChanges(int $serviceId, object $map, array $requested, int $months): array
    {
        $managed = $this->managedOptions((int) $map->id);
        $current = $this->currentSelections($serviceId, (int) $map->id);
        $planner = new PricePlanner($map);
        $removal = Settings::bool('addon_removal');
        $changes = [];
        $lines = [];
        $cost = 0.0;
        foreach ($requested as $cid => $raw) {
            $cid = (int) $cid;
            if (!isset($managed[$cid]) || $managed[$cid]['logical'] === 'os') {
                continue; // not an option of this product, or not changeable
            }
            $opt = $managed[$cid];
            if (!is_scalar($raw) || !preg_match('/^\d{1,6}$/', (string) $raw)) {
                throw new UpgradeException('Invalid value for ' . $opt['name'] . '.');
            }
            $new = (int) $raw;
            if ($opt['type'] === 1) {
                $subs = array_column($opt['subs'], null, 'sub_id');
                if (!isset($subs[$new])) {
                    throw new UpgradeException('Invalid choice for ' . $opt['name'] . '.');
                }
                $old = $current[$cid] ?? (int) $opt['subs'][0]['sub_id'];
                if ($new === $old) {
                    continue;
                }
                if (!$removal && ($subs[$old]['code'] ?? '') !== '') {
                    throw new UpgradeException($opt['name'] . ' cannot be changed online. Please contact support.');
                }
                $monthly = $planner->optionMonthly($subs[$new]['row']);
                if ($monthly === null) {
                    throw new UpgradeException('The price of ' . $opt['name'] . ' is not available right now. Please contact support.');
                }
                $cost += $monthly * $months;
                $lines[] = sprintf('%s: %s -> %s', $opt['name'], $subs[$old]['label'] ?? 'none', $subs[$new]['label']);
            } else {
                $old = $current[$cid] ?? 0;
                if ($opt['type'] === 3) {
                    $new = $new > 0 ? 1 : 0;
                } elseif ($opt['max'] > 0 && $new > $opt['max']) {
                    throw new UpgradeException(sprintf('%s: the maximum is %d.', $opt['name'], $opt['max']));
                }
                if ($new === $old) {
                    continue;
                }
                if ($new < $old && !$removal) {
                    // With removal off the add-on would stay (and keep being renewed) at LogicBoxes while the client pays less.
                    throw new UpgradeException($opt['name'] . ' cannot be reduced online. Please contact support.');
                }
                if ($opt['logical'] === 'block_storage') {
                    if ($new !== 0 && ($new < BlockStorageService::MIN_GB || $new > BlockStorageService::MAX_GB)) {
                        throw new UpgradeException(sprintf('%s must be between %d and %d GB.', $opt['name'], BlockStorageService::MIN_GB, BlockStorageService::MAX_GB));
                    }
                    if ($new !== 0 && $new < $old) {
                        throw new UpgradeException($opt['name'] . ' cannot be made smaller. Remove it (0) and order a new volume after moving your data.');
                    }
                }
                if ($opt['logical'] === 'cpanel_blocks' && $new < $old) {
                    throw new UpgradeException($opt['name'] . ' cannot be reduced before the next renewal.');
                }
                if ($new > $old) {
                    $monthly = $planner->optionMonthly($opt['subs'][0]['row']);
                    if ($monthly === null) {
                        throw new UpgradeException('The price of ' . $opt['name'] . ' is not available right now. Please contact support.');
                    }
                    $cost += $monthly * ($new - $old) * $months;
                }
                $lines[] = $opt['type'] === 3 ? sprintf('%s: %s', $opt['name'], $new ? 'added' : 'removed') : sprintf('%s: %d -> %d', $opt['name'], $old, $new);
            }
            $changes[$cid] = ['old' => $old, 'new' => $new];
        }

        return [$changes, $cost, $lines];
    }

    /**
     * Recurring amount for a product with the given option choices.
     *
     * @param array<int,int> $selections config option id => qty / sub id
     */
    private function recurring(object $map, array $selections, string $cycle, int $currency): ?float
    {
        $cycle = strtolower(str_replace(['-', ' '], '', $cycle));
        $t = (new PricePlanner($map))->targets();
        $amount = $t['product'][$currency][$cycle] ?? -1.0;
        if ($amount < 0) {
            return null;
        }
        $managed = $this->managedOptions((int) $map->id);
        foreach ($selections as $cid => $value) {
            if (!isset($managed[$cid])) {
                continue;
            }
            $opt = $managed[$cid];
            if ($opt['type'] === 1) {
                $amount += $t['options'][(int) $value][$currency][$cycle] ?? 0.0;
            } else {
                $unit = $t['options'][(int) $opt['subs'][0]['sub_id']][$currency][$cycle] ?? 0.0;
                $amount += $opt['type'] === 3 ? ($value > 0 ? $unit : 0.0) : $unit * $value;
            }
        }

        return round($amount, 2);
    }

    /** After a paid change the recurring amount follows the price list. */
    private function setRecurring(int $serviceId, object $map): void
    {
        $service = Capsule::table('tblhosting')->where('id', $serviceId)->first();
        $currency = $this->clientCurrency((int) $service->userid);
        $amount = (new PricePlanner($map))->serviceAmount($serviceId, (string) $service->billingcycle, $currency);
        if ($amount === null || abs((float) $service->amount - $amount) < 0.005) {
            if ($amount !== null) {
                Capsule::table('mod_lbh_services')->where('service_id', $serviceId)->update(['priced_amount' => $amount]);
            }

            return;
        }
        Capsule::table('tblhosting')->where('id', $serviceId)->update(['amount' => $amount]);
        Capsule::table('mod_lbh_services')->where('service_id', $serviceId)->update(['priced_amount' => $amount]);
        Capsule::table('mod_lbh_price_changes')->insert([
            'target' => 'service', 'relid' => $serviceId, 'currency_id' => $currency, 'cycle' => strtolower((string) $service->billingcycle),
            'old_price' => (float) $service->amount, 'new_price' => $amount, 'source' => 'change', 'created_at' => date('Y-m-d H:i:s'),
        ]);
        Logger::log('upgrade', sprintf('Recurring amount %.2f -> %.2f', (float) $service->amount, $amount), 'info', $serviceId);
    }
}
