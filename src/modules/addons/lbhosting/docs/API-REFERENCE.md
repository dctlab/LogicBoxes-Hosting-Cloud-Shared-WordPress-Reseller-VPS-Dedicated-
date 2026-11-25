# LogicBoxes calls used by LogicBoxes Hosting, and how sure we are

Host `https://httpapi.com/api/` (live) or `https://test.httpapi.com/api/` (test). Every call sends `auth-userid` + `api-key`; POST parameters go in the form body; array parameters are repeated keys. Sources: ResellerClub KB, read 2026-10-01 (answer numbers in brackets).

## Paths

`{path}` = `<family>/<os>/<region>` from `src/Catalog/ProductFamily.php`, e.g. `businesshosting/linux/in`, `singledomainhosting/linux/in`, `cloudsites/linux/us`, `virtualserver/linux/in`, `dedicatedserver/windows/in`, `managedserver/linux/us`.

| Region | Confidence |
|---|---|
| `us`, `in` | VERIFIED - KB examples |
| `uk`, `hk` | OFFICIAL - ResellerClub's own WHMCS modules (EnduranceIndia/whmcs-resellerclub-modules) |
| `tr`, `usca` | **PATTERN** - inferred; product keys are verified (KB 1918) but the path segment is a guess. Shown as "path unverified" on the admin service tab. |

## Calls

