<?php

declare(strict_types=1);

namespace LbHosting\Pricing;

use Illuminate\Database\Capsule\Manager as Capsule;
use LbHosting\Products\ProductMap;
use LbHosting\Support\Logger;

/**
 * After Create / ChangePackage: when the service's recurring amount equals what
 * the price map gives (product + selected options), remember it as
 * module-managed (priced_amount) so later price syncs may update it. A
 * different amount is a manual price and is only reported, never changed here
 * (provider-priced upgrade invoices arrive in Phase 4).
 */
final class RecurringPricer
{
    public static function afterChange(int $serviceId, string $event): ?float
    {
        $service = Capsule::table('tblhosting')->where('id', $serviceId)->first();
        if (!$service) {
            return null;
        }
        $map = (new ProductMap())->forProduct((int) $service->packageid);
        if (!$map) {
            return null;
        }
        $currency = (int) Capsule::table('tblclients')->where('id', $service->userid)->value('currency');
        $amount = (new PricePlanner($map))->serviceAmount($serviceId, (string) $service->billingcycle, $currency);
        if ($amount === null) {
            return null;
        }
        if (abs((float) $service->amount - $amount) < 0.005) {
            Capsule::table('mod_lbh_services')->where('service_id', $serviceId)->update(['priced_amount' => $amount]);

            return $amount;
        }
        Logger::log('pricing', sprintf('After %s the recurring amount is %.2f; the price map gives %.2f. Left unchanged (manual price or promotion).', $event, (float) $service->amount, $amount), 'info', $serviceId);

        return null;
    }
}
