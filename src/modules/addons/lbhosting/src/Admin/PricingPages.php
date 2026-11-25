<?php

declare(strict_types=1);

namespace LbHosting\Admin;

use Illuminate\Database\Capsule\Manager as Capsule;
use LbHosting\Catalog\ProductFamily;
use LbHosting\Pricing\PriceCalculator;
use LbHosting\Pricing\PriceCatalog;
use LbHosting\Pricing\PricePlanner;
use LbHosting\Pricing\PriceSync;
use LbHosting\Products\OptionBuilder;
use LbHosting\Products\ProductGenerator;
use LbHosting\Products\ProductMap;
use LbHosting\Support\Logger;
use LbHosting\Support\Settings;

/**
 * Admin pages: Price Catalog, Product Generator, Products & Price Sync.
 * POSTs arrive here only after AddonController has verified the CSRF token.
 */
final class PricingPages
{
    public const DOS = ['refresh_catalog', 'clear_catalog', 'generate', 'map_existing', 'save_map', 'save_option_prices', 'build_options', 'unmap', 'upgrade_paths', 'sync_apply', 'sync_refresh_preview', 'save_bindings', 'switch_source'];

    private AddonController $ui;

    private ProductMap $maps;

    public function __construct(AddonController $ui)
    {
        $this->ui = $ui;
        $this->maps = new ProductMap();
    }

    private function family(string $source = 'get'): string
    {
        $f = (string) (($source === 'post' ? $_POST : $_GET)['family'] ?? ProductFamily::SINGLEDOMAIN);

        return ProductFamily::isFamily($f) ? $f : ProductFamily::SINGLEDOMAIN;
    }

    // ------------------------------------------------------------------ POST