| Call | Used for | Confidence |
|---|---|---|
| `{path}/add.json` POST `domain-name customer-id plan-id months invoice-option auto-renew` (+ `enable-ssl` SDH/MDH, `add-dedicated-ip` Reseller, `os` + `addon[]` VPS/Dedicated) | Create | VERIFIED (1699, 1256, 1320, 2647, 2456, 2053, 2764, 2223). Cloud has no `auto-renew` (2647). |
| `{path}/renew.json` POST `order-id months invoice-option auto-renew` (+ `cpanel-blocks` Reseller) | Renew | VERIFIED (1257, 1321, 2648, 2457, 2054). Sending the recorded `cpanel-blocks` on renewal is PATTERN. |
| `{path}/delete.json` POST | Terminate (delete mode) | VERIFIED |
| `{path}/details.json` GET `order-id` | Sync, verify, add-on ids | VERIFIED call; field names VERIFIED for VPS (2461) and Dedicated (2060), PATTERN for hosting/cloud (several spellings accepted) |
| `{path}/orderid.json` GET `domain-name` | idempotent Create, Link Existing Order | VERIFIED |
| `{path}/search.json` GET `no-of-records page-no status[]` (WordPress: REST `.../list.json`) | Import Manager scan | VERIFIED call (1710 and the same page per product; 2955). **Response shape UNVERIFIED** ("Returns the list of ... Orders"): every entry carrying an order id (`orders.orderid`, `orderid`, `entityid`, ...) is taken; `recsindb` is used for paging when present. |
| `customers/details-by-id.json` GET `customer-id` | Import Manager: customer email / name, optional client creation | PATTERN - same fields as `customers/details.json` (874), which is VERIFIED; the by-id call was not read. |
| `orders/suspend.json` POST `order-id reason`, `orders/unsuspend.json` | Suspend / Unsuspend / Terminate | VERIFIED (1077, 1078) |
| `{path}/modify.json` POST `order-id new-plan-id months invoice-option` | Plan change SDH/MDH/Reseller/VPS | VERIFIED (1701, 1258, 1322, 2977). VPS: upgrades only, SSD only. |
| `{path}/modify-pricing.json` GET `order-id new-plan-id months` | Price of a plan change (`upgradecost`) for the client's quote and invoice; `remainingmonths` used as `months` | VERIFIED call (1264, 1711, 1329). The response keys `upgradecost` / `remainingmonths` are read as documented; if either is missing the catalogue difference x months left is used instead. VPS has no such call. |
| `blockstorage/us/add.json` POST `customer-id plan-id months storage-size-gb attach-order-id invoice-option auto-renew` | VPS Block Storage: new volume | VERIFIED (3283). The plan id is not on the Product Keys page: taken from *Settings > Block Storage plan ID*, else from any `plan-details.json` key containing "blockstorage" (**PATTERN**). |
| `blockstorage/us/upgrade.json` POST `order-id storage-size-gb invoice-option` | Grow a volume (FINAL size, not the increment) | VERIFIED (3295) |
| `blockstorage/us/renew.json`, `delete.json`, `details.json` | Renew / remove / read a volume | VERIFIED calls; renewing the volume for the same months as the server is our choice. Suspend / unsuspend use `orders/suspend.json` on the volume order (PATTERN). |
| `modify.json` with the SAME plan + `cpanel-blocks=N` | Reseller Linux extra cPanel blocks | **PATTERN** - `cpanel-blocks` is documented on modify, a same-plan modify is not. Test once. |
| `{path}/enable-ssl.json` / `disable-ssl.json` | SDH/MDH dedicated IP | VERIFIED (1259/1640, 1702/1703). The MDH Linux US disable example omits `/linux/us`; the full path is used. |
| `{path}/add-dedicated-ip.json` / `delete-dedicated-ip.json` `ips[]` | Reseller IPs | VERIFIED (1323, 1332). Which details key lists the IPs is PATTERN (any key like "dedicated ip"). |
| `{path}/generate-license-key.json` / `revoke-license-key.json` `license-key[]` | Reseller WHMCS licence | VERIFIED (1372, 3457). Details key holding the licence is PATTERN. |
| `{path}/addon/add.json` `addon` / `addon/delete.json` `addon-id` | Cloud CPU/RAM, VPS/Dedicated add-ons | VERIFIED (2652/2653, 2458/2459, 2055/2056, 2766/2767, 2225/2226). Cloud details add-on list format is PATTERN. |
| `{path}/change-password.json` `new-passwd` | ChangePassword (hosting) | VERIFIED (1714, 1436, 1438, 2657) |
| `{path}/dns-record.json` GET `order-id` | DNS records the order needs (dashboard, name server automation) | VERIFIED call (1715, 2664, 3567). **Response shape UNVERIFIED**: the KB only says "Returns DNS records of the domain name". `DnsRecords::normalize` accepts lists, numbered maps and maps keyed by record type, with type/host/value under their usual names. |
| `{path}/enable-shell-access.json` / `disable-shell-access.json` | Cloud SSH switch | VERIFIED (2654, 2655). The current state is not documented in `details.json`, so the dashboard offers both buttons. |
| `customers/generate-login-token.json` GET `customer-id ip` -> `https://{branding}/servlet/AutoLoginServlet?userLoginId={token}&role=customer` | "Manage in control panel" | VERIFIED (2942). Whether the token body is quoted is not shown; both forms are accepted. |
| Panel login form: cPanel `:2083/login/` (`user`, `pass`), WHM `:2087/login/`, Plesk `:8443/login_up.php` (`login_name`, `passwd`) | "Log in to cPanel / WHM / Plesk" | **UNVERIFIED on a live order.** Which `details.json` keys hold the panel password and the server host name is PATTERN (`siteadminpassword` etc.; host: `serverhostname`-style keys, else the IP). Panels can be configured to refuse cross-site logins. |
| `domains/orderid.json`, `dns/activate.json`, `dns/manage/search-records.json`, `dns/manage/add-ipv4-record.json` / `add-ipv6-record.json` / `add-mx-record.json` / `add-txt-record.json` | Branded name server mode: zone + records | VERIFIED (763, 830, 1106, 1093, 1094, 1095, 1097). `add-cname-record.json` is PATTERN (page not read); the `search-records` response shape is PATTERN. |
| `products/plan-details.json` (no filter = every product, one call) | Plan loader, connection test, catalogue (names, Dedicated `supported_os`, VPS `isSSDPlan`, specs) | VERIFIED (939, 2061, 2772, 2977). Plan-name key PATTERN (`plan_name`/`planname`/`name`). |
| `products/reseller-cost-price.json` | Cost price catalogue (default price source) | VERIFIED call (1029). Shapes: same as `customer-price.json` for Multi Domain / Reseller hosting (documented); Single Domain uses `key -> key -> bandid -> pricing -> {add, renew, ssl}` with one price per action (read as a price per month for plan id = band id, applied to every tenure the customer list sells - PATTERN, the KB example is from the older one-plan product). Cloud / WordPress / Business / VPS / Dedicated cost shapes are not shown on 1029 and are read like their customer price list (PATTERN). The reseller cPanel-block and WHMCS add-on prices are not documented anywhere: recognised by name, or chosen by the admin per product. |
| `products/customer-price.json` | Price catalogue | VERIFIED (864). Shapes: hosting `key -> planid -> add/renew -> months` + `ssl` (1275, 1330, 1712); Cloud `key -> plans -> planid` (2659); Dedicated `key -> {addons, plans}` (2064). Cloud/VPS `addons` maps and the meaning of the `ssl` / add-on numbers (per month or per year) are PATTERN - see *Settings > Dedicated IP / add-on price basis*. |
| `customers/details.json?username=`, `customers/signup.json` | Find / create the customer | VERIFIED (official modules) |
| `resellers/details.json` | Connection test, branding URL | VERIFIED |

**Not possible through the API** (handled as manual tasks or refused): Cloud dedicated IP (2652 note), Cloud plan change, Dedicated/Managed plan change, Dedicated bandwidth, VPS downgrades, removing cPanel blocks.

## Locations found on the account

Business Hosting is documented for `businesshosting/linux/in` only and WordPress Hosting for `wordpresshostingusa` only (KB Product Keys page 1918 lists no other key for either). Other product keys in the account's `customer-price.json` / `plan-details.json` that match `businesshosting{os}{region}`, `wordpresshosting{region}` or `cloudsites{os}{region}` are added as locations with **PATTERN** paths: `businesshosting/{os}/{region}`, `restapi/product/{key}` and `cloudsites/{os}/{region}`.

## WordPress Hosting (REST product API)

