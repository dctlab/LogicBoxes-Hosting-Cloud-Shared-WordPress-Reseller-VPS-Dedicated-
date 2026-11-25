<?php
/** Shared helpers for the client-action test suites. */

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use LbHosting\Support\Csrf;
use LbHosting\Support\OutputSignal;
use LbHosting\Support\RedirectSignal;
use LbHosting\Support\Settings;
use LbHosting\WHMCS\ClientAreaController;
use LbHosting\Service\ServiceRepository;
use LbHosting\Catalog\ProductFamily;
use LbHosting\WHMCS\ServerModule;

/** Service 10 linked to order 9001, synced just now. */
function linked(string $module, string $planValue, array $row = [], array $settings = []): ScriptedTransport
{
    freshDb($module, $planValue);
    Settings::override(array_merge(Settings::DEFAULTS, ['panel_hide' => '0'], $settings));
    [$key, $plan] = explode(':', $planValue);
    (new ServiceRepository())->upsert(10, $row + ['family' => \LbHosting\Catalog\ProductFamily::forModule($module), 'order_id' => 9001, 'account_id' => 1, 'product_key' => $key, 'plan_id' => (int) $plan, 'customer_id' => 555, 'provider_status' => 'Active', 'last_synced_at' => date('Y-m-d H:i:s')]);
    Capsule::table('mod_lbh_services')->where('service_id', 10)->update(['created_at' => date('Y-m-d H:i:s', time() - 40 * 86400)]);
    $t = new ScriptedTransport();
    ServerModule::$transport = $t;
    $_SESSION = ['uid' => 1];
    $_SERVER['REMOTE_ADDR'] = '203.0.113.50';

    return $t;
}

/** Run a client action; returns ['redirect' => url] or ['html' => page]. */
function act(string $family, string $action, string $planValue, array $post = [], array $over = [], ?string $token = null, string $method = 'POST'): array
{
    if (empty($GLOBALS['keepRate'])) {
        unset($_SESSION['lbh_rate']);
    }
    $_SERVER['REQUEST_METHOD'] = $method;
    $_POST = $post + ['lbh_token' => $token ?? Csrf::token('clientact10')];
    try {
        ClientAreaController::action($family, $action, params($planValue, $over + ['status' => 'Active']));
    } catch (RedirectSignal $r) {
        $_POST = [];

        return ['redirect' => $r->url];
    } catch (OutputSignal $o) {
        $_POST = [];

        return ['html' => $o->html];
    }
    $_POST = [];

    return [];
}

function flash(): ?array
{
    return $_SESSION['lbh_flash'][10] ?? null;
}

function logText(): string
{
    return json_encode(Capsule::table('mod_lbh_logs')->get()->all()) . json_encode(Capsule::table('mod_lbh_services')->get()->all());
}