    public function handle(string $do): void
    {
        switch ($do) {
            case 'refresh_catalog':
                $acc = $this->ui->accounts()->findActive((int) ($_POST['account_id'] ?? 0));
                if (!$acc) {
                    throw new \InvalidArgumentException('Choose an active API account.');
                }
                $res = (new PriceCatalog())->refresh($acc);
                $this->ui->addMessage($res['cost'] || $res['prices'] ? 'success' : 'warning', sprintf('Stored %d cost price rows, %d customer price rows and %d plans for "%s".', $res['cost'], $res['prices'], $res['plans'], $acc->name));
                $this->autoApplyAfterRefresh($res['changed']);
                if (!empty($res['discovered'])) {
                    $this->ui->addMessage('info', 'New location(s) found on this account and added: ' . implode(', ', array_map(static fn ($k) => ProductFamily::label((string) ProductFamily::forKey($k)) . ' ' . ProductFamily::keyLabel((string) ProductFamily::forKey($k), $k) . ' (' . $k . ')', $res['discovered'])) . '. LogicBoxes does not document these; their API path follows the documented location of the same product.');
                }
                foreach ($res['errors'] as $what => $err) {
                    $this->ui->addMessage('danger', ucfirst($what) . ': ' . $err);
                }
                $this->ui->redirect('catalog', ['account' => $acc->id]);
                break;

            case 'clear_catalog':
                $accId = (int) ($_POST['account_id'] ?? 0);
                $names = $this->ui->accounts()->options();
                if (!isset($names[$accId])) {
                    throw new \InvalidArgumentException('Choose an API account.');
                }
                $family = $this->family('post');
                $all = ($_POST['scope'] ?? '') === 'all';
                $n = PriceCatalog::clear($accId, $all ? null : $family);
                $what = $all ? 'all product types' : ProductFamily::label($family);
                Logger::log('prices', sprintf('Price catalog cleared for "%s" (%s): %d price rows, %d plans', $names[$accId], $what, $n['prices'], $n['plans']));
                $this->ui->addMessage('success', sprintf('Cleared %d price rows and %d plans (%s) for "%s". Your WHMCS products and their prices are not changed; price sync skips them until prices are fetched again.', $n['prices'], $n['plans'], $what, $names[$accId]));
                if (!empty($_POST['refetch'])) {
                    $acc = $this->ui->accounts()->findActive($accId);
                    if (!$acc) {
                        $this->ui->addMessage('warning', 'The account is disabled, so prices were not fetched again.');
                    } else {
                        $res = (new PriceCatalog())->refresh($acc);
                        $this->ui->addMessage($res['cost'] || $res['prices'] ? 'success' : 'warning', sprintf('Fetched again: %d cost price rows, %d customer price rows and %d plans.', $res['cost'], $res['prices'], $res['plans']));
                        foreach ($res['errors'] as $w => $err) {
                            $this->ui->addMessage('danger', ucfirst($w) . ': ' . $err);
                        }
                    }
                }
                $this->ui->redirect('catalog', ['account' => $accId, 'family' => $family]);
                break;

            case 'generate':
                $family = $this->family('post');
                $accountId = (int) ($_POST['account_id'] ?? 0);
                if (!$this->ui->accounts()->findActive($accountId)) {
                    throw new \InvalidArgumentException('Choose an active API account.');
                }
                $known = [];
                foreach (PriceCatalog::plans($accountId, $family) as $p) {
                    $known[$p['product_key'] . ':' . $p['plan_id']] = $p;
                }
                $plans = [];
                foreach ((array) ($_POST['plans'] ?? []) as $sel) {
                    if (isset($known[(string) $sel])) {
                        $plans[] = $known[(string) $sel];
                    }
                }
                $opts = $_POST;
                $opts['manual'] = array_map('strval', (array) ($_POST['manual'] ?? []));
                $report = (new ProductGenerator($this->maps))->generate($family, $accountId, $plans, $opts);
                $this->ui->addMessage('success', sprintf('%d product(s) created, %d updated, %d configurable option(s) created, %d price value(s) written, %d upgrade path(s) added.', count($report['created']), count($report['updated']), $report['options'], $report['prices'], $report['upgrades']));
                foreach ($report['messages'] as $w) {
                    $this->ui->addMessage('warning', $w);
                }
                if (!empty($_POST['hidden']) && $report['created']) {
                    $this->ui->addMessage('info', 'New products were created hidden - review them under Setup > Products/Services, then unhide.');
                }
                $this->ui->redirect('products');
                break;

            case 'map_existing':
                $opts = $_POST;
                $opts['manual'] = array_map('strval', (array) ($_POST['manual'] ?? []));
                (new ProductGenerator($this->maps))->mapExisting((int) ($_POST['product_id'] ?? 0), $opts);
                $this->ui->addMessage('success', 'Product mapped. Review the pending price changes, then apply' . (Settings::bool('price_sync_enabled') ? ' - the automatic sync applies them at its next check' : '') . '.');
                $this->ui->redirect('products');
                break;

            case 'save_map':
                $id = (int) ($_POST['id'] ?? 0);
                if (!$this->maps->find($id)) {
                    throw new \InvalidArgumentException('Mapping not found.');
                }
                $this->maps->save($id, ProductMap::validateSettings($_POST));
                $this->ui->addMessage('success', 'Pricing settings saved. Review the pending changes, then apply.');
                $this->ui->redirect('map', ['id' => $id]);
                break;

            case 'save_option_prices':
                $id = (int) ($_POST['id'] ?? 0);
                $n = 0;
                foreach ((array) ($_POST['manual_price'] ?? []) as $optId => $value) {
                    $value = trim(str_replace(',', '.', (string) $value));
                    if ($value !== '' && (!is_numeric($value) || (float) $value < 0 || (float) $value > 1000000)) {
                        throw new \InvalidArgumentException('Manual prices must be positive numbers.');
                    }
                    $n += Capsule::table('mod_lbh_option_map')->where('id', (int) $optId)->where('map_id', $id)->where('price_item', 'manual')
                        ->update(['manual_price' => $value === '' ? null : round((float) $value, 4)]);
                }
                $this->ui->addMessage('success', $n . ' manual price(s) saved. Review the pending changes, then apply.');
                $this->ui->redirect('map', ['id' => $id]);
                break;

            case 'build_options':
                $id = (int) ($_POST['id'] ?? 0);
                $m = $this->maps->find($id);
                if (!$m) {
                    throw new \InvalidArgumentException('Mapping not found.');
                }
                $res = OptionBuilder::specs((string) $m->family, (string) $m->product_key, (int) $m->account_id, (int) $m->plan_id, array_map('strval', (array) ($_POST['manual'] ?? [])) + ['bandwidth_tiers' => (string) ($_POST['bandwidth_tiers'] ?? '')], PriceCatalog::source($m), ProductMap::bindings($m));
                $n = OptionBuilder::apply($m, ProductFamily::label((string) $m->family) . ' - ' . $m->plan_name . ' (' . ProductFamily::keyLabel((string) $m->family, (string) $m->product_key) . ')', $res['specs']);
                $this->ui->addMessage('success', $n . ' configurable option(s) created; existing ones were completed.');
                foreach ($res['skipped'] as $s) {
                    $this->ui->addMessage('warning', 'Skipped: ' . $s);
                }
                $this->ui->redirect('map', ['id' => $id]);
                break;

            case 'unmap':
                $this->maps->delete((int) ($_POST['id'] ?? 0));
                $this->ui->addMessage('success', 'Product unmapped. The WHMCS product, its options and prices were left as they are.');
                break;

            case 'upgrade_paths':
                $n = 0;
                foreach (ProductFamily::all() as $family) {
                    foreach (array_unique(array_map(static fn ($m) => (int) $m->account_id, $this->maps->all($family))) as $accId) {
                        $n += ProductGenerator::setUpgradePaths($family, $accId);
                    }
                }
                $this->ui->addMessage('success', $n . ' upgrade path(s) added.');
                break;

            case 'sync_apply':
                $r = (new PriceSync())->syncAll('manual');
                $this->ui->addMessage('success', $r['written'] . ' price change(s) applied' . ($r['options'] ? ', ' . $r['options'] . ' missing option(s) created' : '') . '.');
                foreach ($r['warnings'] as $w) {
                    $this->ui->addMessage('warning', $w);
                }
                $this->ui->redirect('products');
                break;

            case 'sync_refresh_preview':
                $changed = false;
                foreach (array_unique(array_map(static fn ($m) => (int) $m->account_id, $this->maps->all())) as $accId) {
                    $acc = $this->ui->accounts()->findActive($accId);
                    if ($acc) {
                        $res = (new PriceCatalog())->refresh($acc);
                        $changed = $changed || $res['changed'];
                        foreach ($res['errors'] as $what => $err) {
                            $this->ui->addMessage('danger', $acc->name . ' - ' . $what . ': ' . $err);
                        }
                    }
                }
                if (!$this->autoApplyAfterRefresh($changed)) {
                    $preview = (new PriceSync())->preview(null, false);
                    $this->ui->addMessage('info', 'Catalogue refreshed' . ($changed ? ' (prices changed)' : ' (no price changed)') . '. ' . count($preview['changes']) . ' change(s) pending - review below.');
                }
                $this->ui->redirect('products');
                break;

            case 'save_bindings':
                $id = (int) ($_POST['id'] ?? 0);
                $m = $this->maps->find($id);
                if (!$m) {
                    throw new \InvalidArgumentException('Mapping not found.');
                }
                $extras = PriceCatalog::extras((int) $m->account_id, (string) $m->product_key, (int) $m->plan_id, PriceCatalog::source($m));
                $bindings = [];
                foreach ((array) ($_POST['bind'] ?? []) as $logical => $item) {
                    $logical = (string) $logical;
                    $item = (string) $item;
                    if (!isset(OptionBuilder::PRICE_ITEMS[$logical]) || $item === '') {
                        continue;
                    }
                    if (!isset($extras[$item])) {
                        throw new \InvalidArgumentException('"' . $item . '" is not in this plan\'s LogicBoxes price list.');
                    }
                    $bindings[$logical] = $item;
                }
                $this->maps->save($id, ['price_items' => $bindings ? json_encode($bindings) : null]);
                $r = OptionBuilder::ensure($this->maps->find($id));
                $this->ui->addMessage('success', 'Price items saved. ' . $r['created'] . ' option(s) created; existing options now follow the chosen items. Review the pending changes, then apply.');
                foreach ($r['skipped'] as $s) {
                    $this->ui->addMessage('warning', 'Skipped: ' . $s);
                }
                $this->ui->redirect('map', ['id' => $id]);
                break;

            case 'switch_source':
                $markup = (float) str_replace(',', '.', (string) ($_POST['markup_pct'] ?? '0'));
                if ($markup <= 0 || $markup > 1000) {
                    throw new \InvalidArgumentException('Enter the markup (your margin over cost) - above 0%.');
                }
                $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
                $n = 0;
                $noCost = [];
                foreach ($this->maps->all() as $m) {
                    if ((string) ($m->price_source ?? 'sell') === 'cost' || ($ids && !in_array((int) $m->id, $ids, true))) {
                        continue;
                    }
                    if (!PriceCatalog::hasPlanPrice((int) $m->account_id, (string) $m->product_key, (int) $m->plan_id, 'cost')) {
                        $noCost[] = '#' . $m->product_id . ' ' . $m->plan_name;
                        continue; // never switch a product to a list that has no price for it
                    }
                    $this->maps->save((int) $m->id, ['price_source' => 'cost', 'markup_pct' => round($markup, 2)]);
                    OptionBuilder::ensure($this->maps->find((int) $m->id));
                    $n++;
                }
                $this->ui->addMessage('success', $n . ' product(s) now priced from the LogicBoxes cost price + ' . $markup . '%. Nothing has been written to WHMCS yet: review the pending changes below, then apply' . (Settings::bool('price_sync_enabled') ? ' (the automatic sync applies them at its next check)' : '') . '.');
                if ($noCost) {
                    $this->ui->addMessage('warning', 'Left on the customer price because the stored cost list has no price for them: ' . implode(', ', $noCost) . '. Fetch prices in the Price Catalog first.');
                }
                $this->ui->redirect('products');
                break;

            default:
                throw new \InvalidArgumentException('Unknown action.');
        }
    }

