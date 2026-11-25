# LogicBoxes Hosting for WHMCS v1.3.2

Sell **Cloud Hosting, Multi Domain Hosting, Single Domain Hosting, Reseller Bulk Hosting, WordPress Hosting, Business Hosting, VPS (Linux KVM) and Dedicated / Managed Servers** through ResellerClub, NetEarthOne or any other LogicBoxes reseller account. Several accounts can be connected at once.

Requires WHMCS 8.x/9.x and PHP 8.1+ with curl and json. No Composer or third-party libraries.

Module auto-update, product and option setup with price import, daily price sync, provider-priced upgrades, client dashboards with one-click logins, name server automation and an Import Manager for existing orders are all included. `docs/GO-LIVE-CHECKLIST.md` lists what to verify on your own reseller account before selling: the module was built against the LogicBoxes documentation without a live account.

## Layout

```
modules/addons/lbhosting/          shared core: admin pages, hooks, src/, lang/, migrations/, tests/, docs/
modules/servers/lbhcloud/          Cloud Hosting
modules/servers/lbhmultidomain/    Multi Domain Hosting      (Linux + Windows; US-TX, US-CA, IN, UK, HK, TR)
modules/servers/lbhsingledomain/   Single Domain Hosting     (Linux + Windows; US-TX, US-CA, IN, UK, HK, TR)
modules/servers/lbhreseller/       Reseller Bulk Hosting     (Linux + Windows; US, IN, UK, HK)
modules/servers/lbhwordpress/      WordPress Hosting         (US documented; other locations found on your account)
modules/servers/lbhbusiness/       Business Hosting          (India documented; other locations found on your account)
modules/servers/lbhvps/            VPS Linux KVM             (US, IN)
modules/servers/lbhdedicated/      Dedicated Linux (US, IN), Dedicated Windows (IN), Managed Server Linux (US)
```

The eight server modules are thin wrappers; all logic lives once in `modules/addons/lbhosting/src`. Upload all nine folders.

## Locations LogicBoxes does not document

The API documentation lists Business Hosting for India only and WordPress Hosting for the US only. Any other location your reseller account sells is picked up from the account's own price and plan list, by product key, when you **fetch prices** (Price Catalog) or **save / test an API account**. It then appears everywhere a location is chosen: Price Catalog, Product Generator, the Plan loader of a hand-made product, and the Import Manager.

- Recognised keys: `businesshosting{linux|windows}{region}`, `wordpresshosting{region}`, `cloudsites{os}{region}`.
- The API path follows the documented location of the same product (for example `businesshosting/linux/us`), so these show the "path unverified" badge until you have placed one order.
- If a location is not found, add its product key under *Settings > Locations > Extra product keys*.

## Install

1. Upload the folders to your WHMCS root (on nginx/RunCloud you may leave `tests/` out; its scripts refuse to run outside the CLI anyway).
2. **Setup > Addon Modules > LogicBoxes Hosting > Activate**, grant your admin role access. Activation creates the `mod_lbh_*` tables; deactivating never deletes data.
3. **Addons > LogicBoxes Hosting > API Accounts > Add**: Reseller ID + API key (Test mode for a demo account). Whitelist the server's IP in the reseller panel (*Settings > API*). Saving runs a connection test and reports the plans found per product.
4. **Price Catalog > Fetch prices and plans from LogicBoxes.** Check the "per month" column against your reseller panel; if it is 12x off, change *Settings > Plan price basis*. Compare one dedicated IP / add-on price too (*Settings > Dedicated IP / add-on price basis*). **Clear catalog** (same page) removes the stored prices and plans - of the product type on screen or of all types - for example plans LogicBoxes no longer sells; "fetch again" reloads them at once. WHMCS products are not changed.
5. **Product Generator:** pick the product family, tick the plans (every location), choose a group, markup, rounding and the WHMCS currency your LogicBoxes account sells in, enter monthly prices for anything LogicBoxes does not price (Cloud dedicated IP, Reseller WHMCS / cPanel blocks, Dedicated bandwidth tiers), then **Generate**. It creates per plan:
   - one WHMCS product with the module settings filled in, prices in every currency (cycles LogicBoxes does not sell are disabled)
   - its configurable options, named exactly as the module reads them (table below), each priced from the catalogue
   - WHMCS upgrade paths between plans of the same location (VPS: upward only; none for Cloud / Dedicated)

   Products are created hidden. Review, then unhide. Re-running updates, never duplicates.
