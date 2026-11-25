<?php

declare(strict_types=1);

namespace LbHosting\Support;

use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Two log sinks:
 *  - WHMCS Module Log (logModuleCall) for raw API traffic. The API key and any
 *    one-time sign-in URL/token are passed as $replaceVars so WHMCS masks them
 *    (VERIFIED signature: logModuleCall($module, $action, $request, $response,
 *    $processed, $replaceVars)).
 *  - mod_lbh_logs for the suite's own audit trail, shown in the addon.
 * Neither sink may ever abort the action being logged.
 */
final class Logger
{
    public const MODULE = 'lbhosting';

    private const SECRET_KEYS = ['api-key', 'api_key', 'apikey', 'passwd', 'new-passwd', 'password', 'password2', 'token', 'auth-token', 'userloginid', 'logintoken', 'siteadminpassword', 'siteadminpass', 'cp_password', 'cppassword', 'rootpassword', 'root_password', 'adminpassword', 'licensekey', 'license-key', 'whmcs_key'];

    /**
     * @param mixed $request
     * @param mixed $response
     * @param mixed $processed
     * @param string[] $secrets
     */
    public static function moduleCall(string $action, $request, $response, $processed, array $secrets = []): void
    {
        if (!function_exists('logModuleCall')) {
            return;
        }
        try {
            logModuleCall(self::MODULE, $action, self::redact($request), $response, self::redact($processed), array_values(array_filter($secrets, static fn ($s) => is_string($s) && $s !== '')));
        } catch (\Throwable $e) {
            // never let logging break provisioning
        }
    }

    /** @param array<string,mixed> $context */
    public static function log(string $action, string $message, string $level = 'info', ?int $serviceId = null, array $context = []): void
    {
        try {
            Capsule::table('mod_lbh_logs')->insert([
                'service_id' => $serviceId,
                'action' => substr($action, 0, 64),
                'level' => in_array($level, ['info', 'warning', 'error'], true) ? $level : 'info',
                'message' => substr($message, 0, 2000),
                'context' => $context ? json_encode(self::redact($context), JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) : null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // table may not exist yet (before activation) - ignore
        }
        if ($level === 'error' && function_exists('logActivity')) {
            try {
                logActivity('LogicBoxes Hosting: ' . $message . ($serviceId ? ' - Service ID: ' . $serviceId : ''));
            } catch (\Throwable $e) {
            }
        }
    }

    /**
     * @param mixed $data
     * @return mixed
     */
    public static function redact($data)
    {
        if (!is_array($data)) {
            return $data;
        }
        $out = [];
        foreach ($data as $k => $v) {
            if (is_string($k) && in_array(strtolower($k), self::SECRET_KEYS, true)) {
                $out[$k] = '***';
            } else {
                $out[$k] = is_array($v) ? self::redact($v) : $v;
            }
        }

        return $out;
    }
}
