<?php

declare(strict_types=1);

namespace LbHosting\Service;

/**
 * Opens the LogicBoxes Server Management Panel without showing the client the branded order panel.
 *
 * The WHMCS server walks the panel's own sign-in chain (AutoLoginServlet -> LogicBoxes SSL login -> back to the
 * branded host), then asks for the order's page (ManageServiceServlet) and STOPS at the first hop that leaves the
 * branded panel - the hand-off to the Server Management Panel (e.g. https://panel.webhostbox.net/...). Only that
 * hop is given to the client's browser, so the client never sees the branded panel. The hand-off target itself is
 * never requested here: its link may be single-use.
 *
 * Nothing here is documented by LogicBoxes, so every step is checked and the caller falls back to the normal
 * panel login when anything is unexpected.
 */
final class PanelSso
{
    /**
     * Test seam: fn(string $method, string $url, array $form, string $cookieHeader): array{status:int, location:string, cookies:list<string>, body:string}
     *
     * @var callable|null
     */
    public static $http = null;

    private const MAX_HOPS = 20;

    /** @var array<string,array<string,string>> cookie domain => name => value */
    private array $jar = [];

    /** @var list<string> */
    public array $trace = [];

    /**
     * @return array{method:string, url:string, fields:array<string,string>} where the client's browser must go
     */
    public function resolve(string $loginUrl, string $orderPageUrl): array
    {
        $brand = strtolower((string) parse_url($orderPageUrl, PHP_URL_HOST));
        if ($brand === '' || stripos($loginUrl, 'https://') !== 0 || stripos($orderPageUrl, 'https://') !== 0) {
            throw new \RuntimeException('bad_urls');
        }
        // 1. Sign in: follow the chain until it settles on a page that does not forward any more.
        $step = ['GET', $loginUrl, []];
        $hops = 0;
        while ($step !== null) {
            if (++$hops > self::MAX_HOPS) {
                throw new \RuntimeException('login_loop');
            }
            $step = $this->hop($step[0], $step[1], $step[2]);
        }
        if (!$this->hasCookieFor($brand)) {
            throw new \RuntimeException('login_no_session');
        }
        // 2. Ask for the order's page and stop at the first hop that leaves the branded panel.
        $step = ['GET', $orderPageUrl, []];
        $hops = 0;
        while ($step !== null) {
            if (++$hops > self::MAX_HOPS) {
                throw new \RuntimeException('order_loop');
            }
            $host = strtolower((string) parse_url($step[1], PHP_URL_HOST));
            if ($host !== $brand) {
                if (stripos($step[1], 'https://') !== 0) {
                    throw new \RuntimeException('handoff_not_https');
                }
                $this->trace[] = 'hand-off to ' . $host . ' (' . $step[0] . ')';

                return ['method' => $step[0], 'url' => $step[1], 'fields' => $step[2]];
            }
            $step = $this->hop($step[0], $step[1], $step[2]);
        }
        throw new \RuntimeException('order_page_no_handoff');
    }

    /**
     * One request. Returns the next step when the response forwards (redirect, auto-submitted form, meta refresh
     * or script redirect), or null when the page settles.
     *
     * @param array<string,string> $form
     * @return array{0:string,1:string,2:array<string,string>}|null
     */
    private function hop(string $method, string $url, array $form): ?array
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $r = $this->request($method, $url, $form, $this->cookieHeader($host, (string) parse_url($url, PHP_URL_PATH)));
        foreach ($r['cookies'] as $c) {
            $this->store($host, $c);
        }
        $this->trace[] = $method . ' ' . $host . (string) parse_url($url, PHP_URL_PATH) . ' -> ' . $r['status'];
        if ($r['status'] >= 300 && $r['status'] < 400 && $r['location'] !== '') {
            return ['GET', self::absolute($r['location'], $url), []];
        }
        if ($r['status'] !== 200) {
            throw new \RuntimeException('http_' . $r['status'] . '_at_' . $host);
        }
        $next = self::forward($r['body'], $url);
        if ($next === null && preg_match('/You need to be logged in|session might have expired/i', $r['body'])) {
            throw new \RuntimeException('not_logged_in_at_' . $host);
        }

