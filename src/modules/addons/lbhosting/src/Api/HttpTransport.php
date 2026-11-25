<?php

declare(strict_types=1);

namespace LbHosting\Api;

/**
 * Minimal HTTP seam so the API client can be unit-tested without network.
 */
interface HttpTransport
{
    /**
     * @param string      $method GET | POST | PATCH | DELETE
     * @param string      $url    full URL, query string included
     * @param string|null $body   form-encoded body, or null for none
     * @return array{status:int, body:string}
     * @throws ApiException on transport-level failure (DNS, TLS, timeout)
     */
    public function send(string $method, string $url, ?string $body = null): array;
}