    // ----------------------------------------------------------------- pages

    public function pageCatalog(): string
    {
        $accounts = $this->ui->accounts()->options();
        if (!$accounts) {
            return '<div class="alert alert-info">Add an API account first.</div>';
        }
        $accId = (int) ($_GET['account'] ?? array_key_first($accounts));
        $family = $this->family();
        $kind = ($_GET['kind'] ?? 'cost') === 'sell' ? 'sell' : 'cost';
        $h = $this->familyPicker('catalog', $family, $accId) . ' ' . $this->accountPicker('catalog', $accounts, $accId, $family);
        $h .= ' <div class="btn-group" style="margin-left:8px">';
        foreach (['cost' => 'Cost price', 'sell' => 'Customer price'] as $k => $label) {
            $h .= '<a class="btn btn-sm ' . ($k === $kind ? 'btn-primary' : 'btn-default') . '" href="' . $this->ui->e($this->ui->url('catalog', ['family' => $family, 'account' => $accId, 'kind' => $k])) . '">' . $label . '</a>';
        }
        $h .= '</div>';
        $h .= '<form method="post" style="display:inline-block;margin-left:8px">' . $this->ui->tokenField() . '<input type="hidden" name="do" value="refresh_catalog"><input type="hidden" name="account_id" value="' . $accId . '"><button class="btn btn-primary btn-sm">Fetch prices and plans from LogicBoxes</button></form>';

        $h .= '<form method="post" style="display:inline-block;margin-left:8px" onsubmit="return confirm(\'Clear the stored LogicBoxes prices and plans? WHMCS products and their prices are not changed.\');">' . $this->ui->tokenField()
            . '<input type="hidden" name="do" value="clear_catalog"><input type="hidden" name="account_id" value="' . $accId . '"><input type="hidden" name="family" value="' . $this->ui->e($family) . '">'
            . '<select name="scope" class="form-control input-sm" style="display:inline-block;width:auto"><option value="family">Only ' . $this->ui->e(ProductFamily::label($family)) . '</option><option value="all">All product types</option></select> '
            . '<label style="font-weight:normal;margin:0 6px"><input type="checkbox" name="refetch" value="1" checked> fetch again</label>'
            . '<button class="btn btn-danger btn-sm">Clear catalog</button></form>';

        $fetched = Capsule::table('mod_lbh_price_snapshots')->where('account_id', $accId)->where('kind', $kind)->value('fetched_at');
        $h .= '<p class="text-muted" style="margin-top:10px">' . ($fetched ? 'Last fetched ' . $this->ui->e($fetched) . '.' : 'Not fetched yet.')
            . ($kind === 'cost'
                ? ' These are your LogicBoxes <strong>cost</strong> prices (<code>reseller-cost-price.json</code>): what LogicBoxes charges you, in the account\'s currency. Products priced from cost add their markup on top.'
                : ' These are your LogicBoxes <strong>customer (selling)</strong> prices (<code>customer-price.json</code>), before markup.')
            . ' Plan price basis: <code>' . $this->ui->e(Settings::get('price_basis')) . '</code>; dedicated IP / add-on prices are read as <code>' . $this->ui->e(Settings::get('extras_basis')) . '</code> (Settings).</p>';
        if (Settings::get('price_action') === 'add') {
            $h .= '<div class="alert alert-warning"><strong>Selling at the new-order ("add") price.</strong> It is often a first-term promo; WHMCS bills the same amount at every renewal. Consider <strong>Settings &gt; Sell at: Renewal price</strong>.</div>';
        }
        $plans = PriceCatalog::plans($accId, $family);
        if (!$plans) {
            return $h . '<p>No ' . $this->ui->e(ProductFamily::label($family)) . ' plans stored for this account.</p>';
        }
        $h .= '<table class="table table-condensed table-bordered"><thead><tr><th>Location</th><th>Plan</th><th>Basis</th><th>Cycle</th><th>Per cycle</th><th>Per month</th><th>Dedicated IP / add-ons</th></tr></thead><tbody>';
        foreach ($plans as $p) {
            $calc = PriceCatalog::calculator($accId, $p['product_key'], $p['plan_id'], $kind);
            $extras = [];
            foreach (Capsule::table('mod_lbh_prices')->where('account_id', $accId)->where('product_key', $p['product_key'])->whereIn('plan_id', [$p['plan_id'], 0])->where('kind', $kind)->where('item', '!=', 'plan')->get() as $x) {
                $extras[] = $this->ui->e(str_replace('addon:', '', (string) $x->item)) . ' ' . number_format((float) $x->price, 2) . ((int) $x->plan_id === 0 ? '*' : '');
            }
            $tenures = $calc->tenures() ?: [0];
            $first = true;
            foreach ($tenures as $m) {
                $h .= '<tr>'
                    . ($first ? '<td rowspan="' . count($tenures) . '">' . $this->ui->e(ProductFamily::keyLabel($family, $p['product_key'])) . '<br><code>' . $this->ui->e($p['product_key']) . '</code></td><td rowspan="' . count($tenures) . '">' . $this->ui->e($p['plan_name'] . ' #' . $p['plan_id']) . '</td><td rowspan="' . count($tenures) . '">' . $this->ui->e($calc->basis()) . '</td>' : '')
                    . ($m === 0 ? '<td colspan="3" class="text-muted">no plan price</td>' : '<td>' . $this->ui->e(PriceCalculator::CYCLES[$m]) . '</td><td>' . number_format((float) $calc->cyclePrice($m), 2) . '</td><td>' . number_format((float) $calc->perMonth($m), 2) . '</td>')
                    . ($first ? '<td rowspan="' . count($tenures) . '">' . ($extras ? implode('<br>', $extras) : '<span class="text-muted">-</span>') . '</td>' : '')
                    . '</tr>';
                $first = false;
            }
        }
        $h .= '</tbody></table><p class="text-muted small">* product-level add-on price (applies to every plan of that location).</p>';
        $raw = (string) Capsule::table('mod_lbh_price_snapshots')->where('account_id', $accId)->where('kind', $kind)->value('payload');
        if ($raw !== '') {
            $h .= '<details><summary>Raw price JSON (this module\'s product keys only)</summary><pre style="max-height:420px;overflow:auto">' . $this->ui->e(mb_substr($raw, 0, 200000)) . '</pre></details>';
        }

        return $h;
    }