        return $next;
    }

    /**
     * The page's automatic forward, if any.
     *
     * @return array{0:string,1:string,2:array<string,string>}|null
     */
    public static function forward(string $html, string $base): ?array
    {
        if (preg_match('/<meta[^>]+http-equiv=["\']?refresh["\']?[^>]*content=["\']?\s*\d+\s*;\s*url=([^"\'>]+)/i', $html, $m)) {
            return ['GET', self::absolute(html_entity_decode(trim($m[1])), $base), []];
        }
        $autoSubmit = preg_match('/\.submit\s*\(\s*\)/i', $html) === 1;
        if ($autoSubmit && preg_match_all('/<form\b[^>]*>.*?<\/form>/is', $html, $forms) && count($forms[0]) === 1) {
            $f = $forms[0][0];
            $action = preg_match('/<form\b[^>]*\baction=["\']([^"\']*)["\']/i', $f, $a) ? html_entity_decode($a[1]) : $base;
            $m = preg_match('/<form\b[^>]*\bmethod=["\']?(\w+)/i', $f, $mm) ? strtoupper($mm[1]) : 'GET';
            $fields = [];
            if (preg_match_all('/<input\b[^>]*>/i', $f, $inputs)) {
                foreach ($inputs[0] as $in) {
                    if (preg_match('/\bname=["\']([^"\']+)["\']/i', $in, $n)) {
                        $fields[html_entity_decode($n[1])] = preg_match('/\bvalue=["\']([^"\']*)["\']/i', $in, $v) ? html_entity_decode($v[1]) : '';
                    }
                }
            }

            return [$m === 'POST' ? 'POST' : 'GET', self::absolute($action, $base), $fields];
        }
        if (preg_match('/(?:window\.|top\.|document\.)?location(?:\.href)?\s*=\s*["\']([^"\']+)["\']/i', $html, $m)
            || preg_match('/location\.replace\(\s*["\']([^"\']+)["\']\s*\)/i', $html, $m)) {
            return ['GET', self::absolute(html_entity_decode($m[1]), $base), []];
        }

        return null;
    }

    public static function absolute(string $url, string $base): string
    {
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }
        $p = parse_url($base);
        $root = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
        if (str_starts_with($url, '//')) {
            return ($p['scheme'] ?? 'https') . ':' . $url;
        }
        if (str_starts_with($url, '/')) {
            return $root . $url;
        }
        $dir = preg_replace('#/[^/]*$#', '/', (string) ($p['path'] ?? '/'));

        return $root . $dir . $url;
    }

    private function store(string $host, string $setCookie): void
    {
        $parts = array_map('trim', explode(';', $setCookie));
        $pair = explode('=', (string) array_shift($parts), 2);
        if (count($pair) !== 2 || $pair[0] === '') {
            return;
        }
        $domain = $host;
        foreach ($parts as $attr) {
            if (stripos($attr, 'domain=') === 0) {
                $d = strtolower(ltrim(substr($attr, 7), '.'));
                if ($d !== '' && ($d === $host || str_ends_with($host, '.' . $d))) {
                    $domain = $d;
                }
            }
        }
        $this->jar[$domain][$pair[0]] = $pair[1];
    }

    private function cookieHeader(string $host, string $path): string
    {
        $out = [];
        foreach ($this->jar as $domain => $cookies) {
            if ($domain === $host || str_ends_with($host, '.' . $domain)) {
                foreach ($cookies as $k => $v) {
                    $out[] = $k . '=' . $v;
                }
            }
        }

        return implode('; ', $out);
    }

    private function hasCookieFor(string $host): bool
    {
        return $this->cookieHeader($host, '/') !== '';
    }

    /**
     * @param array<string,string> $form
     * @return array{status:int, location:string, cookies:list<string>, body:string}
     */
    private function request(string $method, string $url, array $form, string $cookie): array
    {
        if (self::$http !== null) {
            return (self::$http)($method, $url, $form, $cookie);
        }
        if (defined('LBH_TESTING')) {
            throw new \RuntimeException('no_network_in_tests');
        }
        $ch = curl_init($url);
        $headers = [];
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
            CURLOPT_HTTPHEADER => array_values(array_filter(['Accept: text/html,application/xhtml+xml', $cookie !== '' ? 'Cookie: ' . $cookie : ''])),
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int {
                $p = strpos($line, ':');
                if ($p !== false) {
                    $headers[] = [strtolower(trim(substr($line, 0, $p))), trim(substr($line, $p + 1))];
                }

                return strlen($line);
            },
        ];
        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = http_build_query($form);
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_errno($ch);
        curl_close($ch);
        if ($err !== 0 || !is_string($body)) {
            throw new \RuntimeException('network_error_' . $err);
        }
        $location = '';
        $cookies = [];
        foreach ($headers as [$k, $v]) {
            if ($k === 'location') {
                $location = $v;
            } elseif ($k === 'set-cookie') {
                $cookies[] = $v;
            }
        }

        return ['status' => $status, 'location' => $location, 'cookies' => $cookies, 'body' => $body];
    }
}
