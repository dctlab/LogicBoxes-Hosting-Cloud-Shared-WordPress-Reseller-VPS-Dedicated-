<?php

declare(strict_types=1);

namespace LbHosting\Service;

use Illuminate\Database\Capsule\Manager as Capsule;
use LbHosting\Accounts\AccountRepository;
use LbHosting\Api\DnsApi;
use LbHosting\Api\HttpTransport;
use LbHosting\Catalog\ProductFamily;
use LbHosting\Support\Logger;
use LbHosting\Support\Settings;

/**
 * Points the client's domain at the hosting.
 *
 * Settings > Name servers:
 *   branded  the reseller's own (child) name servers from the API account, on
 *            LogicBoxes managed DNS: the zone is activated and the records the
 *            hosting order needs (dns-record.json) are added to it.
 *   server   the hosting server's own name servers, as LogicBoxes reports them
 *            (the server then answers for the zone itself).
 *   off      nothing is changed; the dashboard only shows what is needed.
 *
 * Only a domain that is Active in WHMCS under the SAME client is ever touched,
 * and the name servers are changed through WHMCS's own registrar module
 * (localAPI DomainUpdateNameservers), whichever registrar that is. The
 * automatic run after Create happens once per service; the client / admin
 * button can run it again.
 */
final class NameserverAutomation
{
    private ?HttpTransport $transport;

    public function __construct(?HttpTransport $transport = null)
    {
        $this->transport = $transport;
    }

    public static function mode(): string
    {
        $m = Settings::get('ns_mode');

        return in_array($m, ['branded', 'server'], true) ? $m : 'off';
    }

    public static function job(int $serviceId): ?object
    {
        return Capsule::table('mod_lbh_dns_jobs')->where('service_id', $serviceId)->first();
    }

    /** The WHMCS domain this service's domain is registered as, for the same client. */
    public static function whmcsDomain(int $clientId, string $domain): ?object
    {
        $domain = strtolower(trim($domain));
        if ($domain === '' || $clientId <= 0) {
            return null;
        }

        return Capsule::table('tbldomains')->where('userid', $clientId)->where('domain', $domain)->where('status', 'Active')->orderBy('id', 'desc')->first();
    }

    /**
     * Name servers the domain should use in the current mode.
     *
     * @param array<int,array<string,mixed>> $records normalised dns-record.json
     * @param array<string,mixed> $details stored order details
     * @return string[]
     */
    public static function target(string $mode, ?int $accountId, array $records, array $details): array
    {
        if ($mode === 'branded') {
            try {
                return array_slice((new AccountRepository())->resolve($accountId)->nameserverList(), 0, 5);
            } catch (\Throwable $e) {
                return [];
            }
        }
        if ($mode === 'server') {
            return DnsRecords::nameservers($records, $details);
        }

        return [];
    }

