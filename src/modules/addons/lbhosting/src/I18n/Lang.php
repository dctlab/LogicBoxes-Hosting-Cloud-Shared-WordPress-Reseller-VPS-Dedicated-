<?php

declare(strict_types=1);

namespace LbHosting\I18n;

use LbHosting\Support\Settings;

/**
 * Language handling:
 *   1. english.php is always loaded first (the complete base set)
 *   2. lang/{language}.php is merged on top if it exists
 *   3. overrides/lang/{language}.php is merged last, if present. Files in
 *      overrides/ are yours; module updates never ship or overwrite them.
 * Any key missing from the selected language therefore falls back to
 * English, and an unknown language falls back to English completely.
 *
 * Language files return a flat array: return ['key' => 'Text %s', ...];
 */
final class Lang
{
    /** @var array<string, array<string,string>> */
    private static array $loaded = [];

    private static ?string $current = null;

    public static function setLanguage(?string $language): void
    {
        self::$current = self::sanitize($language);
    }

    /** Resolve the active language: explicit > session > client profile > WHMCS default > module default. */
    public static function language(?string $clientLanguage = null): string
    {
        if (self::$current !== null) {
            return self::$current;
        }
        $candidates = [
            $_SESSION['Language'] ?? null,
            $clientLanguage,
            $GLOBALS['CONFIG']['Language'] ?? null,
            Settings::get('default_language'),
        ];
        foreach ($candidates as $c) {
            $c = self::sanitize(is_string($c) ? $c : null);
            if ($c !== null) {
                return $c;
            }
        }

        return 'english';
    }

    public static function t(string $key, ...$args): string
    {
        $strings = self::strings(self::language());
        $text = $strings[$key] ?? $key;

        return $args ? vsprintf($text, $args) : $text;
    }

    /** @return array<string,string> */
    public static function strings(string $language): array
    {
        $language = self::sanitize($language) ?? 'english';
        if (isset(self::$loaded[$language])) {
            return self::$loaded[$language];
        }
        $root = dirname(__DIR__, 2);
        $strings = self::read($root . '/lang/english.php');
        if ($language !== 'english') {
            $strings = array_merge($strings, self::read($root . '/lang/' . $language . '.php'));
        }
        $strings = array_merge($strings, self::read($root . '/overrides/lang/' . $language . '.php'));

        return self::$loaded[$language] = $strings;
    }

    /** @return string[] languages that have a module or override file */
    public static function available(): array
    {
        $root = dirname(__DIR__, 2);
        $langs = [];
        foreach (array_merge(glob($root . '/lang/*.php') ?: [], glob($root . '/overrides/lang/*.php') ?: []) as $f) {
            $langs[basename($f, '.php')] = true;
        }
        ksort($langs);

        return array_keys($langs);
    }

    public static function reset(): void
    {
        self::$loaded = [];
        self::$current = null;
    }

    private static function sanitize(?string $lang): ?string
    {
        if ($lang === null) {
            return null;
        }
        $lang = strtolower(trim($lang));

        // Language names become file names - only allow a strict charset.
        return preg_match('/^[a-z][a-z_-]{1,30}$/', $lang) ? $lang : null;
    }

    /** @return array<string,string> */
    private static function read(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        $data = include $file;

        return is_array($data) ? array_map('strval', $data) : [];
    }
}
