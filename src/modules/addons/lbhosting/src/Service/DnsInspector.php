<?php

declare(strict_types=1);

namespace LbHosting\Service;

/**
 * Live DNS view for the client dashboard: the domain's current name servers
 * and each record the hosting order needs (from dns-record.json), compared
 * with what public DNS returns right now. Results are cached in the session
 * for five minutes so a page view never waits on DNS twice.
 */
final class DnsInspector
{
    /** @var callable fn(string $host, int $type): array */
    private $resolver;

    public function __construct(?callable $resolver = null)
    {
        $this->resolver = $resolver ?? static function (string $host, int $type): array {
            $r = @dns_get_record($host, $type);

            return is_array($r) ? $r : [];
        };
    }

    /**
     * @param array<int,array<string,mixed>> $expected records from the provider
     * @return array<string,mixed>
     */
    public function inspect(string $domain, array $expected, bool $useCache = true): array
    {
        $domain = strtolower(rtrim($domain, '.'));
        $cacheKey = 'lbh_dns_' . md5($domain . json_encode($expected));
        if ($useCache && isset($_SESSION[$cacheKey]['at']) && $_SESSION[$cacheKey]['at'] > time() - 300) {
            return $_SESSION[$cacheKey]['data'];
        }

        $ns = array_values(array_unique(array_filter(array_map(static fn ($r) => strtolower(rtrim((string) ($r['target'] ?? ''), '.')), $this->q($domain, DNS_NS)))));
        sort($ns);

        $rows = [];
        foreach ($expected as $rec) {
            $type = strtoupper((string) ($rec['type'] ?? ''));
            $host = self::fqdn((string) ($rec['host'] ?? '@'), $domain);
            $want = trim((string) ($rec['value'] ?? ''));
            if ($want === '' || !in_array($type, ['MX', 'TXT', 'CNAME', 'A', 'AAAA'], true)) {
                continue;
            }
            $live = $this->live($type, $host);
            $rows[] = [
                'type' => $type,
                'host' => $host,
                'display_host' => $host === $domain ? '@' : (str_ends_with($host, '.' . $domain) ? substr($host, 0, -strlen($domain) - 1) : $host),
                'expected' => $type === 'MX' && isset($rec['priority']) ? $rec['priority'] . ' ' . $want : $want,
                'current' => $live,
                'state' => self::state($type, $want, $live),
            ];
        }

        $data = [
            'domain' => $domain,
            'nameservers' => $ns,
            'records' => $rows,
            'all_ok' => $rows !== [] && !array_filter($rows, static fn ($r) => $r['state'] !== 'ok'),
            'checked_at' => date('Y-m-d H:i'),
        ];
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION[$cacheKey] = ['at' => time(), 'data' => $data];
        }

        return $data;
    }

    /** @return string[] */
    private function live(string $type, string $host): array
    {
        $map = ['MX' => DNS_MX, 'TXT' => DNS_TXT, 'CNAME' => DNS_CNAME, 'A' => DNS_A, 'AAAA' => DNS_AAAA];
        $out = [];
        foreach ($this->q($host, $map[$type]) as $r) {
            switch ($type) {
                case 'MX':
                    $out[] = (int) ($r['pri'] ?? 0) . ' ' . strtolower(rtrim((string) ($r['target'] ?? ''), '.'));
                    break;
                case 'TXT':
                    $out[] = (string) ($r['txt'] ?? implode('', (array) ($r['entries'] ?? [])));
                    break;
                case 'CNAME':
                    $out[] = strtolower(rtrim((string) ($r['target'] ?? ''), '.'));
                    break;
                case 'A':
                    $out[] = (string) ($r['ip'] ?? '');
                    break;
                case 'AAAA':
                    $out[] = (string) ($r['ipv6'] ?? '');
                    break;
            }
        }
        $out = array_values(array_unique(array_filter($out, static fn ($v) => $v !== '')));
        sort($out);

        return $out;
    }

    /** @param string[] $live */
    public static function state(string $type, string $want, array $live): string
    {
        if (!$live) {
            return 'missing';
        }
        $norm = static fn (string $v): string => strtolower(trim(preg_replace('/\s+/', ' ', trim($v, "\" \t")) ?? '', '.'));
        $w = $norm($want);
        switch ($type) {
            case 'MX':
                foreach ($live as $l) {
                    if ($norm((string) preg_replace('/^\d+\s+/', '', $l)) === $w) {
                        return 'ok';
                    }
                }

                return 'missing';
            case 'TXT':
                if (str_starts_with($w, 'v=spf1')) {
                    $spf = array_values(array_filter($live, static fn ($l) => stripos(trim($l, '" '), 'v=spf1') === 0));
                    if (!$spf) {
                        return 'missing';
                    }
                    if (count($spf) > 1) {
                        return 'warning'; // two SPF records = SPF permerror
                    }
                    preg_match_all('/include:(\S+)/i', $w, $m);
                    foreach ($m[1] as $inc) {
                        if (stripos($spf[0], 'include:' . $inc) === false) {
                            return 'warning';
                        }
                    }

                    return 'ok';
                }
                foreach ($live as $l) {
                    if ($norm($l) === $w) {
                        return 'ok';
                    }
                }

                return 'warning';
            default:
                foreach ($live as $l) {
                    if ($norm($l) === $w) {
                        return 'ok';
                    }
                }

                return 'warning';
        }
    }

    public static function fqdn(string $host, string $domain): string
    {
        $host = strtolower(rtrim(trim($host), '.'));
        if ($host === '' || $host === '@' || $host === $domain) {
            return $domain;
        }
        if (str_ends_with($host, '.' . $domain)) {
            return $host;
        }

        return $host . '.' . $domain;
    }

    /** @return array<int,array<string,mixed>> */
    private function q(string $host, int $type): array
    {
        try {
            return ($this->resolver)($host, $type);
        } catch (\Throwable $e) {
            return [];
        }
    }
}
