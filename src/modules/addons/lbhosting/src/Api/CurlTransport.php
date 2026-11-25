<?php

declare(strict_types=1);

namespace LbHosting\Api;

/**
 * cURL transport. TLS verification is always ON.
 *
 * LogicBoxes expects "Content-Type: application/x-www-form-urlencoded" on POST.
 */
final class CurlTransport implements HttpTransport
{
    private int $timeout;

    private int $connectTimeout;

    public function __construct(int $timeout = 30, int $connectTimeout = 10)
    {
        $this->timeout = $timeout;
        $this->connectTimeout = $connectTimeout;
    }

    public function send(string $method, string $url, ?string $body = null): array
    {
        $method = strtoupper($method);
        $ch = curl_init();
        $headers = [
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded; charset=utf-8',
        ];
        $opts = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_USERAGENT => 'LbHosting-WHMCS/' . (defined('LBH_VERSION') ? LBH_VERSION : 'dev'),
        ];

        if ($method === 'GET') {
            $opts[CURLOPT_HTTPGET] = true;
        } else {
            if ($method === 'POST') {
                $opts[CURLOPT_POST] = true;
            } else {
                $opts[CURLOPT_CUSTOMREQUEST] = $method;
            }
            // Always send a body (possibly empty) so Content-Length is set on PATCH/DELETE.
            $opts[CURLOPT_POSTFIELDS] = $body ?? '';
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;

        curl_setopt_array($ch, $opts);
        $resBody = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0 || $resBody === false) {
            throw new ApiException('Could not reach the LogicBoxes API: ' . ($error !== '' ? $error : 'unknown cURL error ' . $errno));
        }

        return ['status' => $status, 'body' => (string) $resBody];
    }
}
