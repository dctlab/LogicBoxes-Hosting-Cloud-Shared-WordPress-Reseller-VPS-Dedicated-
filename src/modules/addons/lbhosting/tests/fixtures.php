<?php
/** Price / plan fixtures shared by run_phase3.php and run_phase4.php. */

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use LbHosting\Accounts\AccountRepository;
use LbHosting\Pricing\PriceCatalog;
use LbHosting\Support\Settings;

/** A realistic customer-price.json covering every documented shape. */
function priceFixture(): array
{
    return [
        // Hosting (KB 1712 / 1275): planid -> add/renew -> months, + "ssl"
        'singledomainhostinglinuxin' => [
            '1001' => ['add' => ['1' => 99, '12' => 79], 'renew' => ['1' => 129, '12' => 99, '24' => 89], 'ssl' => 150],
            '1002' => ['add' => ['12' => 149], 'renew' => ['12' => 199], 'ssl' => 150],
            '1003' => ['renew' => ['12' => 299]],
        ],
        'resellerhosting' => ['3001' => ['renew' => ['1' => 20, '12' => 18], 'ssl' => 3]],
        'resellerwindowshosting' => ['3101' => ['renew' => ['12' => 25]]],
        // Cloud (KB 2659): "plans" wrapper; add-ons at product level (PATTERN)
        'cloudsiteslinuxus' => ['plans' => ['300109' => ['renew' => ['12' => 20.99, '24' => 33.99]]], 'addons' => ['cpu_1' => 4, 'ram_1' => 3]],
        // VPS: plans + product-level addons
        'virtualserverlinuxus' => ['addons' => ['ipaddress' => 3, 'whmcs' => 15, 'cpanel' => 20, 'plesk_30_domain' => 12, 'storage_1' => 5], 'plans' => [
            '10' => ['renew' => ['1' => 10]], '11' => ['renew' => ['1' => 20]], '12' => ['renew' => ['1' => 15]],
        ]],
        // Dedicated (KB 2064): addons + plans
        'dedicatedserverlinuxin' => ['addons' => ['ipaddress' => 4, 'cpanel' => 25, 'whmcs' => 15], 'plans' => ['105' => ['add' => ['1' => 100], 'renew' => ['1' => 110]]]],
        // WordPress (KB 2852): "plans" wrapper; Business (KB 3561): hosting shape with "ssl"
        'wordpresshostingusa' => ['plans' => ['300109' => ['renew' => ['12' => 20.99], 'add' => ['12' => 2.99]]], 'addons' => ['cpu_1' => 4, 'ipaddress' => 2]],
        'businesshostinglinuxin' => ['41' => ['renew' => ['3' => 500, '12' => 450], 'ssl' => 150], '42' => ['renew' => ['12' => 900]]],
        // Domain keys and other products are ignored
        'dotcom' => ['addnewdomain' => ['1' => 10]],
        'titanmailindia' => ['1758' => ['add' => ['1' => 1]]],
    ];
}

function planFixture(): array
{
    return [
        'singledomainhostinglinuxin' => ['1001' => ['plan_name' => 'Starter'], '1002' => ['plan_name' => 'Business'], '1003' => ['plan_name' => 'Pro']],
        'resellerhosting' => ['3001' => ['plan_name' => 'R1']],
        'cloudsiteslinuxus' => ['300109' => ['plan_name' => 'Cloud Starter']],
        'wordpresshostingusa' => ['300109' => ['plan_name' => 'WP Starter']],
        'businesshostinglinuxin' => ['41' => ['plan_name' => 'Biz Starter'], '42' => ['plan_name' => 'Biz Pro']],
        'virtualserverlinuxus' => ['10' => ['plan_name' => 'VPS S', 'isSSDPlan' => true], '11' => ['plan_name' => 'VPS L', 'isSSDPlan' => true], '12' => ['plan_name' => 'VPS HDD', 'isSSDPlan' => false]],
        'dedicatedserverlinuxin' => ['105' => ['plan_name' => 'DS-1', 'supported_os' => [['os_name' => 'ubuntu_22', 'os_display_name' => 'Ubuntu 22.04'], ['os_name' => 'alma_9', 'os_display_name' => 'AlmaLinux 9']], 'cpu' => ['cpu_cores' => 8], 'ram' => ['ram_memory' => '32 GB']]],
    ];
}

function catalogDb(string $upgradeMode = 'provider'): ScriptedTransport
{
    freshDb('lbhsingledomain', 'singledomainhostinglinuxin:1001');
    Capsule::table('tblproducts')->delete(); // start with no products
    Capsule::table('tblhosting')->delete();
    Settings::override(array_merge(Settings::DEFAULTS, ['upgrade_mode' => $upgradeMode]));
    $t = new ScriptedTransport();
    $t->on('GET', 'api/products/customer-price.json', 200, priceFixture());
    $t->on('GET', 'api/products/plan-details.json', 200, planFixture());
    $acc = (new AccountRepository())->find(1);
    (new PriceCatalog($t))->refresh($acc);

    return $t;
}

function genOpts(array $over = []): array
{
    // Phase 3/4/6 suites cover the customer-price source; tests/run_pricing.php covers the cost price.
    return array_merge(['price_source' => 'sell', 'new_group' => 'Hosting', 'hidden' => '1', 'create_options' => '1', 'markup_pct' => '10', 'rounding' => '2dp', 'source_currency_id' => '1', 'sync_prices' => '1', 'upgrade_paths' => '1'], $over);
}

function pricing(string $type, int $relid, int $currency = 1): ?object
{
    return Capsule::table('tblpricing')->where('type', $type)->where('relid', $relid)->where('currency', $currency)->first();
}

