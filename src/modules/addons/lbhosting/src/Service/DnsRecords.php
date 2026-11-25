<?php

declare(strict_types=1);

namespace LbHosting\Service;

/**
 * Reads the answer of {product}/dns-record.json.
 *
 * The KB only says "Returns DNS records of the domain name" (KB 1715, 2664,
 * 3567) - the exact shape is not documented, so this accepts every shape
 * LogicBoxes uses elsewhere: a list of records, a map keyed by number or by
 * record type, with the type / host / value under any of their usual names.
 * PATTERN until checked against a real response ("Show Provider Details").
 */
final class DnsRecords
{
    public const TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS'];

    /**
     * @param mixed $payload
     * @return array<int,array{type:string,host:string,value:string,priority:?int}>
     */
    public static function normalize($payload): array
    {
        $out = [];
        self::walk($payload, null, $out, 0);
        $seen = [];
        $unique = [];
        foreach ($out as $r) {
            $k = $r['type'] . '|' . strtolower($r['host']) . '|' . strtolower($r['value']);
            if (!isset($seen[$k])) {
                $seen[$k] = true;
                $unique[] = $r;
            }
        }

        return array_slice($unique, 0, 60);
    }

    /**
     * @param mixed $node
     * @param array<int,array<string,mixed>> $out
     */
    private static function walk($node, ?string $typeHint, array &$out, int $depth): void
    {
        if (!is_array($node) || $depth > 5) {
            return;
        }
        $lower = [];
        foreach ($node as $k => $v) {
            if (is_string($k)) {
                $lower[strtolower($k)] = $v;
            }
        }
        $pick = static function (array $keys) use ($lower) {
            foreach ($keys as $k) {
                if (isset($lower[$k]) && is_scalar($lower[$k]) && trim((string) $lower[$k]) !== '') {
                    return trim((string) $lower[$k]);
                }
            }

            return null;
        };
        $type = $pick(['type', 'recordtype', 'record_type', 'record-type']);
        $type = $type !== null ? strtoupper($type) : $typeHint;
        $value = $pick(['value', 'address', 'destination', 'content', 'target', 'pointsto', 'points_to', 'data']);
        if ($type !== null && $value !== null && in_array($type, self::TYPES, true)) {
            $host = $pick(['host', 'hostname', 'name', 'record', 'domain']) ?? '@';
            $prio = $pick(['priority', 'pri', 'preference']);
            if (strlen($value) <= 1000 && strlen($host) <= 253) {
                $out[] = ['type' => $type, 'host' => $host, 'value' => rtrim($value, $type === 'TXT' ? '' : '.'), 'priority' => $prio !== null && ctype_digit($prio) ? (int) $prio : null];
            }

            return;
        }
        foreach ($node as $k => $v) {
            $hint = is_string($k) && in_array(strtoupper($k), self::TYPES, true) ? strtoupper($k) : $typeHint;
            self::walk($v, $hint, $out, $depth + 1);
        }
    }

    /**
     * Name servers of the hosting server: NS records in the DNS answer, else
     * ns1..ns4 style keys in the order details.
     *
     * @param array<int,array<string,mixed>> $records normalised
     * @param array<string,mixed> $details
     * @return string[]
     */
    public static function nameservers(array $records, array $details = []): array
    {
        $ns = [];
        foreach ($records as $r) {
            if ($r['type'] === 'NS') {
                $ns[] = $r['value'];
            }
        }
        if (!$ns) {
            array_walk_recursive($details, static function ($v, $k) use (&$ns) {
                if (is_string($k) && is_string($v) && preg_match('/^(ns\d?|nameserver\d?|name_server\d?)$/i', $k)) {
                    foreach (preg_split('/[\s,;]+/', $v) ?: [] as $one) {
                        $ns[] = $one;
                    }
                }
            });
        }
        $out = [];
        foreach ($ns as $one) {
            $one = strtolower(rtrim(trim($one), '.'));
            if (preg_match('/^([a-z0-9-]+\.)+[a-z]{2,}$/', $one)) {
                $out[] = $one;
            }
        }

        return array_slice(array_values(array_unique($out)), 0, 5);
    }
}