    public function pageGenerator(): string
    {
        $accounts = $this->ui->accounts()->options();
        if (!$accounts) {
            return '<div class="alert alert-info">Add an API account first.</div>';
        }
        $family = $this->family();
        $accId = (int) ($_GET['account'] ?? array_key_first($accounts));
        $h = $this->familyPicker('generator', $family, $accId) . ' ' . $this->accountPicker('generator', $accounts, $accId, $family);

        $plans = array_values(array_filter(PriceCatalog::plans($accId, $family), static fn ($p) => $p['priced']));
        if (!$plans) {
            return $h . '<div class="alert alert-warning" style="margin-top:10px">No ' . $this->ui->e(ProductFamily::label($family)) . ' prices stored for this account yet. Open <a href="' . $this->ui->e($this->ui->url('catalog', ['account' => $accId, 'family' => $family])) . '">Price Catalog</a> and fetch prices first.</div>';
        }

        $h .= '<form method="post" class="form-horizontal" style="max-width:1000px;margin-top:10px">' . $this->ui->tokenField() . '<input type="hidden" name="do" value="generate"><input type="hidden" name="family" value="' . $this->ui->e($family) . '"><input type="hidden" name="account_id" value="' . $accId . '">';
        $h .= '<h3>1. Plans</h3><p class="text-muted">One WHMCS product per plan and location. Plans already generated are updated, never duplicated.</p><table class="table table-condensed"><thead><tr><th></th><th>Location</th><th>Plan</th><th>Status</th></tr></thead><tbody>';
        foreach ($plans as $p) {
            $value = $p['product_key'] . ':' . $p['plan_id'];
            $map = $this->maps->forPlan($accId, $p['product_key'], $p['plan_id']);
            $conf = (string) (ProductFamily::keyMeta($family, $p['product_key'])['conf'] ?? '');
            $h .= '<tr><td><input type="checkbox" name="plans[]" value="' . $this->ui->e($value) . '"' . ($map ? '' : ' checked') . '></td>'
                . '<td>' . $this->ui->e(ProductFamily::keyLabel($family, $p['product_key'])) . ($conf === 'PATTERN' ? ' <span class="label label-warning badge bg-warning">path unverified</span>' : '') . '</td>'
                . '<td>' . $this->ui->e($p['plan_name'] . ' #' . $p['plan_id']) . (PriceCatalog::hasPlanPrice($accId, $p['product_key'], (int) $p['plan_id'], 'cost') ? '' : ' <span class="label label-default badge bg-secondary" title="Not in the stored cost price list: skipped when the price source is the cost price">no cost price</span>') . '</td>'
                . '<td>' . ($map ? 'Product #' . (int) $map->product_id . ' (will be updated; keeps its price source)' : 'New') . '</td></tr>';
        }
        $h .= '</tbody></table>';

        $groups = ['0' => '- Create a new group -'];
        foreach (Capsule::table('tblproductgroups')->orderBy('order')->get() as $g) {
            $groups[(string) $g->id] = (string) $g->name;
        }
        $h .= '<h3>2. Products</h3>';
        $h .= $this->ui->row('Product group', $this->select('group_id', $groups, '0'));
        $h .= $this->ui->row('New group name', '<input name="new_group" class="form-control" value="' . $this->ui->e(ProductFamily::label($family)) . '">');
        $h .= $this->ui->row('Product name', '<input name="name_pattern" class="form-control" value="{plan} ({location})">', 'Placeholders: {plan} {location} {product}.');
        $h .= $this->ui->row('Create hidden', '<label><input type="checkbox" name="hidden" value="1" checked> Create new products hidden so you can review them first</label>');
        if (ProductFamily::can($family, 'plan_change')) {
            $h .= \LbHosting\Service\UpgradeService::enabled()
                ? $this->ui->row('Plan changes', '<p class="form-control-static">Clients change plan from their dashboard, priced the way LogicBoxes charges (Settings &gt; Upgrades). No WHMCS upgrade paths are created.</p>')
                : $this->ui->row('Upgrade paths', '<label><input type="checkbox" name="upgrade_paths" value="1" checked> Let clients change to other plans of the same location' . (ProductFamily::can($family, 'downgrade') ? '' : ' (higher plans only - LogicBoxes refuses VPS downgrades)') . '</label>');
        }

        $h .= '<h3>3. Configurable options</h3>';
        $h .= $this->ui->row('Create options', '<label><input type="checkbox" name="create_options" value="1" checked> Create the add-on options for each product (' . $this->ui->e($this->optionSummary($family)) . ')</label>', 'Each option is priced from the same LogicBoxes price list as the plan (cost price + markup by default), in every currency and billing cycle. An option LogicBoxes does not list a price for is not created - nothing is sold for free or at a made-up price. If LogicBoxes lists it under an unexpected name, bind it on the product\'s page afterwards.');
        foreach ($this->manualFields($family) as $k => [$label, $help]) {
            $h .= $this->ui->row($label, '<input name="manual[' . $this->ui->e($k) . ']" class="form-control" style="max-width:160px" placeholder="per month">', $help);
        }
        if ($family === ProductFamily::DEDICATED) {
            $h .= $this->ui->row('Bandwidth tiers', '<textarea name="bandwidth_tiers" class="form-control" rows="3" placeholder="10TB|10 TB extra = 25&#10;20TB|20 TB extra = 45"></textarea>', 'Optional. One tier per line: code|Label = monthly price. Fulfilled by hand (To-Do + ticket); the API has no bandwidth add-on.');
        }
        $h .= '<h3>4. Pricing</h3>';
        $h .= $this->settingsFields((object) ['price_source' => 'cost', 'markup_pct' => 30, 'rounding' => '2dp', 'source_currency_id' => $this->defaultCurrency(), 'sync_prices' => 1, 'update_recurring' => 0]);
        $h .= '<div class="form-group"><div class="col-sm-offset-3 col-sm-9"><button class="btn btn-primary" type="submit">Generate products and import prices</button></div></div></form>';

        return $h;
    }

