<?php

declare(strict_types=1);

namespace LbHosting\WHMCS;

use LbHosting\Catalog\ProductFamily;

/**
 * Reads the service's WHMCS configurable options and turns them into what the
 * customer ordered, in provider terms. Options are recognised by NAME
 * (case-insensitive, the part before "|"), so hand-made options work as long
 * as they use one of the aliases below. The Phase 3 generator creates them
 * with the first alias of each list.
 *
 * Values: quantity options give a number, yes/no options "1"/"0" or "on",
 * dropdown options give the sub-option name. Dropdown sub-options should be
 * named "code|Friendly Name" (e.g. "alma_9|AlmaLinux 9", "cpanel|cPanel") -
 * WHMCS passes the part before "|" to the module (VERIFIED WHMCS convention);
 * a friendly name that merely CONTAINS a known code is accepted too.
 */
final class OptionMap
{
    public const ALIASES = [
        'os' => ['operating system', 'os', 'os template', 'operating systems'],
        'dedicated_ip' => ['dedicated ip', 'dedicated ips', 'dedicated ip address', 'dedicated ip addresses', 'additional ip', 'additional ips', 'extra ip', 'extra ips', 'ip address', 'ip addresses', 'ipv4'],
        'cpu' => ['cpu', 'cpu cores', 'cpu core', 'extra cpu', 'vcpu'],
        'ram' => ['ram', 'memory', 'extra ram', 'ram (gb)'],
        'whmcs' => ['whmcs', 'whmcs license', 'whmcs licence'],
        'control_panel' => ['control panel', 'panel', 'control panel license', 'control panel licence'],
        'storage' => ['storage', 'additional storage', 'storage addon', 'extra storage'],
        'cpanel_blocks' => ['cpanel blocks', 'cpanel block', 'cpanel accounts', 'additional cpanel accounts'],
        'bandwidth' => ['bandwidth', 'additional bandwidth', 'extra bandwidth'],
        'block_storage' => ['block storage', 'san storage', 'san', 'block storage (gb)'],
    ];

    private const NONE = ['', '0', 'none', 'no', 'off', 'false', 'n/a', '-'];

    /** @var array<string,string> normalised option name => raw value */
    private array $values = [];

    /** @param array<string,mixed> $params WHMCS module params */
    public function __construct(array $params)
    {
        foreach ((array) ($params['configoptions'] ?? []) as $name => $value) {
            $n = strtolower(trim(explode('|', (string) $name)[0]));
            $this->values[$n] = is_scalar($value) ? trim((string) $value) : '';
        }
    }

    /** Raw value of a logical option, or null when the service has no such option. */
    public function raw(string $logical): ?string
    {
        foreach (self::ALIASES[$logical] ?? [] as $alias) {
            if (array_key_exists($alias, $this->values)) {
                return $this->values[$alias];
            }
        }

        return null;
    }

    public function has(string $logical): bool
    {
        return $this->raw($logical) !== null;
    }

    /** Quantity / yes-no option as a number (yes = 1). Null when absent. */
    public function quantity(string $logical): ?int
    {
        $v = $this->raw($logical);
        if ($v === null) {
            return null;
        }
        $l = strtolower($v);
        if (in_array($l, ['on', 'yes', 'true'], true)) {
            return 1;
        }
        if (in_array($l, self::NONE, true)) {
            return 0;
        }
        $code = strtolower(trim(explode('|', $v)[0]));
        if (preg_match('/^\d+$/', $code)) {
            return (int) $code;
        }
        if (preg_match('/(\d+)/', $v, $m)) {
            return (int) $m[1];
        }

        return 1; // a named, non-"none" choice counts as one
    }

    /**
     * Dropdown option resolved to one of $allowed codes. Returns '' for an
     * explicit "none", null when the option is absent or unrecognised.
     *
     * @param string[] $allowed
     */
    public function code(string $logical, array $allowed): ?string
    {
        $v = $this->raw($logical);
        if ($v === null) {
            return null;
        }
        $first = strtolower(trim(explode('|', $v)[0]));
        if (in_array($first, self::NONE, true)) {
            return '';
        }
        if (in_array($first, $allowed, true)) {
            return $first;
        }
        // Friendly name containing the code, longest code first (plesk_30_domain before plesk).
        $sorted = $allowed;
        usort($sorted, static fn ($a, $b) => strlen($b) <=> strlen($a));
        $hay = strtolower($v);
        foreach ($sorted as $code) {
            if (str_contains($hay, $code)) {
                return $code;
            }
        }

        return null;
    }

    /** Free-text value (bandwidth tier etc.), '' for none, null when absent. */
    public function text(string $logical): ?string
    {
        $v = $this->raw($logical);
        if ($v === null) {
            return null;
        }
        $first = trim(explode('|', $v)[0]);

        return in_array(strtolower($first), self::NONE, true) ? '' : mb_substr($first, 0, 64);
    }

