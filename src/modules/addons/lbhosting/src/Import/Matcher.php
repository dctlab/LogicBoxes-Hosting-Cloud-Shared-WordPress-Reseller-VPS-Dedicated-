<?php

declare(strict_types=1);

namespace LbHosting\Import;

use Illuminate\Database\Capsule\Manager as Capsule;
use LbHosting\Catalog\ProductFamily;
use LbHosting\Products\ProductMap;

/**
 * Suggests the WHMCS side of a LogicBoxes order:
 *   - already linked service (mod_lbh_services.order_id)       -> state "linked"
 *   - WHMCS client with the LogicBoxes customer's email
 *   - unlinked service of the same module with the same domain / hostname:
 *       match_by "email"  - it belongs to that client: safe to link in bulk
 *       match_by "domain" - the customer's email matched no client, so only
 *                           the name agrees. Never linked in bulk: an admin
 *                           must confirm it on the row, because paying that
 *                           service's invoices will renew this order.
 *   - product mapped to the order's account + product key + plan
 */
final class Matcher
{
    /**
     * @param array<string,mixed> $row
     * @return array{match_client_id:?int, match_service_id:?int, match_product_id:?int, match_by:?string, state:string}
     */
    public static function match(int $accountId, string $family, string $key, int $orderId, array $row): array
    {
        $linked = Capsule::table('mod_lbh_services')->where('order_id', $orderId)->where('account_id', $accountId)->value('service_id');
        $clientId = null;
        if (!empty($row['customer_email'])) {
            $clientId = Capsule::table('tblclients')->where('email', $row['customer_email'])->value('id');
            $clientId = $clientId ? (int) $clientId : null;
        }
        $productId = null;
        if (!empty($row['plan_id'])) {
            $map = (new ProductMap())->forPlan($accountId, $key, (int) $row['plan_id']);
            $productId = $map ? (int) $map->product_id : null;
        }
        if ($linked) {
            $owner = Capsule::table('tblhosting')->where('id', $linked)->value('userid');

            return ['match_client_id' => $owner ? (int) $owner : $clientId, 'match_service_id' => (int) $linked, 'match_product_id' => $productId, 'match_by' => 'linked', 'state' => 'linked'];
        }

        $serviceId = null;
        $matchBy = null;
        if (!empty($row['domain'])) {
            $alreadyLinked = array_map('intval', Capsule::table('mod_lbh_services')->whereNotNull('order_id')->pluck('service_id')->all());
            $candidates = Capsule::table('tblhosting')
                ->join('tblproducts', 'tblproducts.id', '=', 'tblhosting.packageid')
                ->where('tblproducts.servertype', ProductFamily::module($family))
                ->where('tblhosting.domain', $row['domain'])
                ->whereIn('tblhosting.domainstatus', ['Pending', 'Active', 'Suspended'])
                ->select(['tblhosting.id', 'tblhosting.userid'])
                ->get()->all();
            $free = array_values(array_filter($candidates, static fn ($c) => !in_array((int) $c->id, $alreadyLinked, true)));
            if ($clientId !== null) {
                // The customer is a known client: only that client's service is ever suggested.
                foreach ($free as $c) {
                    if ((int) $c->userid === $clientId) {
                        $serviceId = (int) $c->id;
                        $matchBy = 'email';
                        break;
                    }
                }
            } elseif (count($free) === 1) {
                // Unknown customer and exactly one service with this name: a suggestion only.
                $serviceId = (int) $free[0]->id;
                $matchBy = 'domain';
            }
        }

        return ['match_client_id' => $clientId, 'match_service_id' => $serviceId, 'match_product_id' => $productId, 'match_by' => $matchBy, 'state' => 'new'];
    }
}