WordPress Hosting is the one product on `https://httpapi.com/restapi/product/wordpresshostingusa/...`. Every parameter goes in the query string, also on POST / PATCH / DELETE. All VERIFIED from KB 2843-2853 and 2955.

| Call | Used for |
|---|---|
| `POST .../order` `domain-name customer-id plan-id noOfMonths invoice-option` | Create (tenure is `noOfMonths`; no `auto-renew`) |
| `PATCH .../order/{id}/tenure/{months}` `invoice-option auto-renew` | Renew |
| `PATCH .../order/{id}/suspend` `reason`, `PATCH .../order/{id}/unsuspend` | Suspend / Unsuspend (NOT `orders/suspend.json`) |
| `DELETE .../order/{id}` | Terminate (delete mode) |
| `GET ...?order-id=` / `?domain-name=` | Details, idempotent Create, Link Existing Order. A list-shaped answer is accepted; deleted orders are skipped. Details field names are PATTERN. |
| `POST .../order/{id}/addon` `addon=cpu_1|ram_1|ipaddress invoice-option`, `DELETE .../order/{id}/addon/{addon-id}` | Add-ons |
| `GET .../list.json` | Import Manager (Phase 6) |

No plan change and no panel-password call are documented for WordPress Hosting.

## Business Hosting

Legacy API, one location: `businesshosting/linux/in` (product key `businesshostinglinuxin`), VERIFIED from KB nodes 3547-3577: add, renew, modify, modify-pricing, details, orderid, search, dns-record, change-domain-name, change-password, delete; suspend redirects to `orders/suspend.json`. `enable-ssl` exists as a parameter on **add and renew only** - there is no `enable-ssl.json` / `disable-ssl.json` page, so a dedicated IP change on a running order is a manual task. The product key is not on the KB "Product Keys" page; it comes from the Business Hosting plan-details page (3553).

## WHMCS contracts used

- `localAPI('CreateInvoice')` with `itemdescription1 / itemamount1 / itemtaxed1` raises the invoice for a change; the `InvoicePaid` and `InvoiceCancelled` hooks (`invoiceid`) apply or drop it. VERIFIED (WHMCS developer docs).
- The change forms post to `clientarea.php?action=productdetails&id=N`, which runs the module's `ClientArea` function again. **UNVERIFIED on a live WHMCS** - if your theme or a security add-on blocks that POST, tell us and the forms move to a custom client-area action.
- Client actions run as module custom functions (`clientarea.php?action=productdetails&id=N&modop=custom&a=ClientLogin`, listed in `ClientAreaAllowedFunctions`), POST only. Same mechanism as the lbsuite modules.
- `localAPI('DomainGetNameservers' / 'DomainUpdateNameservers')` change name servers through the domain's registrar module. VERIFIED (WHMCS developer docs).
- "Renew now": `CreateInvoice`, then the item is retyped to `Hosting` / `relid` = service so WHMCS treats payment as that service's renewal. **PATTERN - confirm on staging** that paying it advances the next due date.
- WHMCS HTML-encodes request input; the new panel password is decoded with `WHMCS\Input\Sanitize::decode` before use. PATTERN.
- Import: `localAPI('AddOrder')` with `noinvoice / noinvoiceemail / noemail`, `AcceptOrder` with `autosetup=false, sendemail=false`, `AddClient` with `noemail, skipvalidation`. VERIFIED (WHMCS developer docs). Config options of an imported service are written to `tblhostingconfigoptions` directly.
- Updater: backups go to `$attachments_dir/lbhosting-backups`. If your WHMCS does not expose that folder to modules, the update refuses to start rather than use a shared temp folder.
- Provider mode deletes rows from `tblproduct_upgrade_products` and sets `tblproducts.configoptionsupgrade = 0` for mapped products (direct table writes, as WHMCS has no API for either).


| Contract | Confidence |
|---|---|
| Server module function names and `'success'` / `'Error: ...'` returns, `ConfigOptions` with `Loader`, `AdminCustomButtonArray`, `AdminServicesTabFields(Save)`, `ClientArea` returning `templatefile` + `vars`, `ChangePassword`, `Renew` | VERIFIED |
| `localAPI('OpenTicket', deptid, clientid, serviceid, subject, message, priority)` | VERIFIED command; response `id` key PATTERN |
| `tbltodolist` (date, title, description, admin, status, duedate) | PATTERN (no API exists for To-Do items) |
| `localAPI('AddProduct', type gid name paytype module autosetup showdomainoptions hidden description configoption1-5)` | VERIFIED command; module settings are also written to `tblproducts` afterwards |
| Config options in `tblproductconfiggroups / tblproductconfigoptions (optiontype 1/3/4) / tblproductconfigoptionssub / tblproductconfiglinks`, prices in `tblpricing` (type `product` / `configoptions`, -1 = cycle disabled), upgrade paths in `tblproduct_upgrade_products` | PATTERN (core schema; no API exists for these) |
| WHMCS calls `{module}_Renew` when a renewal invoice is paid | UNVERIFIED - the `InvoicePaid` hook is the fallback; renewals are de-duplicated |
