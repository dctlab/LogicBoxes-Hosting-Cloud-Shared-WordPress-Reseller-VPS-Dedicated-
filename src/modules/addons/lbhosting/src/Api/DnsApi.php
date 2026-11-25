<?php

declare(strict_types=1);

namespace LbHosting\Api;

/**
 * LogicBoxes managed DNS, used when a domain is pointed at the reseller's
 * branded name servers: activate the zone, then add the records the hosting
 * order needs. Only works for domains registered in the same reseller account.
 *
 *   domains/orderid.json            GET  domain-name                (KB 763)
 *   dns/activate.json               POST order-id                   (KB 830)
 *   dns/manage/search-records.json  GET  domain-name type no-of-records page-no host value (KB 1106)
 *   dns/manage/add-ipv4-record.json POST domain-name value host ttl (KB 1093; ipv6 1094, mx 1095 + priority, txt 1097)
 *   dns/manage/add-cname-record.json - same family (PATTERN: page not read)
 */
final class DnsApi
{
    private const ADD = ['A' => 'add-ipv4-record', 'AAAA' => 'add-ipv6-record', 'CNAME' => 'add-cname-record', 'MX' => 'add-mx-record', 'TXT' => 'add-txt-record'];

    private LogicBoxesClient $client;

    public function __construct(LogicBoxesClient $client)
    {
        $this->client = $client;
    }

    /** Order id of a domain registered in this reseller account, or null. */
    public function domainOrderId(string $domain, ?int $serviceId = null): ?int
    {
        try {
            $res = $this->client->get('domains/orderid.json', ['domain-name' => $domain], 'DomainOrderId', $serviceId);
        } catch (ApiException $e) {
            return null;
        }
        if (is_array($res)) {
            $res = $res['orderid'] ?? $res['entityid'] ?? null;
        }

        return is_numeric($res) && (int) $res > 0 ? (int) $res : null;
    }

    public function activate(int $domainOrderId, ?int $serviceId = null): void
    {
        try {
            $this->client->post('dns/activate.json', ['order-id' => $domainOrderId], 'DnsActivate', $serviceId);
        } catch (ApiException $e) {
            if (stripos($e->getMessage(), 'already') === false) {
                throw $e;
            }
        }
    }

    public static function supports(string $type): bool
    {
        return isset(self::ADD[$type]);
    }

    public function exists(string $domain, string $type, string $host, string $value, ?int $serviceId = null): bool
    {
        $q = ['domain-name' => $domain, 'type' => $type, 'no-of-records' => 50, 'page-no' => 1, 'value' => $value];
        if ($host !== '' && $host !== '@') {
            $q['host'] = $host;
        }
        try {
            $res = $this->client->get('dns/manage/search-records.json', $q, 'DnsSearch', $serviceId);
        } catch (ApiException $e) {
            return false; // "no records" is reported as an error by some installations
        }
        if (!is_array($res)) {
            return false;
        }
        $want = strtolower(rtrim($value, '.'));
        foreach ($res as $rec) {
            if (is_array($rec) && isset($rec['value']) && strtolower(rtrim((string) $rec['value'], '.')) === $want) {
                $h = strtolower((string) ($rec['host'] ?? '@'));
                if (($host === '' || $host === '@') ? in_array($h, ['', '@', strtolower($domain)], true) : $h === strtolower($host)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function add(string $domain, string $type, string $host, string $value, ?int $priority = null, ?int $serviceId = null): void
    {
        if (!isset(self::ADD[$type])) {
            throw new ApiException('DNS record type ' . $type . ' cannot be added through the API.');
        }
        $p = ['domain-name' => $domain, 'value' => $value];
        if ($host !== '' && $host !== '@') {
            $p['host'] = $host;
        }
        if ($type === 'MX' && $priority !== null) {
            $p['priority'] = $priority;
        }
        $this->client->post('dns/manage/' . self::ADD[$type] . '.json', $p, 'DnsAdd' . $type, $serviceId);
    }
}
