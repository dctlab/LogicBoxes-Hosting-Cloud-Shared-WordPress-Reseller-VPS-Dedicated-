<?php

declare(strict_types=1);

namespace LbHosting\Service;

use LbHosting\Accounts\Account;
use LbHosting\Api\LogicBoxesClient;
use LbHosting\Catalog\ProductFamily;
use LbHosting\Support\Settings;

/**
 * One-click login for the client.
 *
 * 1. Order panel (every product): customers/generate-login-token.json
 *    (customer-id, ip) -> https://{branding}/servlet/AutoLoginServlet
 *    ?userLoginId={token}&role=customer (KB 2942; the token lives 120 s).
 *    This is where Sitebuilder, the VPS / Dedicated server panel, webmail and
 *    everything else LogicBoxes offers for the order are reached.
 *
 * 2. Hosting panel (cPanel / WHM / Plesk): the panel's own login form is
 *    posted from the client's browser with the order's panel username and
 *    password, read live from details.json. The password is never stored or
 *    logged; it only travels in that one HTTPS form post.
 *    UNVERIFIED on a live order: which details.json keys hold the server
 *    host name and the panel password differs per product - see hostFrom().
 */
final class AutoLogin
{
    public const PANELS = [
        'cpanel' => ['port' => 2083, 'path' => '/login/', 'user' => 'user', 'pass' => 'pass', 'label' => 'cPanel'],
        'whm' => ['port' => 2087, 'path' => '/login/', 'user' => 'user', 'pass' => 'pass', 'label' => 'WHM'],
        'plesk' => ['port' => 8443, 'path' => '/login_up.php', 'user' => 'login_name', 'pass' => 'passwd', 'label' => 'Plesk'],
    ];

    /** Which hosting panel an order of this family / location has, or null (servers, WordPress: order panel only). */
    public static function panelType(string $family, string $productKey): ?string
    {
        if (ProductFamily::isServer($family) || ProductFamily::isRest($family)) {
            return null;
        }
        $windows = stripos($productKey, 'windows') !== false;
        if ($family === ProductFamily::RESELLER) {
            return $windows ? 'plesk' : 'whm';
        }

        return $windows ? 'plesk' : 'cpanel';
    }

    /** One-click login to the branded LogicBoxes panel (lands on the panel home page). */
    public static function orderPanelUrl(Account $account, LogicBoxesClient $client, int $customerId, string $ip, ?int $serviceId = null): string
    {
        if ($customerId <= 0) {
            throw new \RuntimeException('The LogicBoxes customer of this service is unknown. Run "Sync from Provider".');
        }
        $branding = self::brandingHost($account, $client);
        $token = $client->get('customers/generate-login-token.json', [
            'customer-id' => $customerId,
            'ip' => filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0',
        ], 'GenerateLoginToken', $serviceId);
        if (is_array($token)) {
            $token = $token['token'] ?? $token['logintoken'] ?? null;
        }
        if (!is_string($token) || $token === '') {
            throw new \RuntimeException('LogicBoxes did not return a login token.');
        }

        return 'https://' . $branding . '/servlet/AutoLoginServlet?userLoginId=' . rawurlencode($token) . '&role=customer';
    }

    /** The order's own page in the LogicBoxes customer panel, or null when it cannot be built. */
    public static function orderPageUrl(string $brandingHost, int $orderId, string $productKey): ?string
    {
        if ($orderId <= 0 || !preg_match('/^[a-z0-9]{2,64}$/', $productKey)) {
            return null;
        }

        return 'https://' . $brandingHost . '/servlet/ManageServiceServlet?orderid=' . $orderId . '&productkey=' . $productKey . '&servicename=' . $productKey;
    }