    public function pageProducts(): string
    {
        $maps = $this->maps->all();
        $h = '';
        if (!$maps) {
            $h .= '<div class="alert alert-info">No products are mapped yet. Use the <a href="' . $this->ui->e($this->ui->url('generator')) . '">Product Generator</a>, or map an existing product below.</div>';
        } else {
            $h .= '<table class="table table-striped table-condensed"><thead><tr><th>Product</th><th>Family</th><th>Location</th><th>Plan</th><th>Options</th><th>Price source</th><th>Pricing</th><th>Sync</th><th>Recurring</th><th>Last sync</th><th></th></tr></thead><tbody>';
            $productNames = Capsule::table('tblproducts')->whereIn('id', array_map(static fn ($m) => (int) $m->product_id, $maps))->pluck('name', 'id')->all();
            foreach ($maps as $m) {
                $h .= '<tr><td><a href="configproducts.php?action=edit&id=' . (int) $m->product_id . '">' . $this->ui->e($productNames[$m->product_id] ?? ('#' . $m->product_id . ' (deleted)')) . '</a></td>'
                    . '<td>' . $this->ui->e(ProductFamily::label((string) $m->family)) . '</td>'
                    . '<td>' . $this->ui->e(ProductFamily::keyLabel((string) $m->family, (string) $m->product_key)) . '</td>'
                    . '<td>' . $this->ui->e($m->plan_name . ' #' . $m->plan_id) . '</td>'
                    . '<td>' . count(array_unique(array_map(static fn ($o) => $o->logical, ProductMap::options((int) $m->id)))) . '</td>'
                    . '<td>' . (PriceCatalog::source($m) === 'cost' ? 'Cost' : '<span class="text-warning">Customer price</span>') . '</td>'
                    . '<td>' . ((float) $m->markup_pct != 0.0 ? ($m->markup_pct > 0 ? '+' : '') . $this->ui->e($m->markup_pct) . '%, ' : '') . $this->ui->e(ProductMap::ROUNDING[$m->rounding] ?? $m->rounding) . '</td>'
                    . '<td>' . ($m->sync_prices ? 'On' : 'Off') . '</td>'
                    . '<td>' . ($m->update_recurring ? 'Update' : 'Keep') . '</td>'
                    . '<td>' . $this->ui->e($m->last_synced_at ?? 'never') . '</td>'
                    . '<td style="white-space:nowrap"><a class="btn btn-default btn-sm" href="' . $this->ui->e($this->ui->url('map', ['id' => $m->id])) . '">Edit</a> '
                    . $this->ui->postButton('products', 'unmap', (int) $m->id, 'Unmap', 'btn-danger', 'Stop managing prices for this product? The product itself is not deleted.') . '</td></tr>';
            }
            $h .= '</tbody></table>';
            $h .= '<form method="post" style="margin-bottom:15px">' . $this->ui->tokenField() . '<input type="hidden" name="do" value="upgrade_paths"><button class="btn btn-default btn-sm">Refresh WHMCS upgrade paths</button></form>';
            $onSell = array_filter($maps, static fn ($m) => PriceCatalog::source($m) !== 'cost');
            if ($onSell) {
                $h .= '<div class="panel panel-default card"><div class="panel-body card-body"><form method="post" class="form-inline">' . $this->ui->tokenField() . '<input type="hidden" name="do" value="switch_source">'
                    . '<strong>' . count($onSell) . ' product(s) are priced from the LogicBoxes customer price.</strong> Price them from your <strong>cost price</strong> + markup instead: '
                    . '<input name="markup_pct" class="form-control input-sm" style="width:80px" value="30"> % '
                    . '<button class="btn btn-default btn-sm" onclick="return confirm(' . $this->ui->e((string) json_encode('Switch ' . count($onSell) . ' product(s) to cost price + markup? You will see the price changes before anything is written.')) . ')">Switch to cost price</button>'
                    . '<br><small class="text-muted">The new prices are shown below as pending changes; nothing is written until you apply them (or the automatic sync does, if it is on).</small></form></div></div>';
            }
        }

        $preview = (new PriceSync())->preview();
        $changes = $preview['changes'];
        $h .= '<h3>Pending price changes</h3>';
        foreach ($preview['warnings'] as $w) {
            $h .= '<div class="alert alert-warning">' . $this->ui->e($w) . '</div>';
        }
        $h .= '<div style="margin-bottom:10px"><form method="post" style="display:inline">' . $this->ui->tokenField() . '<input type="hidden" name="do" value="sync_refresh_preview"><button class="btn btn-default">' . (Settings::bool('price_sync_enabled') ? 'Fetch latest LogicBoxes prices (changes are applied automatically)' : 'Fetch latest LogicBoxes prices &amp; preview') . '</button></form> ';
        if ($changes) {
            $h .= '<form method="post" style="display:inline" onsubmit="return confirm(' . $this->ui->e(json_encode('Apply ' . count($changes) . ' price change(s)?')) . ')">' . $this->ui->tokenField() . '<input type="hidden" name="do" value="sync_apply"><button class="btn btn-primary">Apply ' . count($changes) . ' change(s)</button></form>';
        }
        $h .= '</div>';
        if (!$changes) {
            $h .= '<p class="text-muted">Everything is in sync with the stored catalogue.</p>';
        } else {
            $currencies = Capsule::table('tblcurrencies')->pluck('code', 'id')->all();
            $subNames = $this->subNames($changes);
            $h .= '<table class="table table-condensed"><thead><tr><th>What</th><th>ID</th><th>Currency</th><th>Cycle</th><th>Current</th><th>New</th></tr></thead><tbody>';
            foreach (array_slice($changes, 0, 500) as $c) {
                $what = $c['target'] === 'product' ? 'Product price' : ($c['target'] === 'service' ? 'Service recurring amount' : 'Option: ' . ($subNames[(int) $c['relid']] ?? '?'));
                $link = $c['target'] === 'service' ? '<a href="clientsservices.php?id=' . (int) $c['relid'] . '">#' . (int) $c['relid'] . '</a>' : '#' . (int) $c['relid'];
                $old = $c['old'] === null ? 'not set' : ((float) $c['old'] < 0 ? 'disabled' : number_format((float) $c['old'], 2));
                $new = (float) $c['new'] < 0 ? 'disabled' : number_format((float) $c['new'], 2);
                $h .= '<tr><td>' . $this->ui->e($what) . '</td><td>' . $link . '</td><td>' . $this->ui->e($currencies[$c['currency']] ?? $c['currency']) . '</td><td>' . $this->ui->e($c['cycle']) . '</td><td>' . $this->ui->e($old) . '</td><td><strong>' . $this->ui->e($new) . '</strong></td></tr>';
            }
            $h .= '</tbody></table>' . (count($changes) > 500 ? '<p class="text-muted">Showing the first 500.</p>' : '');
        }

        $mapped = array_map(static fn ($m) => (int) $m->product_id, $maps);
        $unmapped = [];
        foreach (Capsule::table('tblproducts')->whereIn('servertype', ProductFamily::modules())->get() as $p) {
            if (!in_array((int) $p->id, $mapped, true)) {
                $unmapped[(string) $p->id] = $p->name . ' (#' . $p->id . ', ' . $p->servertype . ', plan ' . ($p->configoption2 ?: 'not set') . ')';
            }
        }
        if ($unmapped) {
            $h .= '<h3>Map an existing product</h3><p class="text-muted">For products you created by hand. The product needs a plan in its Module Settings.</p>'
                . '<form method="post" class="form-horizontal" style="max-width:1000px">' . $this->ui->tokenField() . '<input type="hidden" name="do" value="map_existing">'
                . $this->ui->row('Product', $this->select('product_id', $unmapped, (string) array_key_first($unmapped)))
                . $this->ui->row('Configurable options', '<label><input type="checkbox" name="create_options" value="1"> Also create the module\'s configurable options for it (only priced options)</label>')
                . $this->settingsFields((object) ['price_source' => 'cost', 'markup_pct' => 30, 'rounding' => '2dp', 'source_currency_id' => $this->defaultCurrency(), 'sync_prices' => 1, 'update_recurring' => 0])
                . '<div class="form-group"><div class="col-sm-offset-3 col-sm-9"><button class="btn btn-default" type="submit">Map product</button></div></div></form>';
        }

        $recent = Capsule::table('mod_lbh_price_changes')->orderBy('id', 'desc')->limit(30)->get()->all();
        if ($recent) {
            $h .= '<h3>Recent price changes</h3><table class="table table-condensed"><thead><tr><th>Time</th><th>What</th><th>ID</th><th>Cycle</th><th>Old</th><th>New</th><th>Source</th></tr></thead><tbody>';
            foreach ($recent as $r) {
                $h .= '<tr><td>' . $this->ui->e($r->created_at) . '</td><td>' . $this->ui->e($r->target) . '</td><td>' . (int) $r->relid . '</td><td>' . $this->ui->e($r->cycle) . '</td><td>' . $this->ui->e($r->old_price) . '</td><td>' . $this->ui->e($r->new_price) . '</td><td>' . $this->ui->e($r->source) . '</td></tr>';
            }
            $h .= '</tbody></table>';
        }

        return $h;
    }

