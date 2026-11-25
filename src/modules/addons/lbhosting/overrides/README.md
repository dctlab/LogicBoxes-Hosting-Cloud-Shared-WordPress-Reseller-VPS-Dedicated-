# overrides/

Everything in an `overrides/` folder is yours. Module updates never ship or
overwrite files there, so customisations survive upgrades.

| File | Purpose |
|---|---|
| `modules/addons/lbhosting/overrides/lang/<language>.php` | Return an array of only the strings you want to change, or a full new language (e.g. `french.php`). Merged over `lang/`; English is always the fallback. |
| `modules/servers/<module>/overrides/templates/clientarea.tpl` | Copy `../templates/clientarea.tpl` of the same module (lbhcloud, lbhmultidomain, lbhsingledomain, lbhreseller, lbhvps, lbhdedicated) here to customise that product's client dashboard. The module uses the copy when it exists. |
