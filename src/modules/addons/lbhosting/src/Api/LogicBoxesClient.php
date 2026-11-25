<?php

declare(strict_types=1);

namespace LbHosting\Api;

use LbHosting\Support\Logger;

/**
 * LogicBoxes HTTP API client (ResellerClub, NetEarthOne and every other
 * LogicBoxes brand share the API; only the credentials differ).
 *
 * Every hosting and server product this suite sells (Single/Multi Domain,
 * Reseller and Cloud hosting, VPS, Dedicated and Managed servers) lives on the
 * legacy HTTP API (VERIFIED - ResellerClub KB answer 836 tree, 2647-2664):
 *   https://httpapi.com/api/<family>/<os>/<region>/<action>.json
 *   GET: params in the query string; POST: form body.
 *
 * Auth is auth-userid + api-key on every call (VERIFIED). Array params are sent
 * as repeated keys (addon=cpanel&addon=whmcs) as LogicBoxes expects.
 *
 * WordPress Hosting alone uses the REST family on the same host:
 *   https://httpapi.com/restapi/product/wordpresshostingusa/...  (KB 2843-2853)
 * A REST success may carry "error":"NoError" - not an error.
 *
 * Errors are normalised into ApiException. Seen shapes:
 *   {"status":"ERROR","message":"..."}         legacy (VERIFIED, every KB page)
 *   {"status":"error","error":"..."}           legacy
 *   {"error":"..."}                            bare error
 *   {"actionstatus":"Failed", ...}             business failure of an action
 */
final class LogicBoxesClient
{
    public const LIVE_HOST = 'https://httpapi.com/';
    public const TEST_HOST = 'https://test.httpapi.com/';

    /** Response keys whose values are one-time secrets - masked in the Module Log. */
    private const SECRET_RESPONSE_KEYS = ['token', 'logintoken', 'siteadminpassword', 'siteadminpass', 'password', 'passwd', 'cp_password', 'cppassword', 'rootpassword', 'root_password', 'adminpassword', 'licensekey', 'licenseKey', 'whmcs_key'];

    private string $host;

    private string $resellerId;

    private string $apiKey;

    private HttpTransport $transport;

    public function __construct(string $resellerId, string $apiKey, bool $testMode = false, ?string $baseUrl = null, ?HttpTransport $transport = null)
    {
        $this->resellerId = trim($resellerId);
        $this->apiKey = trim($apiKey);
        $this->host = self::normaliseHost($baseUrl !== null && trim($baseUrl) !== '' ? $baseUrl : ($testMode ? self::TEST_HOST : self::LIVE_HOST));
        $this->transport = $transport ?? new CurlTransport();

        if ($this->resellerId === '' || $this->apiKey === '') {
            throw new ApiException('LogicBoxes API account is missing its Reseller ID or API key.');
        }
    }

    /** "https://httpapi.com/api/" and "https://httpapi.com" both become "https://httpapi.com/". */
    public static function normaliseHost(string $url): string
    {
        $url = rtrim(trim($url), '/');
        $url = (string) preg_replace('#/(rest)?api$#i', '', $url);

        return $url . '/';
    }

    public function host(): string
    {
        return $this->host;
    }

    // ------------------------------------------------------------- legacy /api

    /** @param array<string,mixed> $params @return mixed */
    public function get(string $path, array $params = [], string $action = '', ?int $serviceId = null)
    {
        $query = $this->query($params);

        return $this->send('GET', $this->host . 'api/' . ltrim($path, '/') . '?' . $query, null, $path, $params, $action, $serviceId);
    }

    /** @param array<string,mixed> $params @return mixed */
    public function post(string $path, array $params = [], string $action = '', ?int $serviceId = null)
    {
        return $this->send('POST', $this->host . 'api/' . ltrim($path, '/'), $this->query($params), $path, $params, $action, $serviceId);
    }

    // -------------------------------------------------------------- /restapi

    /**
     * REST product API (WordPress Hosting): https://httpapi.com/restapi/product/...
     * GET / POST / PATCH / DELETE with EVERY parameter in the query string
     * (VERIFIED - ResellerClub KB 2843-2853).
     *
     * @param array<string,mixed> $params
     * @return mixed
     */
    public function rest(string $method, string $path, array $params = [], string $action = '', ?int $serviceId = null)
    {
        $method = strtoupper($method);
        if (!in_array($method, ['GET', 'POST', 'PATCH', 'DELETE'], true)) {
            throw new ApiException('Unsupported HTTP method ' . $method);
        }
        $url = $this->host . 'restapi/' . ltrim($path, '/') . '?' . $this->query($params);

        return $this->send($method, $url, $method === 'GET' ? null : '', 'restapi/' . ltrim($path, '/'), $params, $action, $serviceId);
    }

    // ------------------------------------------------------------------ core