    /**
     * Desired provider-side state for a service: what to send on Create, and
     * what add-on counts ChangePackage must converge on.
     *
     * Keys in the result:
     *   os        => ?string            server OS code
     *   addons    => [code => qty]      provider add-ons via addon/add.json (servers, cloud)
     *   ssl       => ?bool              SDH / MDH dedicated IP
     *   dedicated_ips => ?int           Reseller dedicated IPs
     *   whmcs     => ?bool              Reseller WHMCS licence
     *   cpanel_blocks => ?int           Reseller Linux extra cPanel blocks
     *   manual    => [item => value]    things only a person can fulfil
     *   unknown   => string[]           recognised options with unrecognised values
     *
     * A null / missing entry means "the service has no option for it - leave
     * the provider as it is".
     *
     * @return array<string,mixed>
     */
    public function desired(ProductConfig $cfg): array
    {
        $family = $cfg->family;
        $key = $cfg->productKey;
        $out = ['os' => null, 'addons' => [], 'ssl' => null, 'dedicated_ips' => null, 'whmcs' => null, 'cpanel_blocks' => null, 'manual' => [], 'unknown' => [], 'block_storage' => null];

        switch ($family) {
            case ProductFamily::CLOUD:
                if (($n = $this->quantity('cpu')) !== null) {
                    $out['addons']['cpu_1'] = $n;
                }
                if (($n = $this->quantity('ram')) !== null) {
                    $out['addons']['ram_1'] = $n;
                }
                if (($n = $this->quantity('dedicated_ip')) !== null) {
                    $out['manual']['dedicated_ip'] = (string) $n;
                }
                break;

            case ProductFamily::WORDPRESS:
                foreach (['cpu' => 'cpu_1', 'ram' => 'ram_1', 'dedicated_ip' => 'ipaddress'] as $logical => $code) {
                    if (($n = $this->quantity($logical)) !== null) {
                        $out['addons'][$code] = $n;
                    }
                }
                break;

            case ProductFamily::SINGLEDOMAIN:
            case ProductFamily::MULTIDOMAIN:
            case ProductFamily::BUSINESS:
                if (($n = $this->quantity('dedicated_ip')) !== null) {
                    $out['ssl'] = $n > 0;
                }
                break;

            case ProductFamily::RESELLER:
                if (($n = $this->quantity('dedicated_ip')) !== null) {
                    $out['dedicated_ips'] = $n;
                }
                if (($n = $this->quantity('whmcs')) !== null) {
                    $out['whmcs'] = $n > 0;
                }
                $meta = ProductFamily::keyMeta($family, $key);
                if ($meta['os'] === 'linux' && ($n = $this->quantity('cpanel_blocks')) !== null) {
                    $out['cpanel_blocks'] = $n;
                }
                break;

            case ProductFamily::VPS:
            case ProductFamily::DEDICATED:
                $osList = ProductFamily::osList($family, $key);
                $os = $osList ? $this->code('os', array_keys($osList)) : null;
                if ($os === null && $this->has('os') && $osList) {
                    $out['unknown'][] = 'os';
                }
                $out['os'] = $os ?: ($cfg->defaultOs ?? null);

                $allowed = ProductFamily::addons($family, $key);
                foreach ($cfg->includedAddons as $code) {
                    $out['addons'][$code] = 1;
                }
                if (isset($allowed['ipaddress']) && ($n = $this->quantity('dedicated_ip')) !== null) {
                    $out['addons']['ipaddress'] = $n;
                }
                if (isset($allowed['whmcs']) && ($n = $this->quantity('whmcs')) !== null) {
                    $out['addons']['whmcs'] = min(1, $n);
                }
                foreach (['control_panel' => 'panel', 'storage' => 'storage'] as $logical => $group) {
                    $codes = array_keys(array_filter($allowed, static fn ($m) => ($m['group'] ?? '') === $group));
                    if (!$codes || !$this->has($logical)) {
                        continue;
                    }
                    $code = $this->code($logical, $codes);
                    if ($code === null) {
                        $out['unknown'][] = $logical;
                        continue;
                    }
                    foreach ($codes as $c) {
                        // one choice per group: the chosen code 1, the others 0
                        if (!in_array($c, $cfg->includedAddons, true)) {
                            $out['addons'][$c] = $c === $code ? 1 : 0;
                        }
                    }
                }
                if ($family === ProductFamily::DEDICATED && ($bw = $this->text('bandwidth')) !== null) {
                    $out['manual']['bandwidth'] = $bw;
                }
                if ($family === ProductFamily::VPS && ($n = $this->quantity('block_storage')) !== null) {
                    $out['block_storage'] = $n; // GB - provisioned in Phase 4
                }
                break;
        }

        return $out;
    }
}