6. **Products & Price Sync** shows pending price changes (products, option sub-options and - if enabled per product - existing services' recurring amounts) and applies them; the WHMCS daily cron does the same automatically. A recurring amount an admin changed by hand is never touched.

Or create a product by hand: **Module** = one of the eight "(LogicBoxes / ResellerClub)" modules, then in Module Settings:

| Setting | Meaning |
|---|---|
| API Account | Which reseller account. Blank = the default account. |
| Plan | Click the load icon; stored as `productkey:planid`. **The product key decides location and OS** (e.g. `multidomainhostinglinuxin` = Linux, India). |
| Invoice Option | How LogicBoxes bills **you**. `NoInvoice` debits your reseller balance. |
| Default OS (VPS/Dedicated) | OS code when the order has no OS option (`alma_8`, `ubuntu_22`, `win2k19`, ...). Required for Dedicated Windows. |
| Included Add-ons (VPS/Dedicated) | Add-on codes always ordered, e.g. `cpanel,whmcs`. |

Hosting products must ask for a domain; server products need a fully qualified hostname (the service Domain field).

## Configurable options the module understands

The generator creates these for you. Hand-made options work too: they are recognised by **option name** (case-insensitive). Dropdown sub-options should be named `code|Friendly name` (WHMCS passes the code to the module).

| Option name (any of) | Products | Effect at LogicBoxes |
|---|---|---|
| Dedicated IP / Dedicated IPs / Additional IP / IP Address | SDH, MDH | 1 = `enable-ssl` with the order / `enable-ssl.json`; 0 = `disable-ssl.json` |
| | Business | `enable-ssl` **with the order and on renewal only**; a change on a running order is **manual** (To-Do + ticket) |
| | WordPress | quantity of the `ipaddress` add-on |
| | Reseller | quantity; first with the order (`add-dedicated-ip`), more via `add-dedicated-ip.json`; lowering deletes the last IPs |
| | VPS, Dedicated | quantity of the `ipaddress` add-on |
| | **Cloud** | **manual** - To-Do item + client ticket (the API cannot add IPs) |
| CPU / RAM | Cloud, WordPress | units of `cpu_1` / `ram_1` (add-on add / delete calls) |
| WHMCS License | Reseller | `generate-license-key.json` / `revoke-license-key.json` |
| | VPS, Dedicated Linux, Managed | `whmcs` add-on |
| cPanel Blocks | Reseller Linux | extra blocks of 10 cPanel accounts (increase only) |
| Operating System | VPS, Dedicated | `alma_8 alma_9 rocky_8 rocky_9 ubuntu_20 ubuntu_22` (VPS), `centos_7 ... debian_10` (Dedicated Linux), `win2k12/16/19` (Windows) |
| Control Panel | VPS, Dedicated | `none`, `cpanel`, `plesk_10_domain|30|unlimited` (VPS), `plesk_web_admin|pro|host_license` (Dedicated) |
| Storage | VPS (HDD plans), Dedicated US, Managed | `none`, `storage_1` ... `storage_5` |
| Bandwidth | Dedicated | **manual** - To-Do item + client ticket |
| Block Storage / SAN Storage (quantity = GB) | VPS, US only | 10 - 500 GB volume attached to the server (`blockstorage/us/add.json`); raising it grows the volume (`upgrade.json`), 0 deletes it, it cannot be made smaller. Suspended, unsuspended, renewed and deleted with the server |

Add-ons sent with the order are recorded; Create and ChangePackage only send the **difference** between the options and what the module already applied (`mod_lbh_addons`). Lowering an option removes only add-ons LogicBoxes marks as **paid**, newest first; anything it cannot identify safely is refused with a message. Removal can be disabled in Settings.

## Pricing

Every mapped product is priced from one LogicBoxes price list, chosen per product (*Price source*):

- **Cost price** (default for new products): `products/reseller-cost-price.json` - what LogicBoxes charges you. The markup is your margin and must be above 0%.
- **Customer price**: `products/customer-price.json` - your selling prices at LogicBoxes, plus markup. Products mapped before 1.1.0 stay on this until you switch them (*Products & Price Sync > Switch to cost price*).

The plan **and every configurable option** use the same list, the same markup and rounding, in **every WHMCS currency** (converted with the WHMCS exchange rates) and for **monthly, quarterly, semi-annual, annual, biennial and triennial** cycles. Plan cycles LogicBoxes does not sell are disabled.

| Option | LogicBoxes price item |
|---|---|
| Dedicated IP (SDH / MDH / Business / Reseller) | the plan's `ssl` price |
| VPS / Dedicated add-ons, Cloud and WordPress CPU / RAM / IP | the `addons` prices (plan or product level) |
| Reseller WHMCS licence | a `whmcs` add-on price (recognised by name) |
| **Reseller (Linux) cPanel blocks** - quantity 0-20, 1 block = 10 cPanel accounts | a cPanel-block add-on price (recognised by name, e.g. `cpanel_blocks`, `additional_cpanel_accounts`), per block per month |
| Cloud dedicated IP, VPS Block Storage per GB, Dedicated bandwidth tiers | the price you enter - the only typed-in prices, because LogicBoxes has no API price for them |
| Operating System | free |

An option LogicBoxes lists no price for is **not created** - nothing is sold for free or at a made-up price. When LogicBoxes lists an add-on under a name the module does not recognise, choose the item on the product's page (*Products & Price Sync > Edit > LogicBoxes price items*); the option is then created and follows that item's price.

**Automatic sync.** The WHMCS cron checks the LogicBoxes price lists every *N* hours (*Settings > Price check interval*, default 6). When any price changed - or a product was never synced - product and option prices are rewritten, options LogicBoxes has started to price are created on existing products, and (where switched on per product) existing services' recurring amounts follow. A recurring amount you changed by hand is never touched. Fetching prices by hand in the Price Catalog applies a change the same way.

**No duplicates.** Options the module manages are tracked, and re-running the generator or the sync only fills gaps. An option of the same name that is already on a product (made by hand, or by an older version) is never doubled:
- it is **adopted** (its sub-option matched, its price then synced) when you run the generator, "Create missing options" or "Apply" - never by the cron on its own;
- it is left alone, with a warning saying why, when its option group is shared with other products (one plan price per group is impossible), or when it is a dropdown whose choices are not `code|Label` for the codes the module sells.

**Never free, never mixed.** A price of 0 in a LogicBoxes list is treated as "not priced": no free option or plan is created from it. A plan with no price in the chosen list is skipped by the generator with a message. Plan-change quotes compare both plans from the same list. Existing services' recurring amounts keep the WHMCS price of options the module does not manage. If applying a detected change fails, it is retried at the next check.

## Upgrades and downgrades (provider-priced)

WHMCS's own upgrade pages prorate from **your** prices, which is not what LogicBoxes charges. With *Settings > Pricing of changes = Provider-priced* (the default) the client changes plan or add-ons from the service page instead:

1. **Quote** - plan change: LogicBoxes' own figure from `modify-pricing.json` (`upgradecost` for the months left); VPS, which has no such call, uses the catalogue difference x months left. Add-ons: monthly price x units added x months left. Your markup, rounding and currency conversion are applied. Removing an add-on costs nothing and gives no refund (LogicBoxes gives none).
2. **Confirm** - a normal WHMCS invoice is raised. Nothing is sent to LogicBoxes yet.
3. **Paid** - the `InvoicePaid` hook changes the order at LogicBoxes, moves the WHMCS service to the new product / options and sets the new recurring amount. A change that costs nothing is applied at once, without an invoice.

One open change per service. A paid change LogicBoxes refuses (for example: reseller funds) is **failed**, shown on the Dashboard and under **Upgrades**, where *Retry* applies it after you fix the cause - never twice. Cancelling the invoice drops the change. In this mode the module removes WHMCS upgrade paths between mapped products and switches off their configurable-option upgrades, so clients cannot use both routes. Choose *WHMCS standard upgrade pages* in Settings to go back to WHMCS prorata. Only products created or mapped by the generator can use the flow (it needs their price mapping).

## Client dashboard

Each service page shows the order (status, plan, location, expiry, IP, panel username, OS), what is included (add-ons, dedicated IPs, Block Storage), the change forms above, and - where the product supports it - these actions. Every action is a POST with a per-service token and an owner check.

| Action | Products | What it does |
|---|---|---|
| Log in to cPanel / WHM / Plesk | SDH, MDH, Business, Cloud (cPanel), Reseller (WHM / Plesk) | Reads the panel login live from `details.json` and posts the panel's own login form from the client's browser. The password is never stored or logged. |
| Manage in control panel | all | `customers/generate-login-token.json` -> your branded LogicBoxes panel. This is the route to Sitebuilder, the VPS / Dedicated server panel and webmail. Needs the Branding URL on the API account. |
| Renew now | all | Raises the next renewal invoice early (due on the service's own due date). Paying it renews in WHMCS and at LogicBoxes like any renewal. |
| Change control panel password | SDH, MDH, Business, Reseller, Cloud | `change-password.json`; 9-16 characters with upper, lower, digit and symbol. |
| SSH on / off | Cloud | `enable-shell-access.json` / `disable-shell-access.json` |
| Refresh | all | Re-reads the order (also done in the background at most every 30 minutes). |
| Server Management Panel | VPS, Dedicated | Box with "Log in to Server Management Panel" (one-click login to the LogicBoxes panel, where the server is started, stopped and restarted) and the login details (IP, username). |
| Server Management Panel without the order panel | VPS, Dedicated | With *Hide the order panel (servers)* on, the WHMCS server signs in to the order panel itself and sends the client straight to the Server Management Panel. Falls back to the normal login if that fails. Check it with the admin **Test Server Panel Login** button. |
| Reboot / Shut down / Start | VPS, Dedicated | The LogicBoxes API has no power call for servers, so each button logs the client into the server panel (same one-click login as "Manage in control panel") where they confirm the action. The request is logged. |

**Name servers and DNS** (hosting products): the page lists the DNS records LogicBoxes says the order needs (`dns-record.json`), checks each against live DNS (OK / Different / Missing), and shows the domain's current name servers next to the ones it should use.

*Settings > Name servers and DNS* can also set them for the client - once after Create, from the client's button, or from the admin **Apply Name Servers** button:

- **Branded** - the name servers on the API account. The LogicBoxes DNS zone is activated and the records are added **first**; the name servers are only switched once the zone is complete. Needs the domain to be registered in the same LogicBoxes account.
- **Hosting server** - the server's own name servers as LogicBoxes reports them.
- Only a domain that is Active in WHMCS under the same client is changed, through its own registrar module. If the domain is still pending at Create, the daily cron tries again for 14 days.

**Customising:** every section has a switch under *Settings > Client area*, plus a free-text notice. "Quick actions", "Change your plan or add-ons" and "Server power" have tick boxes per product type, so you can show them for some products only (hiding the change section also stops plan / add-on orders from the page). Each power button (Reboot, Shut down, Start) and the Refresh button can also be hidden on its own, and the Expires date in the Service overview can be hidden per product type. For layout, copy `servers/{module}/templates/clientarea.tpl` to `servers/{module}/overrides/templates/clientarea.tpl`; for wording, copy a language file to `overrides/lang/`. Both survive updates.

## Import Manager

Brings orders that already exist at LogicBoxes into WHMCS. **Scan** reads every location of the ticked products (Active and Suspended orders) and suggests, per order, the WHMCS client (by the LogicBoxes customer's email), an existing service (same module, same domain) and the product mapped to the order's plan. Nothing is created until you act, and nothing is ever changed at LogicBoxes.

- **Link** attaches the order to an existing service. A service of the same client is linked in bulk; a service that merely has the same name (the customer's email matches no client) is only linked when you confirm it on the row.
- **Import** creates the service with `AddOrder` + `AcceptOrder`: no invoice, no emails, no provisioning call. Next due date = LogicBoxes expiry; status = Active or Suspended as at LogicBoxes. On generator-made products the configurable options are set to what is on the order (OS, control panel, IPs, WHMCS licence, CPU / RAM), and the recurring amount can be set from the price map. The mapped product always wins; the product chosen in the form is only for unmapped plans.
- **Create missing clients** (optional) adds the WHMCS client from the LogicBoxes customer record, without a welcome email.
- Refused on purpose: orders with no expiry date from LogicBoxes (WHMCS would invoice them at once), statuses other than Active / Suspended, and products set to provision "as soon as an order is placed".
- What the module finds on the order is recorded as already applied, so the first Create / upgrade after an import orders or removes nothing by itself. Check the options on a few imported services before relying on them.

## Updates

*Addons > LogicBoxes Hosting > Updates* installs new versions from a release you sign yourself:

1. Once, on your own computer: `php tools/sign-release.php keygen`. Paste the **public** key into the Updates page; keep the secret key offline.
2. Per release: `php tools/sign-release.php sign <package.zip> <version> <https zip url> <secret-key-file> ["notes"]`, upload the zip and the printed manifest JSON to https URLs, and set the manifest URL on the Updates page.
3. The module checks daily (or on *Check now*) and installs on click, or automatically if you tick that.

An update is installed only if the manifest's Ed25519 signature verifies against your public key, the zip's SHA-256 equals the signed value, the package really is the announced version, and every file in it lives under `modules/addons/lbhosting/` or one of the eight `modules/servers/lbh...` folders. `overrides/` folders are never written. The current files are backed up to the WHMCS attachments folder first (last five kept) and restored if copying fails. PHP needs the `sodium` and `zip` extensions and write access to the module folders (on RunCloud: the web app user must own them). Files a new version no longer ships are not deleted.

## Lifecycle

- **Create** is safe to re-run: a linked service is verified and only missing extras are sent; otherwise an existing order for the same domain and customer is adopted instead of ordering twice (another customer's or a deleted order is refused).
- **Suspend / Unsuspend** use `orders/suspend.json` / `orders/unsuspend.json` (every product's own suspend page redirects there).
- **Terminate** suspends at LogicBoxes by default (recoverable); Settings can switch to delete (permanent) or do nothing.
- **Renew** (module `Renew` + `InvoicePaid` fallback) is de-duplicated per billing period; **Renew at Provider** forces one.
- **ChangePackage**: plan change via `modify.json` (SDH, MDH, Business, Reseller: up/down; VPS: upgrade only; Cloud, WordPress and Dedicated: not possible through the API) with the remaining months, then add-on reconciliation.
- **ChangePassword** (hosting products except WordPress): `change-password.json`.
- Admin buttons: **Sync from Provider**, **Link Existing Order** (searches every location of the product), **Renew at Provider**, **Reconcile Add-ons**, **Show Provider Details** (the redacted `details.json`, to see your account's real field names).

## Manual tasks

Items the API cannot provision create a `mod_lbh_manual_tasks` row, a WHMCS **To-Do** item and a **support ticket** for the client (both switchable in Settings). Do the change in the reseller panel, then **Manual Tasks > Mark done** (also completes the To-Do). A changed request cancels the open task and opens a new one; re-running Create never duplicates them.

## Security

API keys encrypted with WHMCS `encrypt()`; API key, panel passwords, licence keys and login tokens are masked in the Module Log; panel passwords are never stored (`details_json` is redacted). Every admin POST is CSRF-checked; all output is escaped.

## Tests (offline, CLI only)

```
php tests/run.php                                              # API client, registry, options, full lifecycle of all 8 families (166 checks)
php tests/run_phase3.php                                       # catalogue, option builder, generator, price sync, recurring (73)
php tests/run_phase4.php                                       # provider-priced changes, invoices, queue, VPS Block Storage, client POST flow (73)
php tests/run_phase5.php                                       # dashboard data, client actions, logins, DNS parsing / live check, name server automation (91)
php tests/run_phase6.php                                       # Import Manager (scan, match, link, import) and the signed updater (107)
php tests/run_pricing.php                                      # cost-price pricing, every currency / cycle, cPanel blocks, automatic sync, no duplicates (60)
php tests/run_locations.php                                    # undocumented Business / WordPress locations found on the account (18)
php tests/render_admin.php                                     # every admin page and POST flow (64)
SMARTY=/path/to/smarty/libs php tests/render_templates.php     # 8 client templates with Smarty 4, security on (89)
```

See `docs/API-REFERENCE.md` for every LogicBoxes call and how certain we are about each one, `docs/GO-LIVE-CHECKLIST.md` for what to verify before selling, and `docs/CHANGELOG.md` for the version history.