    /**
     * @param array<string,mixed> $params WHMCS module params (serviceid, userid, domain, ...)
     * @return array{status:string, message:string, nameservers:string[], records_added:int}
     */
    public function apply(string $family, array $params, string $source, bool $force = false): array
    {
        $serviceId = (int) $params['serviceid'];
        $mode = self::mode();
        if ($mode === 'off' || !ProductFamily::can($family, 'dns')) {
            return ['status' => 'skipped', 'message' => 'off', 'nameservers' => [], 'records_added' => 0];
        }
        $job = self::job($serviceId);
        // Only a finished run is final: a skip (domain not Active yet) or a failure is tried again next time.
        if ($job && !$force && $job->status === 'done') {
            return ['status' => (string) $job->status, 'message' => (string) $job->message, 'nameservers' => json_decode((string) $job->nameservers, true) ?: [], 'records_added' => (int) $job->records_added];
        }

        $result = ['status' => 'failed', 'message' => '', 'nameservers' => [], 'records_added' => 0];
        try {
            $prov = new Provisioner($family, null, null, $this->transport);
            [$row, $api] = $prov->linked($params);
            $domain = strtolower(Provisioner::domain($params, false));
            $wd = self::whmcsDomain((int) ($params['userid'] ?? 0), $domain);
            if (!$wd) {
                $result = ['status' => 'skipped', 'message' => 'domain_not_in_whmcs', 'nameservers' => [], 'records_added' => 0];
            } else {
                $records = self::records($prov, $family, $params, true);
                $ns = self::target($mode, $row->account_id ? (int) $row->account_id : null, $records, ServiceRepository::storedDetails($row));
                if (count($ns) < 2) {
                    throw new \RuntimeException($mode === 'branded' ? 'no_branded_ns' : 'no_server_ns');
                }
                $result['nameservers'] = $ns;

                // Branded mode: the zone must exist and hold the records BEFORE the domain is
                // pointed at it - otherwise the site and mail of a live domain would go dark.
                $note = 'ok';
                if ($mode === 'branded') {
                    [$added, $note] = $this->zone($row, $domain, $records, $serviceId);
                    $result['records_added'] = $added;
                    if ($note !== 'ok') {
                        throw new \RuntimeException($note);
                    }
                }

                $current = self::currentNameservers((int) $wd->id);
                $a = $current;
                $b = $ns;
                sort($a);
                sort($b);
                if ($a !== $b) {
                    $req = ['domainid' => (int) $wd->id];
                    foreach ($ns as $i => $one) {
                        $req['ns' . ($i + 1)] = $one;
                    }
                    $res = localAPI('DomainUpdateNameservers', $req);
                    if (!is_array($res) || ($res['result'] ?? '') !== 'success') {
                        Logger::log('nameservers', 'Registrar refused the name server change for ' . $domain . ': ' . (is_array($res) ? ($res['message'] ?? $res['error'] ?? 'unknown') : 'no response'), 'error', $serviceId);
                        throw new \RuntimeException('registrar_failed');
                    }
                    Logger::log('nameservers', sprintf('Name servers of %s set to %s (%s, %s)', $domain, implode(', ', $ns), $mode, $source), 'info', $serviceId);
                }

                $result['status'] = 'done';
                $result['message'] = $note;
            }
        } catch (\Throwable $e) {
            $result['message'] = mb_substr($e->getMessage(), 0, 500);
            if (!in_array($result['message'], ['no_branded_ns', 'no_server_ns', 'registrar_failed', 'zone_elsewhere', 'zone_partial', 'no_records'], true)) {
                Logger::log('nameservers', 'Name server automation failed: ' . $e->getMessage(), 'error', $serviceId);
            } elseif (in_array($result['message'], ['no_branded_ns', 'no_server_ns'], true)) {
                Logger::log('nameservers', $result['message'] === 'no_branded_ns'
                    ? 'Branded name servers are not set on the API account (at least two are needed).'
                    : 'LogicBoxes did not report the hosting server\'s name servers for this order.', 'warning', $serviceId);
            }
        }

        $now = date('Y-m-d H:i:s');
        $fields = ['mode' => $mode, 'status' => $result['status'], 'source' => substr($source, 0, 16), 'nameservers' => json_encode($result['nameservers']), 'records_added' => $result['records_added'], 'message' => $result['message'], 'updated_at' => $now];
        if ($job) {
            Capsule::table('mod_lbh_dns_jobs')->where('service_id', $serviceId)->update($fields);
        } else {
            Capsule::table('mod_lbh_dns_jobs')->insert($fields + ['service_id' => $serviceId, 'created_at' => $now]);
        }

        return $result;
    }

    /**
     * Daily cron: try again where the automatic run could not finish for a
     * reason that resolves itself - the domain was not Active in WHMCS yet
     * (domain and hosting ordered together), or LogicBoxes had no DNS data
     * for the new order yet. Gives up after 14 days.
     */
    public function retryPending(int $limit = 50): int
    {
        if (self::mode() === 'off' || !Settings::bool('ns_on_create')) {
            return 0;
        }
        $done = 0;
        $jobs = Capsule::table('mod_lbh_dns_jobs')->where('status', '!=', 'done')->where('source', 'create')
            ->where('created_at', '>=', date('Y-m-d H:i:s', time() - 14 * 86400))->orderBy('id')->limit($limit)->get();
        foreach ($jobs as $job) {
            if (!in_array((string) $job->message, ['domain_not_in_whmcs', 'no_records', 'no_server_ns'], true)) {
                continue;
            }
            try {
                $serviceId = (int) $job->service_id;
                if ((string) Capsule::table('tblhosting')->where('id', $serviceId)->value('domainstatus') !== 'Active') {
                    continue;
                }
                $r = $this->apply(\LbHosting\WHMCS\ServiceParams::family($serviceId), \LbHosting\WHMCS\ServiceParams::build($serviceId), 'create');
                $done += $r['status'] === 'done' ? 1 : 0;
            } catch (\Throwable $e) {
                Logger::log('nameservers', 'Retry failed: ' . $e->getMessage(), 'warning', (int) $job->service_id);
            }
        }

        return $done;
    }

