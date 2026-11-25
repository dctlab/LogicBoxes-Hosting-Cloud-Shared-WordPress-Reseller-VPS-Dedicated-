# Go-live checklist

The module was built against the LogicBoxes / ResellerClub documentation and tested offline (scripted API responses). No call has been run against a real reseller account. Work through this list on a **test (demo) reseller account** or with one real low-value order per product before selling.

**Show Provider Details** (admin button on any linked service) prints the real `details.json` with secrets removed. Most "field name" items below are settled by reading that output once per product.

## 1. Account and prices
| Check | How | If it is wrong |
|---|---|---|
| Connection test passes and lists plans per product | API Accounts > Save | Whitelist the server IP in the reseller panel |
| Cost prices match the reseller panel | Price Catalog > Cost price, "per month" column | Settings > Plan price basis |
| Single Domain cost prices (band format) give the right monthly cost | Price Catalog > Cost price, Single Domain | Send me the raw JSON shown under the table |
| Reseller cPanel blocks and WHMCS licence are found in the cost list | Products & Price Sync > Edit a Linux reseller product > LogicBoxes price items | Choose the right item from the list there; tell me its name so it is recognised automatically |
| Plan prices match the reseller panel | Price Catalog, "per month" column | Settings > Plan price basis |
| Dedicated IP and add-on prices match | Price Catalog, one `ssl` and one add-on price | Settings > Dedicated IP / add-on price basis (per month or per year) |
| Turkey and US-California locations work | Create or scan one order there | The API path for those locations is inferred; tell me the error text |
| Every Business Hosting and WordPress Hosting location you sell is listed | Fetch prices, then open the Product Generator for each | Add the product key under Settings > Locations; send me the key if it is refused |
| One order in each Business / WordPress location outside India / US | Create a test order | Their API path follows the documented location; tell me the error text |

## 2. One order per product you will sell
| Check | Why it is unproven |
|---|---|
| Create, Suspend, Unsuspend, Renew, Terminate | Documented calls, never run |
| The service tab shows IP, panel username, plan, expiry after Sync | Hosting and Cloud `details.json` field names are inferred |
| WHMCS calls the module on renewal payment (or the InvoicePaid fallback renews once) | Depends on your WHMCS automation settings |
| Add-on changes: dedicated IP on/off, VPS/Dedicated add-ons, Cloud CPU/RAM | Cloud add-on list format is inferred |
| Reseller: extra cPanel blocks | Sent as a same-plan `modify.json`, which is not documented |
| Manual items (Cloud dedicated IP, Dedicated bandwidth, Business dedicated IP change) create a To-Do and a ticket | The To-Do is written to `tbltodolist` directly |
| WordPress Hosting details and add-ons | REST API; details field names inferred |

## 3. Upgrades (provider-priced mode)
| Check | Why it is unproven |
|---|---|
| The quote equals what LogicBoxes charges | `modify-pricing.json` response keys `upgradecost` / `remainingmonths` |
| The change form posts and the invoice opens | The form posts to the product details page; a theme or security add-on could block it |
| Paying the invoice applies the change once | `InvoicePaid` hook |
| VPS Block Storage: add, grow, renew with the server | Plan id auto-detection; renewing for the same months as the server |

## 4. Client dashboard
| Check | Why it is unproven |
|---|---|
| "Log in to cPanel / WHM / Plesk" lands in the panel | Which `details.json` fields hold the panel password and server host name; panels can refuse cross-site logins. Try Settings > Panel host if the host is wrong |
| "Manage in control panel" opens your branded panel | Needs the Branding URL; documented call, never run |
| DNS records are listed and checked | `dns-record.json` response shape is not documented |
| Renew now: paying the invoice moves the next due date | The invoice item is retyped to a hosting renewal |
| Password change, Cloud SSH on/off | Documented calls, never run |
| VPS / Dedicated "Reboot / Shut down / Start" open the server panel where those controls are | Same one-click login as "Manage in control panel"; the client confirms the action there |
| "Log in to Server Management Panel" signs in, then moves to the VPS order page (Server Management Panel) | The tab is moved after "Order page delay" seconds; raise it if clients see "You need to be logged in" |
| VPS service > "Test Server Panel Login" reports "works" and a client click lands on the Server Management Panel without showing the order panel | Server-side sign-in walks undocumented panel pages; a firewall / Cloudflare check on the panel host, or a hand-off tied to the signing-in IP, makes it fall back to the normal login (logged) |
| Settings > Client area display boxes hide "Quick actions", "Change your plan or add-ons" and "Server power" for the unticked product types | New in 1.2.0 |

## 5. Name servers (only if you switch it on)
| Check | Why it is unproven |
|---|---|
| Hosting-server mode finds the server's name servers | Read from `dns-record.json` NS records or `ns1`-style keys in the details |
| Branded mode adds the records, then switches the name servers | `search-records` response shape; `add-cname-record.json` not read |

Test with a domain you own before enabling "After Create".

## 6. Import Manager
| Check | Why it is unproven |
|---|---|
| Scan finds your orders | `search.json` response shape is not documented |
| Customer email is found | `customers/details-by-id.json` not read |
| Options of an imported service match the order | Add-on / dedicated IP field names are inferred. Import three orders, compare, then do the rest |

## 7. Updates
| Check | How |
|---|---|
| PHP has `sodium` and `zip` | Updates page reports it on Check now |
| The web user can write the module folders and the attachments folder | RunCloud: files owned by the web app user |
| A signed test release installs | Sign a copy of this version with a higher version number in `autoload.php` |

## Known limits
- Cloud, WordPress and Dedicated plans cannot be changed through the API; VPS can only be upgraded.
- Block Storage exists for US VPS only.
- The LogicBoxes API has no reboot / shut down / start call for VPS or Dedicated servers, so those buttons open the LogicBoxes server panel instead of acting directly.
- The live DNS check does up to 12 lookups per page view (cached five minutes); switch the DNS section off if your resolver is slow.
- Client action throttling is per login session.
- The updater does not delete files a new version no longer ships.
- A signed update manifest has no expiry: whoever controls the download host can keep serving an older signed release, but never an unsigned or downgraded one.
