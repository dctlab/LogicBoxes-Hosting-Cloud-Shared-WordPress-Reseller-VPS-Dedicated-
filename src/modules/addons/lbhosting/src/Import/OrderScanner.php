<?php

declare(strict_types=1);

namespace LbHosting\Import;

use Illuminate\Database\Capsule\Manager as Capsule;
use LbHosting\Accounts\Account;
use LbHosting\Api\HostingApi;
use LbHosting\Api\HttpTransport;
use LbHosting\Catalog\ProductFamily;
use LbHosting\Service\ServiceRepository;
use LbHosting\Support\Logger;

/**
 * Import Manager, step 1: find the hosting / server orders that already exist
 * at LogicBoxes and stage them in mod_lbh_import_orders with a suggested
 * WHMCS match. Scanning only reads; nothing is changed at LogicBoxes.
 *
 * Per product location (product key):
 *   {path}/search.json  no-of-records, page-no, status[]      (KB 1710 and the
 *   same page of every product; WordPress: REST .../list.json, KB 2955)
 * then one details.json per order not yet handled, for plan, status, expiry,
 * customer and what is on the order.
 *
 * The search RESPONSE is not documented ("Returns the list of ... Orders"), so
 * every entry that carries an order id under one of its usual names is taken
 * (PATTERN). Locations whose API path is unverified simply report an error.
 */
final class OrderScanner
{
    private const PAGE_SIZE = 100;

    private const MAX_PAGES = 50;        // 5,000 orders per location per scan

    private const ID_KEYS = ['orders.orderid', 'orderid', 'order-id', 'order_id', 'entity.entityid', 'entityid'];

    private ?HttpTransport $transport;

    /** @var array<int,array{email:?string,name:?string}> */
    private array $customerCache = [];

    public function __construct(?HttpTransport $transport = null)
    {
        $this->transport = $transport;
    }

    /**
     * @param string[] $families families to scan
     * @param string[] $statuses provider statuses to keep (Active, Suspended, ...)
     * @return array{found:int, new:int, updated:int, skipped:int, errors:string[]}
     */
    public function scan(Account $account, array $families, array $statuses = ['Active', 'Suspended']): array
    {
        $api = $account->api($this->transport);
        $report = ['found' => 0, 'new' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];
        $wantStatus = array_map('strtolower', $statuses);

        foreach ($families as $family) {
            if (!in_array($family, ProductFamily::all(), true)) {
                continue;
            }
            foreach (array_keys(ProductFamily::keys($family)) as $key) {
                $key = (string) $key;
                $orderIds = [];
                for ($page = 1; $page <= self::MAX_PAGES; $page++) {
                    try {
                        $res = $api->search($family, $key, $page, self::PAGE_SIZE, $statuses);
                    } catch (\Throwable $e) {
                        if ($page === 1 && !self::isEmptyResult($e->getMessage())) {
                            $report['errors'][] = ProductFamily::keyLabel($family, $key) . ' (' . ProductFamily::label($family) . '): ' . $e->getMessage();
                        }
                        break;
                    }
                    $ids = self::orderIds($res);
                    foreach ($ids as $id) {
                        $orderIds[$id] = true;
                    }
                    $total = isset($res['recsindb']) && is_numeric($res['recsindb']) ? (int) $res['recsindb'] : null;
                    if (count($ids) < self::PAGE_SIZE || ($total !== null && $page * self::PAGE_SIZE >= $total)) {
                        break;
                    }
                }

                foreach (array_keys($orderIds) as $orderId) {
                    $existing = Capsule::table('mod_lbh_import_orders')->where('account_id', $account->id)->where('product_key', $key)->where('order_id', $orderId)->first();
                    if ($existing && in_array($existing->state, ['linked', 'imported', 'ignored'], true)) {
                        $report['skipped']++; // already handled: no need to read it again
                        continue;
                    }
                    try {
                        $d = $api->details($family, $key, (int) $orderId);
                    } catch (\Throwable $e) {
                        $report['errors'][] = 'Order ' . $orderId . ': ' . $e->getMessage();
                        continue;
                    }
                    if (!$d) {
                        continue;
                    }
                    $status = (string) ServiceRepository::pick($d, ['currentstatus', 'orderstatus', 'order_status', 'status']);
                    if ($wantStatus && !in_array(strtolower($status), $wantStatus, true)) {
                        continue;
                    }
                    $report['found']++;
                    try {
                        $this->stage($account, $api, $family, $key, (int) $orderId, $d, $existing, $report);
                    } catch (\Throwable $e) {
                        $report['errors'][] = 'Order ' . $orderId . ' could not be staged: ' . $e->getMessage();
                    }
                }
            }
        }
        Logger::log('import', sprintf('Scan of "%s": %d orders (%d new, %d refreshed, %d already handled)%s', $account->name, $report['found'], $report['new'], $report['updated'], $report['skipped'], $report['errors'] ? ' - ' . implode('; ', array_slice($report['errors'], 0, 5)) : ''));

        return $report;
    }

