# Changelog

## 1.3.2 (2026-11-05)
- **Clear the Price Catalog:** Price Catalog has a "Clear catalog" button - only the product type on screen, or all product types of the API account - with "fetch again" ticked by default. Removes stored LogicBoxes prices and plans (and, for all types, the raw price JSON), e.g. plans LogicBoxes no longer sells. WHMCS products and their prices are not changed: price sync skips any product whose prices are not stored until they are fetched again.

## 1.3.1 (2026-11-02)
- **VPS OS and extra IPs:** LogicBoxes returns the OS and the add-ons of a VPS inside `installed_os` (seen on a live India VPS). The OS now shows in the Service overview, extra IPs bought as add-ons show as "Additional IP addresses", and the Import Manager sees both.

## 1.3.0 (2026-11-02)
- **Server Management Panel without the order panel (VPS / Dedicated):** "Log in to Server Management Panel", the power buttons and "Manage in control panel" now open the Server Management Panel (e.g. panel.webhostbox.net) directly. The WHMCS server signs in to the branded order panel itself, opens the order's page and passes only the hand-off to the Server Management Panel to the client, so the client never sees the order panel. If any step fails, the normal panel login is used and the reason is logged.
- Settings > Client area: **Hide the order panel (servers)** (on by default) and **Server sign-in IP** (public IP of the WHMCS server; empty = detect).
- Admin service page (VPS / Dedicated): **Test Server Panel Login** button shows each step of that sign-in and where the client would be sent.

## 1.2.7 (2026-11-02)
- **Fix - client page not changing after an update:** WHMCS only rebuilds a template whose file date is newer than its compiled copy, and files unpacked from the zip can keep an older date, so the previous page (without the Server Management Panel box) kept showing. The first client page view after an update now re-dates the eight module templates once, so WHMCS rebuilds them.

## 1.2.6 (2026-11-02)
- **Fix - "You need to be logged in" after the panel login:** LogicBoxes followed the order-page address before its login had finished. The new tab now signs in first and is moved to the order's page (`ManageServiceServlet`, which opens the Server Management Panel) a few seconds later. Delay: Settings > Client area > Order page delay (default 6 s, 2-30).

## 1.2.5 (2026-11-02)
- **Panel logins open the order's own page:** "Log in to Server Management Panel", "Manage in control panel" and the power buttons now ask the one-click login to land on `ManageServiceServlet?orderid=...&productkey=...&servicename=...` (for VPS / Dedicated, where the Server Management Panel is) instead of the panel home page. Switch: Settings > Client area > Open the order's own page (on by default).

## 1.2.4 (2026-11-02)
- **Server Management Panel box always shows when ticked:** Settings > Client area > Show "Server Management Panel" now shows the box (description and login details) even with the power buttons and order panel login switched off; those switches only control its buttons.

## 1.2.3 (2026-11-02)
- **Server Management Panel box (VPS / Dedicated):** replaces the "Server power" box with the LogicBoxes wording: what the panel is for, a "Log in to Server Management Panel" button (one-click login to the LogicBoxes panel), Reboot / Shut down / Start, and Login Details (IP, username, credentials note). Wording can be changed in the language files.
- **Admin service tab - LogicBoxes details:** shows the order details last read from LogicBoxes, passwords removed, to see exactly what LogicBoxes returns for a service.

## 1.2.2 (2026-11-02)
- **Hide the Expires date:** Settings > Client area > Show "Expires" date has a tick box per product type; untick a type to remove the Expires row from its Service overview.

## 1.2.1 (2026-11-02)
- **Show or hide each button:** Settings > Client area now has tick boxes for Reboot, Shut down and Start (with none ticked the Server power box is hidden) and a switch for the Refresh button. A hidden button is also refused if posted directly.

## 1.2.0 (2026-11-02)
- **Server power for VPS and Dedicated:** a "Server power" card with Reboot, Shut down and Start. The LogicBoxes API has no power call for servers, so each button logs the client into the LogicBoxes server panel in a new tab (one-click login, same as "Manage in control panel"), where they confirm the action. Each request is logged; suspended servers get no buttons. Switch: Settings > Client area > Server power buttons.
- **Display options per product type:** Settings > Client area has tick boxes for which product types show "Quick actions", "Change your plan or add-ons" and "Server power". A hidden plan-change section also stops plan / add-on orders from that page.

## 1.1.0 (2026-10-25)
- **Cost price as source:** products and configurable options are priced from `reseller-cost-price.json` plus your markup (default for new products; existing ones can be switched in one step). Typed-in option prices are gone except for the three items LogicBoxes has no price for.
- **Automatic sync on price change:** the cron checks every N hours (setting) and applies any change at once - products, options in every currency and cycle, missing options, and (per product) existing services.
- **No duplicates:** same-name options already on a product are adopted; re-runs only fill gaps.
- **Linux reseller cPanel blocks** priced per block of 10 accounts from the LogicBoxes list, with the recurring price included in the service amount.
- Per-product choice of the LogicBoxes price item when LogicBoxes uses an unexpected name.
- Safety: 0 prices never create free options or plans; options in shared option groups are not adopted; recurring amounts keep hand-made options; a failed apply is retried; plan-change quotes use one price list; the generator keeps an existing product's price source and skips plans with no price in the chosen list.

## 1.0.1 (2026-10-02)
- **Business Hosting and WordPress Hosting locations:** locations beyond the one LogicBoxes documents for each are found on the reseller account (price / plan list) and added automatically; extra product keys can be typed in under Settings > Locations.

## 1.0.0 (2026-10-02)
- **Import Manager:** scan Active / Suspended orders of all eight products, match to clients / services / products, link or import without invoices, emails or provisioning; optional client creation; add-on baseline recorded.
- **Signed self-updater:** Ed25519-signed manifest, SHA-256-bound package, path-checked files, overrides never touched, backup and rollback, daily check, optional automatic install. `tools/sign-release.php` for key generation and signing.
- Go-live checklist and final documentation.

## 0.5.0
- Client dashboard: cPanel / WHM / Plesk login, order-panel login, Renew now, panel password change, Cloud SSH switch, add-ons and IPs, live DNS check.
- Name server automation (branded or hosting-server name servers), admin "Apply Name Servers", daily retry.
- Section switches and dashboard notice in Settings; English and Spanish strings.

## 0.4.0
- Provider-priced upgrades / downgrades (plans and add-ons) with their own WHMCS invoices; Upgrades queue with retry.
- VPS Block Storage (SAN) for the US location.

## 0.3.1
- WordPress Hosting (REST API) and Business Hosting modules.

## 0.3.0
- Price catalogue, product and configurable-option generator, daily price sync, recurring-amount sync.

## 0.2.0
- Core addon, multi-account support, provisioning lifecycle for Cloud, Multi Domain, Single Domain, Reseller, VPS and Dedicated / Managed servers; add-on reconciliation; manual tasks (To-Do + ticket); admin tools.