    public function pageMapForm(int $id): string
    {
        $m = $this->maps->find($id);
        if (!$m) {
            return '<div class="alert alert-warning">Mapping not found.</div>';
        }
        $family = (string) $m->family;
        $h = '<h3>' . $this->ui->e($m->plan_name . ' - ' . ProductFamily::label($family) . ' - ' . ProductFamily::keyLabel($family, (string) $m->product_key)) . '</h3>';
        $h .= '<form method="post" action="' . $this->ui->e($this->ui->url('map', ['id' => $id])) . '" class="form-horizontal" style="max-width:1000px">' . $this->ui->tokenField()
            . '<input type="hidden" name="do" value="save_map"><input type="hidden" name="id" value="' . $id . '">'
            . $this->settingsFields($m)
            . '<div class="form-group"><div class="col-sm-offset-3 col-sm-9"><button class="btn btn-primary">Save</button> <a class="btn btn-default" href="' . $this->ui->e($this->ui->url('products')) . '">Back</a></div></div></form>';

        // Options and their price sources
        $planner = new PricePlanner($m);
        $kind = $planner->source();
        $extras = PriceCatalog::extras((int) $m->account_id, (string) $m->product_key, (int) $m->plan_id, $kind);
        $bindings = ProductMap::bindings($m);
        $bindable = array_values(array_filter(array_keys(OptionBuilder::PRICE_ITEMS), static function ($l) use ($family, $m) {
            if ($l === 'cpanel_blocks') {
                return $family === ProductFamily::RESELLER && stripos((string) $m->product_key, 'windows') === false;
            }
            if ($l === 'cpu' || $l === 'ram') {
                return in_array($family, [ProductFamily::CLOUD, ProductFamily::WORDPRESS], true);
            }
            if ($l === 'whmcs') {
                return in_array($family, [ProductFamily::RESELLER, ProductFamily::VPS, ProductFamily::DEDICATED], true);
            }

            return !($family === ProductFamily::CLOUD && $l === 'dedicated_ip');
        }));
        $h .= '<h4>LogicBoxes price items (' . ($kind === 'cost' ? 'cost price list' : 'customer price list') . ')</h4>';
        if (!$extras) {
            $h .= '<p class="text-muted">This plan has no add-on prices in the stored price list. Fetch prices in the Price Catalog.</p>';
        } else {
            $h .= '<p class="text-muted">Each option is priced from the item shown. "Automatic" finds it by its usual names; if LogicBoxes lists it under another name (shown in the list), choose it here.</p>'
                . '<form method="post" class="form-horizontal" style="max-width:900px" action="' . $this->ui->e($this->ui->url('map', ['id' => $id])) . '">' . $this->ui->tokenField() . '<input type="hidden" name="do" value="save_bindings"><input type="hidden" name="id" value="' . $id . '">';
            $choices = ['' => '- Automatic -'];
            foreach ($extras as $item => $p) {
                $choices[$item] = $item . ' (' . number_format($p, 2) . ')';
            }
            foreach ($bindable as $l) {
                $auto = OptionBuilder::priceFor($l, (int) $m->account_id, (string) $m->product_key, (int) $m->plan_id, $kind, $bindings);
                $now = $auto ? 'now: <code>' . $this->ui->e($auto['item']) . '</code> ' . number_format($auto['price'], 2) . ' / month' : '<span class="text-danger">no price found - the option is not created</span>';
                $h .= $this->ui->row(OptionBuilder::NAMES[$l] . ($l === 'cpanel_blocks' ? ' (per block of 10 accounts)' : ''), $this->select('bind[' . $l . ']', $choices, (string) ($bindings[$l] ?? '')), $now);
            }
            $h .= '<div class="form-group"><div class="col-sm-offset-3 col-sm-9"><button class="btn btn-default btn-sm">Save price items</button></div></div></form>';
        }
        $opts = ProductMap::options($id);
        $h .= '<h4>Configurable options</h4>';
        if ($opts) {
            foreach ($opts as $o) {
                if ($o->price_item === 'manual' && !OptionBuilder::isManualOnly($family, (string) $o->logical)) {
                    $h .= '<div class="alert alert-warning">' . $this->ui->e(OptionBuilder::NAMES[$o->logical] ?? $o->logical) . ' is still priced by hand (from an older version). Choose its LogicBoxes price item above, or press "Create missing options" once LogicBoxes lists it - it then follows the LogicBoxes price.</div>';
                    break;
                }
            }
            $names = Capsule::table('tblproductconfigoptionssub')->whereIn('id', array_map(static fn ($o) => (int) $o->config_sub_id, $opts))->pluck('optionname', 'id')->all();
            $h .= '<form method="post" action="' . $this->ui->e($this->ui->url('map', ['id' => $id])) . '">' . $this->ui->tokenField() . '<input type="hidden" name="do" value="save_option_prices"><input type="hidden" name="id" value="' . $id . '">'
                . '<table class="table table-condensed" style="max-width:900px"><thead><tr><th>Option</th><th>Sub-option</th><th>Price source</th><th>LogicBoxes price / month</th><th>Manual price / month</th></tr></thead><tbody>';
            foreach ($opts as $o) {
                $monthly = $planner->optionMonthly($o);
                $src = $o->price_item === 'manual' ? 'manual' : ($o->price_item === 'free' ? 'free' : 'catalogue: ' . $o->price_item);
                $h .= '<tr><td>' . $this->ui->e(OptionBuilder::NAMES[$o->logical] ?? $o->logical) . '</td><td>' . $this->ui->e($names[$o->config_sub_id] ?? ('#' . $o->config_sub_id . ' (deleted)')) . '</td><td>' . $this->ui->e($src) . '</td>'
                    . '<td>' . ($o->price_item === 'manual' || $o->price_item === 'free' ? '-' : ($monthly === null ? '<span class="text-danger">not listed</span>' : number_format($monthly, 2))) . '</td>'
                    . '<td>' . ($o->price_item === 'manual' ? '<input name="manual_price[' . (int) $o->id . ']" class="form-control input-sm" style="max-width:120px" value="' . $this->ui->e($o->manual_price) . '">' : '-') . '</td></tr>';
            }
            $h .= '</tbody></table><button class="btn btn-default btn-sm">Save manual prices</button></form>';
        } else {
            $h .= '<p class="text-muted">No options managed for this product yet.</p>';
        }
        $h .= '<form method="post" class="form-horizontal" style="max-width:1000px;margin-top:15px" action="' . $this->ui->e($this->ui->url('map', ['id' => $id])) . '">' . $this->ui->tokenField() . '<input type="hidden" name="do" value="build_options"><input type="hidden" name="id" value="' . $id . '">'
            . '<p class="text-muted">Create missing options (' . $this->ui->e($this->optionSummary($family)) . '). Existing ones - including options of the same name you made by hand - are kept and synced, never duplicated.</p>';
        foreach ($this->manualFields($family) as $k => [$label, $help]) {
            $h .= $this->ui->row($label, '<input name="manual[' . $this->ui->e($k) . ']" class="form-control" style="max-width:160px" placeholder="per month">', $help);
        }
        if ($family === ProductFamily::DEDICATED) {
            $h .= $this->ui->row('Bandwidth tiers', '<textarea name="bandwidth_tiers" class="form-control" rows="2" placeholder="10TB|10 TB extra = 25"></textarea>');
        }
        $h .= '<div class="form-group"><div class="col-sm-offset-3 col-sm-9"><button class="btn btn-default">Create missing options</button></div></div></form>';

        if ($planner->hasPrices()) {
            $cid = $this->defaultCurrency();
            $code = (string) Capsule::table('tblcurrencies')->where('id', $cid)->value('code');
            $t = $planner->targets();
            $h .= '<h4>Product price after markup (' . $this->ui->e($code) . ')</h4><table class="table table-condensed" style="max-width:720px"><tr>';
            foreach (PriceCalculator::CYCLES as $cycle) {
                $v = $t['product'][$cid][$cycle] ?? -1;
                $h .= '<td><small class="text-muted">' . $this->ui->e($cycle) . '</small><br>' . ($v < 0 ? '-' : number_format($v, 2)) . '</td>';
            }
            $h .= '</tr></table>';
        }

        return $h;
    }