    /**
     * The records the order needs, cached on the service row (6 hours).
     *
     * @param array<string,mixed> $params
     * @return array<int,array<string,mixed>>
     */
    public static function records(Provisioner $prov, string $family, array $params, bool $refresh = false): array
    {
        $serviceId = (int) $params['serviceid'];
        [$row, $api] = $prov->linked($params);
        $cached = !empty($row->dns_json) ? json_decode((string) $row->dns_json, true) : null;
        $fresh = !empty($row->dns_fetched_at) && strtotime((string) $row->dns_fetched_at) > time() - 6 * 3600;
        if (!$refresh && $fresh) {
            return is_array($cached) ? $cached : []; // also after a failed read: not again on every page view
        }
        try {
            $records = DnsRecords::normalize($api->dnsRecords($family, (string) $row->product_key, (int) $row->order_id, $serviceId));
        } catch (\Throwable $e) {
            Logger::log('dns', 'DNS records could not be read: ' . $e->getMessage(), 'warning', $serviceId);
            (new ServiceRepository())->upsert($serviceId, ['dns_fetched_at' => date('Y-m-d H:i:s')]); // do not retry on every page view

            return is_array($cached) ? $cached : [];
        }
        (new ServiceRepository())->upsert($serviceId, ['dns_json' => json_encode($records), 'dns_fetched_at' => date('Y-m-d H:i:s')]);

        return $records;
    }

    /** @return string[] */
    private static function currentNameservers(int $domainId): array
    {
        try {
            $res = localAPI('DomainGetNameservers', ['domainid' => $domainId]);
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        for ($i = 1; $i <= 5; $i++) {
            $v = is_array($res) ? strtolower(rtrim(trim((string) ($res['ns' . $i] ?? '')), '.')) : '';
            if ($v !== '') {
                $out[] = $v;
            }
        }

        return $out;
    }

    /**
     * Branded mode: activate LogicBoxes DNS for the domain and add what is missing.
     *
     * @param array<int,array<string,mixed>> $records
     * @return array{0:int,1:string} records added, note
     */
    private function zone(object $row, string $domain, array $records, int $serviceId): array
    {
        $account = (new AccountRepository())->resolve($row->account_id ? (int) $row->account_id : null);
        $dns = new DnsApi($account->client($this->transport));
        $addable = array_values(array_filter($records, static fn ($r) => DnsApi::supports((string) $r['type'])));
        if (!$addable) {
            Logger::log('nameservers', 'LogicBoxes returned no DNS records for this order yet, so the name servers of ' . $domain . ' were left unchanged. Try "Apply Name Servers" again once the order is set up.', 'warning', $serviceId);

            return [0, 'no_records'];
        }
        $orderId = $dns->domainOrderId($domain, $serviceId);
        if ($orderId === null) {
            Logger::log('nameservers', $domain . ' was not found as a domain order in this LogicBoxes account, so its DNS zone cannot be set up there and its name servers were left unchanged. Point it by hand, or use the "hosting server" name server mode.', 'warning', $serviceId);

            return [0, 'zone_elsewhere'];
        }
        $dns->activate($orderId, $serviceId);
        $added = 0;
        $failed = 0;
        foreach ($records as $r) {
            if (!DnsApi::supports((string) $r['type'])) {
                continue;
            }
            $host = self::relativeHost((string) $r['host'], $domain);
            try {
                if ($dns->exists($domain, (string) $r['type'], $host, (string) $r['value'], $serviceId)) {
                    continue;
                }
                $dns->add($domain, (string) $r['type'], $host, (string) $r['value'], $r['priority'] ?? null, $serviceId);
                $added++;
            } catch (\Throwable $e) {
                $failed++;
                Logger::log('nameservers', sprintf('DNS record %s %s could not be added: %s', $r['type'], $r['host'], $e->getMessage()), 'warning', $serviceId);
            }
        }
        Logger::log('nameservers', sprintf('DNS zone of %s: %d record(s) added%s', $domain, $added, $failed ? ', ' . $failed . ' failed' : ''), $failed ? 'warning' : 'info', $serviceId);

        return [$added, $failed ? 'zone_partial' : 'ok'];
    }

    private static function relativeHost(string $host, string $domain): string
    {
        $host = strtolower(rtrim(trim($host), '.'));
        if ($host === '' || $host === '@' || $host === $domain) {
            return '@';
        }

        return str_ends_with($host, '.' . $domain) ? substr($host, 0, -strlen($domain) - 1) : $host;
    }
}
