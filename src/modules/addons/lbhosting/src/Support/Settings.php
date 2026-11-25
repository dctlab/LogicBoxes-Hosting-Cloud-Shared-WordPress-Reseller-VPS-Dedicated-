<?php

declare(strict_types=1);

namespace LbHosting\Support;

use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Module-wide settings stored in mod_lbh_settings (key/value). Defaults live
 * here so a fresh install behaves sensibly before anyone opens the settings page.
 */
final class Settings
{
    public const DEFAULTS = [
        // Lifecycle
        'terminate_mode' => 'suspend',    // suspend | delete | local
        'renew_dedupe' => '1',
        'provider_auto_renew' => '0',     // auto-renew flag sent to LogicBoxes (WHMCS drives renewals, so off)
        'upgrade_months' => 'remaining',  // remaining | tenure - months sent with modify.json
        'addon_removal' => '1',           // remove add-ons at the provider when a config option is lowered
        // Manual fulfilment (items the API cannot provision)
        'manual_todo' => '1',             // create a WHMCS To-Do item
        'manual_ticket' => '1',           // open a support ticket for the client
        'manual_ticket_dept' => '',       // support department id (blank = first department)
        // Upgrades (Phase 4)
        'upgrade_mode' => 'provider',     // provider = module flow priced by LogicBoxes | whmcs = WHMCS's own upgrade pages
        'block_storage_plan_id' => '',    // VPS Block Storage plan id (blank = detect from plan-details)
        // Pricing (Phase 3)
        'price_basis' => 'auto',          // auto | per_month | per_term - what a plan price number means
        'price_action' => 'renew',        // renew | add - which LogicBoxes plan price to sell at
        'extras_basis' => 'per_month',    // per_month | per_year - dedicated IP (ssl) / add-on prices
        'price_sync_enabled' => '1',
        'price_sync_refresh' => '1',
        'price_sync_interval' => '6',     // hours between price checks (WHMCS cron); a change is applied at once
        'price_sync_options' => '1',      // create options LogicBoxes starts pricing on existing products
        'price_sync_last' => '',
        'price_sync_pending' => '0',      // a price change was fetched but not applied yet (retried at the next check)
        // Client area
        'allow_client_renew' => '1',
        'dash_expires' => 'cloud,multidomain,singledomain,reseller,wordpress,business,vps,dedicated',     // product types that show the Expires date
        'show_credentials' => '1',        // show panel username on the dashboard
        'renew_invoice_days' => '7',      // due date of a "Renew now" invoice, days from today
        'allow_panel_login' => '1',       // one-click cPanel / WHM / Plesk login
        'allow_order_panel' => '1',       // one-click login to the LogicBoxes order panel (Sitebuilder, server panel, ...)
        'allow_password_change' => '1',   // panel password change from the dashboard
        'allow_shell' => '1',             // Cloud Hosting SSH on / off
        'show_dns' => '1',                // name server + DNS record section with a live check
        'panel_host' => 'auto',           // auto | ip | domain - host used for the panel login
        'dashboard_notice' => '',         // free text shown at the top of every dashboard
        // Which product types show each dashboard section (comma-separated families)
        'dash_actions' => 'cloud,multidomain,singledomain,reseller,wordpress,business,vps,dedicated',      // Quick actions
        'dash_changes' => 'cloud,multidomain,singledomain,reseller,wordpress,business,vps,dedicated',      // Change your plan or add-ons
        'dash_power' => 'vps,dedicated',  // Server power (VPS / Dedicated)
        'allow_power' => '1',             // Reboot / Shut down / Start buttons (open the LogicBoxes server panel)
        'power_buttons' => 'reboot,shutdown,start', // which power buttons are shown
        'allow_refresh' => '1',
        'panel_order_page' => '1',        // after a panel login, move the tab to the order's own page (ManageServiceServlet)
        'panel_order_delay' => '6',
        'panel_hide' => '1',              // VPS / Dedicated: sign in server-side and send the client straight to the Server Management Panel
        'panel_sso_ip' => '',             // public IP of this WHMCS server for that sign-in (empty = detect)       // seconds to wait for the LogicBoxes login before opening the order page           // client "Refresh" button
        // Name servers / DNS (Phase 5)
        'ns_mode' => 'off',               // off | branded | server
        'ns_on_create' => '1',            // run once after a service is created
        'ns_client_button' => '1',        // let the client apply the name servers from the dashboard
        'default_language' => 'english',
        // Locations LogicBoxes sells but does not document (found on the account, or typed in)
        'discovered_keys' => '',
        'extra_product_keys' => '',
        // Updates (Phase 6)
        'update_url' => '',
        'update_pubkey' => '',
        'update_check' => '1',
        'update_auto' => '0',
        'update_state' => '',
    ];

    /** @var array<string,string>|null */
    private static ?array $cache = null;

    public static function get(string $key, ?string $default = null): string
    {
        self::load();
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }

        return $default ?? (self::DEFAULTS[$key] ?? '');
    }

    public static function bool(string $key): bool
    {
        return in_array(strtolower(self::get($key)), ['1', 'on', 'yes', 'true'], true);
    }

    public static function set(string $key, string $value): void
    {
        Capsule::table('mod_lbh_settings')->updateOrInsert(['setting' => $key], ['value' => $value]);
        if (self::$cache !== null) {
            self::$cache[$key] = $value; // keep the rest of what is loaded (or overridden) as it is
        }
    }

    /** @return array<string,string> */
    public static function all(): array
    {
        self::load();

        return array_merge(self::DEFAULTS, self::$cache);
    }

    /** For tests. @param array<string,string> $values */
    public static function override(array $values): void
    {
        self::$cache = $values;
    }

    private static function load(): void
    {
        if (self::$cache !== null) {
            return;
        }
        self::$cache = [];
        try {
            foreach (Capsule::table('mod_lbh_settings')->get() as $row) {
                self::$cache[(string) $row->setting] = (string) $row->value;
            }
        } catch (\Throwable $e) {
            // not installed yet - defaults apply
        }
    }
}