    // --------------------------------------------------------------- helpers

    /**
     * After a manual price fetch: when a price changed and automatic sync is
     * on, apply it straight away (same as the cron would). Returns whether it did.
     */
    private function autoApplyAfterRefresh(bool $changed): bool
    {
        if (!$changed || !Settings::bool('price_sync_enabled')) {
            return false;
        }
        $r = (new PriceSync())->syncAll('auto');
        $this->ui->addMessage('success', 'LogicBoxes prices changed: ' . $r['written'] . ' WHMCS price(s) updated automatically' . ($r['options'] ? ', ' . $r['options'] . ' missing option(s) created' : '') . '.');
        foreach ($r['warnings'] as $w) {
            $this->ui->addMessage('warning', $w);
        }

        return true;
    }

    private function optionSummary(string $family): string
    {
        return [
            ProductFamily::CLOUD => 'CPU, RAM, Dedicated IP (manual)',
            ProductFamily::MULTIDOMAIN => 'Dedicated IP',
            ProductFamily::SINGLEDOMAIN => 'Dedicated IP',
            ProductFamily::RESELLER => 'Dedicated IPs, WHMCS License, cPanel Blocks of 10 accounts (Linux)',
            ProductFamily::WORDPRESS => 'CPU, RAM, Dedicated IP',
            ProductFamily::BUSINESS => 'Dedicated IP',
            ProductFamily::VPS => 'Operating System, Control Panel, Dedicated IP, WHMCS License, Storage (HDD plans), Block Storage (US)',
            ProductFamily::DEDICATED => 'Operating System, Control Panel, Dedicated IP, WHMCS License, Storage, Bandwidth (manual)',
        ][$family];
    }