    /**
     * @param array<string,mixed> $params for logging only
     * @return mixed decoded JSON (array, int, string, bool)
     */
    private function send(string $method, string $url, ?string $body, string $path, array $params, string $action, ?int $serviceId)
    {
        $raw = null;
        $decoded = null;
        try {
            $res = $this->transport->send($method, $url, $body);
            $raw = $res['body'];
            $trim = trim($raw);
            $decoded = $trim === '' ? [] : json_decode($raw, true);
            if ($decoded === null && $trim !== 'null') {
                // Bare, unquoted scalars (e.g. an order id) are valid bodies too.
                if (preg_match('/^-?\d+$/', $trim)) {
                    $decoded = (int) $trim;
                } elseif ($res['status'] < 400 && preg_match('/^[A-Za-z0-9._~+\/=-]{8,512}$/', $trim)) {
                    $decoded = $trim; // an unquoted token
                } elseif ($res['status'] >= 400) {
                    throw new ApiException('LogicBoxes API returned HTTP ' . $res['status'] . ': ' . mb_substr(strip_tags($trim), 0, 200), $res['status']);
                } else {
                    throw new ApiException('Unexpected (non-JSON) response from LogicBoxes, HTTP ' . $res['status'] . '.', $res['status']);
                }
            }

            $error = self::extractError($decoded);
            if ($error !== null) {
                throw new ApiException($error, $res['status'], is_array($decoded) ? $decoded : null);
            }
            if ($res['status'] >= 400) {
                throw new ApiException('LogicBoxes API returned HTTP ' . $res['status'] . '.', $res['status'], is_array($decoded) ? $decoded : null);
            }

            return $decoded;
        } finally {
            Logger::moduleCall(
                $action !== '' ? $action : $path,
                ['method' => $method, 'path' => $path, 'params' => $params, 'serviceid' => $serviceId],
                $raw,
                $decoded,
                array_merge([$this->apiKey], self::secretValues($decoded))
            );
        }
    }

    /** @param mixed $decoded */
    public static function extractError($decoded): ?string
    {
        if (!is_array($decoded)) {
            return null;
        }
        $status = isset($decoded['status']) && is_string($decoded['status']) ? strtolower($decoded['status']) : '';
        if ($status === 'error' || $status === 'failed' || $status === 'failure') {
            foreach (['message', 'error', 'actionstatusdesc', 'msg'] as $k) {
                if (!empty($decoded[$k]) && is_string($decoded[$k])) {
                    return $decoded[$k];
                }
            }

            return 'LogicBoxes reported an error without a message.';
        }
        if (isset($decoded['error']) && is_string($decoded['error'])) {
            $err = trim($decoded['error']);
            $isSuccess = in_array($status, ['success', 'active'], true);
            if ($err !== '' && strcasecmp($err, 'NoError') !== 0 && !$isSuccess) {
                return $err;
            }
        }
        // Action results carry actionstatus=Failed on business failures.
        if (isset($decoded['actionstatus']) && strtolower((string) $decoded['actionstatus']) === 'failed') {
            return (string) ($decoded['actionstatusdesc'] ?? 'The requested action failed at LogicBoxes.');
        }

        return null;
    }

    /** @param array<string,mixed> $params */
    private function query(array $params): string
    {
        return self::buildQuery(array_merge($params, [
            'auth-userid' => $this->resellerId,
            'api-key' => $this->apiKey,
        ]));
    }

    /** @param array<string,mixed> $params */
    public static function buildQuery(array $params): string
    {
        $parts = [];
        foreach ($params as $key => $value) {
            if ($value === null) {
                continue;
            }
            $k = rawurlencode((string) $key);
            $values = is_array($value) ? $value : [$value];
            foreach ($values as $v) {
                if (is_bool($v)) {
                    $v = $v ? 'true' : 'false';
                }
                $parts[] = $k . '=' . rawurlencode((string) $v);
            }
        }

        return implode('&', $parts);
    }

    /**
     * @param mixed $decoded
     * @return string[]
     */
    private static function secretValues($decoded): array
    {
        $out = [];
        if (is_array($decoded)) {
            $keys = array_map('strtolower', self::SECRET_RESPONSE_KEYS);
            array_walk_recursive($decoded, static function ($v, $k) use (&$out, $keys) {
                if (is_string($k) && in_array(strtolower($k), $keys, true) && is_scalar($v) && (string) $v !== '') {
                    $out[] = (string) $v;
                }
            });
        } elseif (is_string($decoded) && strlen($decoded) >= 8) {
            $out[] = $decoded; // bare login token
        }
        // The raw body holds the JSON-escaped form ("\/", "\u00e9", "\"") - mask that spelling too.
        foreach ($out as $v) {
            $esc = substr((string) json_encode($v), 1, -1);
            if ($esc !== '' && $esc !== $v) {
                $out[] = $esc;
            }
            $esc = substr((string) json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 1, -1);
            if ($esc !== '' && $esc !== $v) {
                $out[] = $esc;
            }
        }

        return array_values(array_unique($out));
    }
}