    /** "No orders" comes back as an error from some LogicBoxes installations. */
    private static function isEmptyResult(string $message): bool
    {
        return (bool) preg_match('/no (records|orders|entity|data)|not found|no result/i', $message);
    }

    /**
     * @param array<mixed> $res
     * @return int[]
     */
    public static function orderIds(array $res): array
    {
        $out = [];
        $walk = static function ($node, int $depth) use (&$walk, &$out): void {
            if (!is_array($node) || $depth > 3) {
                return;
            }
            $id = ServiceRepository::pick($node, self::ID_KEYS);
            if (is_numeric($id) && (int) $id > 0) {
                $out[(int) $id] = true;

                return;
            }
            foreach ($node as $child) {
                $walk($child, $depth + 1);
            }
        };
        foreach ($res as $child) {
            $walk($child, 1);
        }

        return array_keys($out);
    }

    /**
     * @param array<string,mixed> $d
     * @param array<string,mixed> $report
     */
    private function stage(Account $account, HostingApi $api, string $family, string $key, int $orderId, array $d, ?object $existing, array &$report): void
    {
        $plan = ServiceRepository::pick($d, ['planid', 'plan-id', 'plan_id']);
        $customerId = ServiceRepository::pick($d, ['customerid', 'customer-id', 'customer_id']);
        $domain = strtolower(trim((string) ServiceRepository::pick($d, ['domainname', 'domain-name', 'domain_name', 'hostname', 'description'])));
        $row = [
            'family' => $family,
            'domain' => mb_substr($domain, 0, 191),
            'customer_id' => is_numeric($customerId) && (int) $customerId > 0 ? (int) $customerId : null,
            'lb_status' => substr((string) ServiceRepository::pick($d, ['currentstatus', 'orderstatus', 'order_status', 'status']), 0, 32),
            'plan_id' => is_numeric($plan) ? (int) $plan : null,
            'plan_name' => mb_substr((string) ServiceRepository::pick($d, ['plan_name', 'planname', 'plan-name']), 0, 191) ?: null,
            'extras_json' => json_encode(ProviderExtras::read($family, $d)),
            'expires_at' => ($ts = ServiceRepository::timestamp(ServiceRepository::pick($d, ['endtime', 'expirytimestamp', 'expirydate', 'expiry_date']))) !== null ? date('Y-m-d H:i:s', $ts) : null,
            'scanned_at' => date('Y-m-d H:i:s'),
            'message' => null,
            'customer_email' => null,
            'customer_name' => null,
        ];
        if ($row['customer_id']) {
            $cust = $this->customer($api, (int) $row['customer_id']);
            $row['customer_email'] = $cust['email'];
            $row['customer_name'] = $cust['name'];
        }
        $row = array_merge($row, Matcher::match($account->id, $family, $key, $orderId, $row));

        if ($existing) {
            Capsule::table('mod_lbh_import_orders')->where('id', $existing->id)->update($row);
            $report['updated']++;
        } else {
            Capsule::table('mod_lbh_import_orders')->insert($row + ['account_id' => $account->id, 'product_key' => $key, 'order_id' => $orderId]);
            $report['new']++;
        }
    }

    /** @return array{email:?string,name:?string} */
    private function customer(HostingApi $api, int $customerId): array
    {
        if (isset($this->customerCache[$customerId])) {
            return $this->customerCache[$customerId];
        }
        $out = ['email' => null, 'name' => null];
        try {
            // customers/details-by-id.json - same fields as customers/details.json (KB 874); the by-id call itself is PATTERN
            $d = $api->client()->get('customers/details-by-id.json', ['customer-id' => $customerId], 'CustomerById');
            if (is_array($d)) {
                $email = (string) ($d['username'] ?? $d['useremail'] ?? $d['email'] ?? '');
                $out['email'] = filter_var($email, FILTER_VALIDATE_EMAIL) ? strtolower($email) : null;
                $out['name'] = isset($d['name']) ? mb_substr((string) $d['name'], 0, 191) : null;
            }
        } catch (\Throwable $e) {
            // unknown customer - matching falls back to the domain
        }

        return $this->customerCache[$customerId] = $out;
    }
}
