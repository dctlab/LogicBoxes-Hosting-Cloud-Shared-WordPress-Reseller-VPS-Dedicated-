<?php

declare(strict_types=1);

namespace LbHosting\Support;

/**
 * Session synchronizer token for this module's own client-area and addon
 * forms. Same approach as the other ISH modules: a module-owned
 * token compared with hash_equals(), so protection doesn't depend on which
 * WHMCS entry point dispatched the request.
 */
final class Csrf
{
    private const SESSION_KEY = 'lbh_csrf';

    public static function token(string $context = 'default'): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        if (empty($_SESSION[self::SESSION_KEY][$context]) || !is_string($_SESSION[self::SESSION_KEY][$context])) {
            $_SESSION[self::SESSION_KEY][$context] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::SESSION_KEY][$context];
    }

    public static function verify(?string $submitted, string $context = 'default'): bool
    {
        $expected = $_SESSION[self::SESSION_KEY][$context] ?? null;

        return is_string($expected) && is_string($submitted) && $submitted !== '' && hash_equals($expected, $submitted);
    }

    public static function field(string $context = 'default'): string
    {
        return '<input type="hidden" name="lbh_token" value="' . htmlspecialchars(self::token($context), ENT_QUOTES, 'UTF-8') . '">';
    }
}
