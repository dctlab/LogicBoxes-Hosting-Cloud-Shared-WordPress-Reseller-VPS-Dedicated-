<?php

declare(strict_types=1);

namespace LbHosting\Update;

/**
 * Signed release manifest.
 *
 *   {
 *     "module": "lbhosting", "version": "1.3.0", "released": "2026-10-01",
 *     "zip_url": "https://.../lbhosting-1.1.0.zip", "sha256": "<hex of the zip>",
 *     "min_php": "8.1", "notes": "...",
 *     "signature": "<base64 Ed25519 signature>"
 *   }
 *
 * The signature covers canonical() = the manifest WITHOUT "signature",
 * keys sorted, json_encode(JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).
 * Only the holder of the secret key (tools/sign-release.php) can produce it;
 * the module only ever holds the public key. A compromised download host
 * can therefore not push code: the zip's SHA-256 is inside the signed data.
 */
final class Manifest
{
    public const MODULE = 'lbhosting';

    /** @var array<string,mixed> */
    public array $data;

    /** @param array<string,mixed> $data */
    private function __construct(array $data)
    {
        $this->data = $data;
    }

    /** @param array<string,mixed> $data */
    public static function canonical(array $data): string
    {
        unset($data['signature']);
        ksort($data);
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json) || $json === '') {
            throw new \RuntimeException('Update manifest cannot be put in canonical form.');
        }

        return $json;
    }

    /**
     * Parse + verify. Throws on ANY problem - an unverifiable manifest is
     * treated exactly like a malicious one.
     */
    public static function verify(string $json, string $publicKeyB64): self
    {
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            throw new \RuntimeException('PHP sodium extension is required to verify updates.');
        }
        $pk = base64_decode(trim($publicKeyB64), true);
        if ($pk === false || strlen($pk) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new \RuntimeException('Update public key is missing or invalid (Settings > Updates).');
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Update manifest is not valid JSON.');
        }
        $sig = base64_decode((string) ($data['signature'] ?? ''), true);
        if ($sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw new \RuntimeException('Update manifest is not signed.');
        }
        if (!sodium_crypto_sign_verify_detached($sig, self::canonical($data), $pk)) {
            throw new \RuntimeException('Update manifest signature is INVALID - update refused.');
        }
        if (($data['module'] ?? '') !== self::MODULE) {
            throw new \RuntimeException('Update manifest is for a different module.');
        }
        if (!preg_match('/^\d+\.\d+\.\d+$/', (string) ($data['version'] ?? ''))) {
            throw new \RuntimeException('Update manifest has an invalid version.');
        }
        if (!preg_match('/^[a-f0-9]{64}$/', (string) ($data['sha256'] ?? ''))) {
            throw new \RuntimeException('Update manifest has no valid SHA-256.');
        }
        if (stripos((string) ($data['zip_url'] ?? ''), 'https://') !== 0) {
            throw new \RuntimeException('Update download URL must be https.');
        }

        return new self($data);
    }

    public function version(): string
    {
        return (string) $this->data['version'];
    }

    public function isNewerThan(string $current): bool
    {
        return version_compare($this->version(), $current, '>');
    }

    public function phpOk(): bool
    {
        $min = (string) ($this->data['min_php'] ?? '');

        return $min === '' || version_compare(PHP_VERSION, $min, '>=');
    }

    /** Used by tools/sign-release.php. @param array<string,mixed> $data */
    public static function sign(array $data, string $secretKey): array
    {
        $data['signature'] = base64_encode(sodium_crypto_sign_detached(self::canonical($data), $secretKey));

        return $data;
    }
}