    /**
     * Price inputs for the only items LogicBoxes does not price (they are not
     * provisioned through the API). Everything else is priced from LogicBoxes.
     *
     * @return array<string,array{0:string,1:string}>
     */
    private function manualFields(string $family): array
    {
        switch ($family) {
            case ProductFamily::CLOUD:
                return ['dedicated_ip' => ['Dedicated IP price', 'Required to sell the Cloud dedicated IP: LogicBoxes has no API (and no API price) for it - it is set up by hand.']];
            case ProductFamily::VPS:
                return ['block_storage' => ['Block Storage price per GB', 'Required to sell Block Storage (SAN) on US plans: LogicBoxes does not publish a per-GB price in its price list.']];
            default:
                return [];
        }
    }

    /** @param array<int,array<string,mixed>> $changes @return array<int,string> */
    private function subNames(array $changes): array
    {
        $ids = array_values(array_unique(array_map(static fn ($c) => (int) $c['relid'], array_filter($changes, static fn ($c) => $c['target'] === 'configoptions'))));
        if (!$ids) {
            return [];
        }
        $out = [];
        foreach (Capsule::table('tblproductconfigoptionssub')->whereIn('id', $ids)->get() as $s) {
            $opt = (string) Capsule::table('tblproductconfigoptions')->where('id', $s->configid)->value('optionname');
            $out[(int) $s->id] = $opt . ' / ' . $s->optionname;
        }

        return $out;
    }

    private function settingsFields(object $v): string
    {
        $currencies = [];
        foreach (Capsule::table('tblcurrencies')->orderBy('id')->get() as $c) {
            $currencies[(string) $c->id] = $c->code . (!empty($c->default) ? ' (default)' : '');
        }
        $chk = static fn ($x) => $x ? ' checked' : '';

        return $this->ui->row('Price source', $this->select('price_source', ProductMap::SOURCES, PriceCatalog::source($v)), 'Cost price: what LogicBoxes charges you (<code>reseller-cost-price.json</code>); the markup below is your margin. Customer price: your selling prices at LogicBoxes (<code>customer-price.json</code>). The plan and every option use the same list.')
            . $this->ui->row('Markup %', '<input name="markup_pct" class="form-control" style="max-width:120px" value="' . $this->ui->e($v->markup_pct) . '">', 'Added on top of the LogicBoxes price (with the cost price it must be above 0). Applies to the plan and to every option, in every currency and billing cycle.')
            . $this->ui->row('Rounding', $this->select('rounding', ProductMap::ROUNDING, (string) $v->rounding))
            . $this->ui->row('LogicBoxes price currency', $this->select('source_currency_id', $currencies, (string) $v->source_currency_id), 'The WHMCS currency your LogicBoxes account sells in. Other currencies are converted with the WHMCS exchange rates.')
            . $this->ui->row('Price sync', '<label><input type="checkbox" name="sync_prices" value="1"' . $chk($v->sync_prices) . '> Keep product and option prices in sync with LogicBoxes (applied automatically when LogicBoxes changes a price - Settings &gt; Pricing)</label>')
            . $this->ui->row('Existing services', '<label><input type="checkbox" name="update_recurring" value="1"' . $chk($v->update_recurring) . '> Also update recurring amounts of existing services (manually changed amounts are never touched)</label>');
    }

    /** @param array<int|string,string> $opts */
    private function select(string $name, array $opts, string $selected): string
    {
        $h = '<select name="' . $this->ui->e($name) . '" class="form-control">';
        foreach ($opts as $k => $label) {
            $h .= '<option value="' . $this->ui->e($k) . '"' . ((string) $k === $selected ? ' selected' : '') . '>' . $this->ui->e($label) . '</option>';
        }

        return $h . '</select>';
    }

    /** @param array<int,string> $accounts */
    private function accountPicker(string $page, array $accounts, int $selected, ?string $family = null): string
    {
        $h = '<form method="get" style="display:inline-block"><input type="hidden" name="module" value="lbhosting"><input type="hidden" name="page" value="' . $this->ui->e($page) . '">'
            . ($family !== null ? '<input type="hidden" name="family" value="' . $this->ui->e($family) . '">' : '')
            . 'Account <select name="account" class="form-control input-sm" style="display:inline-block;width:auto" onchange="this.form.submit()">';
        foreach ($accounts as $id => $label) {
            $h .= '<option value="' . (int) $id . '"' . ((int) $id === $selected ? ' selected' : '') . '>' . $this->ui->e($label) . '</option>';
        }

        return $h . '</select></form>';
    }

    private function familyPicker(string $page, string $family, int $accId): string
    {
        $h = '<div class="btn-group" role="group" style="margin-right:10px">';
        foreach (ProductFamily::all() as $f) {
            $h .= '<a class="btn btn-sm ' . ($f === $family ? 'btn-primary' : 'btn-default') . '" href="' . $this->ui->e($this->ui->url($page, ['family' => $f, 'account' => $accId])) . '">' . $this->ui->e(ProductFamily::label($f)) . '</a>';
        }

        return $h . '</div>';
    }

    private function defaultCurrency(): int
    {
        return (int) (Capsule::table('tblcurrencies')->where('default', 1)->value('id') ?: Capsule::table('tblcurrencies')->orderBy('id')->value('id'));
    }
}