    public static function brandingHost(Account $account, LogicBoxesClient $client): string
    {
        $host = trim($account->brandingUrl);
        if ($host === '') {
            $details = $client->get('resellers/details.json', [], 'ResellerDetails');
            $host = is_array($details) ? (string) ($details['brandingurl'] ?? '') : '';
        }
        $host = preg_replace('#^https?://#i', '', $host);
        $host = strtolower(trim((string) $host, "/ \t"));
        if ($host === '' || !preg_match('/^[a-z0-9.-]+(:\d+)?$/', $host)) {
            throw new \RuntimeException('The reseller branding URL is unknown. Set it on the API account.');
        }

        return $host;
    }

    /**
     * The panel login form for an order, built from LIVE (unredacted) details.
     *
     * @param array<string,mixed> $details
     * @return array{action:string, fields:array<string,string>, label:string}
     */
    public static function panelForm(string $panel, array $details, string $domain): array
    {
        if (!isset(self::PANELS[$panel])) {
            throw new \RuntimeException('This product has no hosting control panel login.');
        }
        $def = self::PANELS[$panel];
        $user = ServiceRepository::pick($details, ['siteadminusername', 'cpanelusername', 'cp_username', 'adminusername', 'username']);
        $pass = ServiceRepository::pick($details, ['siteadminpassword', 'siteadminpass', 'cppassword', 'cp_password', 'adminpassword', 'password', 'passwd']);
        if (!is_string($user) || $user === '' || !is_string($pass) || $pass === '') {
            throw new \RuntimeException('LogicBoxes did not return the panel login for this order.');
        }
        $host = self::hostFrom($details, $domain);

        return [
            'action' => 'https://' . $host . ':' . $def['port'] . $def['path'],
            'fields' => [$def['user'] => $user, $def['pass'] => $pass],
            'label' => $def['label'],
        ];
    }

    /**
     * Host of the hosting server. Settings > "Panel host" decides:
     *   auto   a server host name in the details if there is one, else the IP
     *   ip     always the order's IP address (certificate warning in the browser)
     *   domain the client's own domain (works once the domain points at the server)
     *
     * @param array<string,mixed> $details
     */
    public static function hostFrom(array $details, string $domain): string
    {
        $mode = Settings::get('panel_host');
        $valid = static fn ($h) => is_string($h) && preg_match('/^([a-z0-9-]+\.)+[a-z]{2,}$/i', $h);
        if ($mode === 'domain' && $valid($domain)) {
            return strtolower($domain);
        }
        if ($mode !== 'ip') {
            foreach (['serverhostname', 'server_hostname', 'serverhost', 'servername', 'server_name', 'hostname', 'cpanelhost', 'panelhost'] as $k) {
                $h = ServiceRepository::pick($details, [$k]);
                if ($valid($h)) {
                    return strtolower((string) $h);
                }
            }
        }
        $ip = ServiceRepository::primaryIp($details);
        if ($ip !== null) {
            return strpos($ip, ':') !== false ? '[' . $ip . ']' : $ip;
        }
        // Never fall back to the client's domain here: it may still point at a previous host,
        // which would then receive the panel password.
        throw new \RuntimeException('The address of the hosting server is unknown.');
    }

    /**
     * Standalone page that posts the login form from the client's browser.
     *
     * @param array{action:string, fields:array<string,string>, label:string} $form
     */
    public static function page(array $form, string $continueLabel): string
    {
        $e = static fn (string $v) => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $h = '<!doctype html><html><head><meta charset="utf-8"><meta name="referrer" content="no-referrer"><meta name="robots" content="noindex"><title>' . $e($form['label']) . '</title></head>'
            . '<body><form id="lbh-login" method="post" action="' . $e($form['action']) . '">';
        foreach ($form['fields'] as $k => $v) {
            $h .= '<input type="hidden" name="' . $e((string) $k) . '" value="' . $e((string) $v) . '">';
        }

        return $h . '<p style="font-family:sans-serif;text-align:center;margin-top:20vh"><button type="submit">' . $e($continueLabel) . ' ' . $e($form['label']) . '</button></p></form>'
            . '<script>document.getElementById("lbh-login").submit();</script></body></html>';
    }
}
