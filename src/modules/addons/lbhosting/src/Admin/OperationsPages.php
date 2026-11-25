<?php

declare(strict_types=1);

namespace LbHosting\Admin;

use Illuminate\Database\Capsule\Manager as Capsule;
use LbHosting\Import\Importer;
use LbHosting\Import\OrderScanner;
use LbHosting\Catalog\ProductFamily;
use LbHosting\Import\ProviderExtras;
use LbHosting\Support\Settings;
use LbHosting\Update\Updater;

/**
 * Admin pages: Import Manager, Updates.
 * POSTs arrive here only after AddonController has verified the CSRF token.
 */
final class OperationsPages
{
    public const DOS = ['import_scan', 'import_link', 'import_one', 'import_bulk', 'import_ignore', 'import_restore', 'update_settings', 'update_check', 'update_install'];


    private AddonController $ui;

    public function __construct(AddonController $ui)
    {
        $this->ui = $ui;
    }

    // ------------------------------------------------------------------ POST

    public function handle(string $do): void
    {
        $importer = new Importer();
        switch ($do) {
            case 'import_scan':
                $acc = $this->ui->accounts()->findActive((int) ($_POST['account_id'] ?? 0));
                if (!$acc) {
                    throw new \InvalidArgumentException('Choose an active API account.');
                }
                $keys = array_values(array_intersect((array) ($_POST['families'] ?? []), ProductFamily::all()));
                $statuses = array_values(array_intersect((array) ($_POST['statuses'] ?? []), ['Active', 'Suspended']));
                if (!$keys || !$statuses) {
                    throw new \InvalidArgumentException('Choose at least one product and one status.');
                }
                @set_time_limit(600);
                $r = (new OrderScanner())->scan($acc, $keys, $statuses);
                $this->ui->addMessage('success', sprintf('Scan finished: %d order(s) read, %d new, %d refreshed, %d already handled.', $r['found'], $r['new'], $r['updated'], $r['skipped']));
                foreach (array_slice($r['errors'], 0, 12) as $e) {
                    $this->ui->addMessage('warning', $e);
                }
                $this->ui->redirect('import');
                break;

            case 'import_link':
                $sid = $importer->link((int) $_POST['id'], (int) ($_POST['service_id'] ?? 0) ?: null, true); // a per-row click is the admin's confirmation
                $this->ui->addMessage('success', 'Linked to service #' . $sid . '.');
                break;

            case 'import_one':
                $sid = $importer->import((int) $_POST['id'], $this->importOptions());
                $this->ui->addMessage('success', 'Imported as service #' . $sid . '.');
                break;

            case 'import_bulk':
                $opts = $this->importOptions();
                $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
                if (!$ids) {
                    throw new \InvalidArgumentException('Tick the orders to process.');
                }
                $done = ['linked' => 0, 'imported' => 0, 'failed' => 0, 'review' => 0];
                foreach ($ids as $id) {
                    $o = Capsule::table('mod_lbh_import_orders')->where('id', $id)->first();
                    if (!$o || !in_array($o->state, ['new', 'error'], true)) {
                        continue;
                    }
                    try {
                        if (!empty($o->match_service_id) && ($o->match_by ?? null) !== 'email') {
                            $done['review']++; // same name only: neither linked nor imported (that would duplicate the service)
                        } elseif (!empty($o->match_service_id)) {
                            $importer->link($id);
                            $done['linked']++;
                        } else {
                            $importer->import($id, $opts);
                            $done['imported']++;
                        }
                    } catch (\Throwable $e) {
                        $done['failed']++;
                    }
                }
                $this->ui->addMessage($done['failed'] || $done['review'] ? 'warning' : 'success', sprintf('%d linked, %d imported, %d failed (see the State column)%s.', $done['linked'], $done['imported'], $done['failed'], $done['review'] ? ', ' . $done['review'] . ' left for you to check: a service with the same name exists but belongs to a client that does not match the LogicBoxes customer - use Link on the row once you are sure, or Ignore' : ''));
                break;

            case 'import_ignore':
                $importer->ignore((int) $_POST['id']);
                break;

            case 'import_restore':
                $importer->restore((int) $_POST['id']);
                break;

            case 'update_settings':
                $url = trim((string) ($_POST['update_url'] ?? ''));
                if ($url !== '' && (stripos($url, 'https://') !== 0 || !filter_var($url, FILTER_VALIDATE_URL))) {
                    throw new \InvalidArgumentException('Manifest URL must be a valid https:// URL.');
                }
                $pk = trim((string) ($_POST['update_pubkey'] ?? ''));
                $raw = $pk === '' ? '' : base64_decode($pk, true);
                if ($pk !== '' && ($raw === false || strlen($raw) !== 32)) {
                    throw new \InvalidArgumentException('Public key must be the base64 Ed25519 key printed by tools/sign-release.php keygen.');
                }
                Settings::set('update_url', $url);
                Settings::set('update_pubkey', $pk);
                Settings::set('update_check', !empty($_POST['update_check']) ? '1' : '0');
                Settings::set('update_auto', !empty($_POST['update_auto']) ? '1' : '0');
                $this->ui->addMessage('success', 'Update settings saved.');
                break;

            case 'update_check':
                $s = (new Updater())->check();
                $this->ui->addMessage(!empty($s['ok']) ? 'success' : 'danger', !empty($s['ok'])
                    ? (!empty($s['available']) ? 'Version ' . $s['latest'] . ' is available (signature verified).' : 'You are on the latest version (' . $s['current'] . ').')
                    : 'Check failed: ' . ($s['error'] ?? 'unknown'));
                break;

            case 'update_install':
                $v = (new Updater())->install();
                $this->ui->addMessage('success', 'Updated to ' . $v . '. Reload this page.');
                break;
        }
    }

    /** @return array<string,mixed> */
    private function importOptions(): array
    {
        return [
            'product_id' => (int) ($_POST['product_id'] ?? 0),
            'billingcycle' => (string) ($_POST['billingcycle'] ?? 'Annually'),
            'paymentmethod' => (string) ($_POST['paymentmethod'] ?? ''),
            'create_client' => !empty($_POST['create_client']),
            'set_amount' => !empty($_POST['set_amount']),
        ];
    }

    // ----------------------------------------------------------------- pages

    public function pageImport(): string
    {
        $accounts = $this->ui->accounts()->options();
        if (!$accounts) {
            return '<div class="alert alert-info">Add an API account first.</div>';
        }
        $h = '<p>Finds hosting and server orders that already exist at LogicBoxes and brings them into WHMCS. Scanning only reads; nothing is created until you click Link or Import, and nothing is ever changed at LogicBoxes.</p>'
            . '<p class="text-muted">Every location of the ticked products is searched (one call per location, then one per order). Locations whose API path LogicBoxes does not document may report an error - that is harmless.</p>';
        $h .= '<form method="post" class="form-inline" style="margin-bottom:15px">' . $this->ui->tokenField() . '<input type="hidden" name="do" value="import_scan">'
            . $this->select('account_id', array_map('strval', $accounts), (string) array_key_first($accounts), false) . ' ';
        foreach (ProductFamily::all() as $k) {
            $h .= '<label style="margin:0 8px"><input type="checkbox" name="families[]" value="' . $this->ui->e($k) . '" checked> ' . $this->ui->e(ProductFamily::label($k)) . '</label>';
        }
        $h .= ' | ';
        foreach (['Active' => true, 'Suspended' => true] as $st => $on) {
            $h .= '<label style="margin:0 8px"><input type="checkbox" name="statuses[]" value="' . $st . '"' . ($on ? ' checked' : '') . '> ' . $st . '</label>';
        }
        $h .= '<button class="btn btn-primary">Scan LogicBoxes</button></form>';

        $filter = (string) ($_GET['state'] ?? 'new');
        $counts = Capsule::table('mod_lbh_import_orders')->selectRaw('state, count(*) as c')->groupBy('state')->pluck('c', 'state')->all();
        $h .= '<ul class="nav nav-pills" style="margin-bottom:10px">';
        foreach (['new' => 'To do', 'error' => 'Errors', 'linked' => 'Linked', 'imported' => 'Imported', 'ignored' => 'Ignored', 'all' => 'All'] as $st => $label) {
            $n = $st === 'all' ? array_sum($counts) : (int) ($counts[$st] ?? 0);
            $h .= '<li class="nav-item' . ($filter === $st ? ' active' : '') . '"><a class="nav-link' . ($filter === $st ? ' active' : '') . '" href="' . $this->ui->e($this->ui->url('import', ['state' => $st])) . '">' . $this->ui->e($label) . ' (' . $n . ')</a></li>';
        }
        $h .= '</ul>';

        $q = Capsule::table('mod_lbh_import_orders')->orderBy('domain')->limit(1000);
        if ($filter !== 'all') {
            $q->where('state', $filter);
        }
        $rows = $q->get()->all();
        if (!$rows) {
            return $h . '<p class="text-muted">Nothing here.</p>';
        }

        $products = [];
        foreach (Capsule::table('tblproducts')->whereIn('servertype', ProductFamily::modules())->orderBy('name')->get() as $p) {
            $products[(string) $p->id] = $p->name . ' (#' . $p->id . ', ' . $p->servertype . ')';
        }
        $gateways = [];
        foreach (Capsule::table('tblpaymentgateways')->where('setting', 'name')->orderBy('order')->get() as $g) {
            $gateways[(string) $g->gateway] = (string) $g->value;
        }

        $h .= '<form method="post">' . $this->ui->tokenField() . '<input type="hidden" name="do" value="import_bulk">';
        $h .= '<table class="table table-condensed table-striped"><thead><tr><th><input type="checkbox" onclick="document.querySelectorAll(\'.lbh-imp\').forEach(c=>c.checked=this.checked)"></th><th>Domain</th><th>Product</th><th>Order</th><th>LogicBoxes</th><th>Plan / on the order</th><th>Expires</th><th>Customer</th><th>WHMCS match</th><th>State</th><th></th></tr></thead><tbody>';
        foreach ($rows as $o) {
            $match = [];
            if (!empty($o->match_client_id)) {
                $match[] = '<a href="clientssummary.php?userid=' . (int) $o->match_client_id . '">client #' . (int) $o->match_client_id . '</a>';
            }
            if (!empty($o->match_service_id)) {
                $match[] = '<a href="clientsservices.php?id=' . (int) $o->match_service_id . '">service #' . (int) $o->match_service_id . '</a>'
                    . (($o->match_by ?? null) === 'domain' && $o->state !== 'linked' ? ' <span class="label label-warning badge" title="The LogicBoxes customer matches no WHMCS client; only the name agrees">same name only - check the owner</span>' : '');
            }
            if (!empty($o->match_product_id)) {
                $match[] = 'product #' . (int) $o->match_product_id;
            }
            $actionable = in_array($o->state, ['new', 'error'], true);
            $h .= '<tr><td>' . ($actionable ? '<input type="checkbox" class="lbh-imp" name="ids[]" value="' . (int) $o->id . '">' : '') . '</td>'
                . '<td>' . $this->ui->e($o->domain) . '</td>'
                . '<td>' . $this->ui->e(ProductFamily::label((string) $o->family)) . '<br><small class="text-muted">' . $this->ui->e(self::keyLabel((string) $o->family, (string) $o->product_key)) . '</small></td>'
                . '<td>' . (int) $o->order_id . '</td>'
                . '<td>' . $this->ui->e($o->lb_status) . '</td>'
                . '<td>' . $this->ui->e(!empty($o->plan_name) ? $o->plan_name : '#' . ($o->plan_id ?? '?')) . (($x = ProviderExtras::summary(json_decode((string) ($o->extras_json ?? ''), true) ?: [])) !== '' ? '<br><small class="text-muted">' . $this->ui->e($x) . '</small>' : '') . '</td>'
                . '<td>' . $this->ui->e(!empty($o->expires_at) ? substr((string) $o->expires_at, 0, 10) : '-') . '</td>'
                . '<td>' . $this->ui->e(!empty($o->customer_email) ? $o->customer_email : ('#' . ($o->customer_id ?? '?'))) . '</td>'
                . '<td>' . ($match ? implode('<br>', $match) : '<span class="text-muted">none</span>') . '</td>'
                . '<td>' . $this->ui->e($o->state) . (!empty($o->message) ? '<br><small class="text-danger">' . $this->ui->e($o->message) . '</small>' : '') . '</td>'
                . '<td style="white-space:nowrap">';
            if ($actionable && !empty($o->match_service_id)) {
                $h .= $this->rowButton('import_link', (int) $o->id, 'Link', 'btn-success', ($o->match_by ?? null) === 'domain' ? 'Link this order to service #' . (int) $o->match_service_id . '? Its owner does not match the LogicBoxes customer. Paying that service will renew this order.' : '');
            }
            if ($actionable) {
                $h .= ' ' . $this->rowButton('import_ignore', (int) $o->id, 'Ignore', 'btn-default');
            }
            if ($o->state === 'ignored') {
                $h .= $this->rowButton('import_restore', (int) $o->id, 'Restore', 'btn-default');
            }
            $h .= '</td></tr>';
        }
        $h .= '</tbody></table>';

        $h .= '<div class="panel panel-default card"><div class="panel-body card-body"><h4>Process ticked orders</h4>'
            . '<p class="text-muted">Orders matched to a service of the same client are <strong>linked</strong>. Orders marked "same name only" are left alone until you Link or Ignore them on the row. The others are <strong>imported</strong> as new services: no invoice, no emails, no provisioning call. The next due date is set to the LogicBoxes expiry and the status matches LogicBoxes. On generator-made products the configurable options are set to what is on the order; check them on a few services before relying on them. A chosen product must use the same module as the order.</p>'
            . '<div class="row"><div class="col-sm-4"><label>Product for plans that are not mapped</label>' . $this->select('product_id', ['0' => '- none: mapped plans only -'] + $products, '0') . '</div>'
            . '<div class="col-sm-3"><label>Billing cycle</label>' . $this->select('billingcycle', array_combine(Importer::CYCLES, Importer::CYCLES), 'Annually') . '</div>'
            . '<div class="col-sm-3"><label>Payment method</label>' . $this->select('paymentmethod', $gateways ?: ['' => '(no active gateways)'], (string) array_key_first($gateways)) . '</div></div>'
            . '<div class="checkbox" style="margin-top:10px"><label><input type="checkbox" name="create_client" value="1"> Create missing WHMCS clients from LogicBoxes customer details (no welcome email)</label></div>'
            . '<div class="checkbox"><label><input type="checkbox" name="set_amount" value="1" checked> Set the recurring amount from the product\'s price map (mapped products only)</label></div>'
            . '<button class="btn btn-primary" onclick="return confirm(\'Process the ticked orders?\')">Link / import ticked orders</button></div></div></form>';

        return $h . implode('', $this->rowForms);
    }

    public function pageUpdates(): string
    {
        $s = Settings::all();
        $state = Updater::state();
        $h = '<p>Current version: <strong>' . $this->ui->e(Updater::currentVersion()) . '</strong>';
        if ($state) {
            $h .= ' · last check ' . $this->ui->e($state['checked_at'] ?? '-');
            if (!empty($state['error'])) {
                $h .= ' · <span class="text-danger">' . $this->ui->e($state['error']) . '</span>';
            } elseif (!empty($state['available'])) {
                $h .= ' · <strong class="text-success">version ' . $this->ui->e($state['latest']) . ' available</strong>' . (empty($state['php_ok']) ? ' <span class="text-danger">(needs a newer PHP)</span>' : '');
            } else {
                $h .= ' · up to date';
            }
        }
        $h .= '</p>';
        if (!empty($state['notes'])) {
            $h .= '<pre style="white-space:pre-wrap;max-height:240px;overflow:auto">' . $this->ui->e($state['notes']) . '</pre>';
        }
        $h .= '<div style="margin-bottom:20px">'
            . '<form method="post" style="display:inline">' . $this->ui->tokenField() . '<input type="hidden" name="do" value="update_check"><button class="btn btn-default">Check now</button></form> ';
        if (!empty($state['available']) && !empty($state['php_ok'])) {
            $h .= '<form method="post" style="display:inline" onsubmit="return confirm(\'Install version ' . $this->ui->e($state['latest']) . '? A backup is taken first and restored automatically if copying fails.\')">' . $this->ui->tokenField() . '<input type="hidden" name="do" value="update_install"><button class="btn btn-primary">Install ' . $this->ui->e($state['latest']) . '</button></form>';
        }
        $h .= '</div>';

        $chk = static fn (string $k) => ($s[$k] ?? '0') === '1' ? ' checked' : '';
        $h .= '<h3>Update source</h3><p class="text-muted">Updates are only installed from a manifest signed with <em>your</em> key (<code>tools/sign-release.php</code>), and only if the downloaded zip matches the signed SHA-256. Files in any <code>overrides/</code> folder are never touched. Before copying, current files are backed up outside the web root (WHMCS attachments folder).</p>'
            . '<form method="post" class="form-horizontal" style="max-width:900px">' . $this->ui->tokenField() . '<input type="hidden" name="do" value="update_settings">'
            . $this->ui->row('Manifest URL', '<input name="update_url" class="form-control" value="' . $this->ui->e($s['update_url']) . '" placeholder="https://updates.example.com/lbhosting.json">')
            . $this->ui->row('Public key', '<input name="update_pubkey" class="form-control" value="' . $this->ui->e($s['update_pubkey']) . '" placeholder="base64 Ed25519 public key">')
            . $this->ui->row('Daily check', '<label><input type="checkbox" name="update_check" value="1"' . $chk('update_check') . '> Check for new versions every day</label>')
            . $this->ui->row('Automatic install', '<label><input type="checkbox" name="update_auto" value="1"' . $chk('update_auto') . '> Install verified new versions automatically during the daily cron</label>')
            . '<div class="form-group"><div class="col-sm-offset-3 col-sm-9"><button class="btn btn-primary">Save</button></div></div></form>';

        return $h;
    }

    // --------------------------------------------------------------- helpers

    private static function keyLabel(string $family, string $key): string
    {
        try {
            return ProductFamily::keyLabel($family, $key);
        } catch (\Throwable $e) {
            return $key;
        }
    }

    /** @var string[] per-row forms, rendered after (outside) the bulk form */
    private array $rowForms = [];

    /** A row action button that submits its own small form via the HTML5 form= attribute (no nested forms). */
    private function rowButton(string $do, int $id, string $label, string $cls, string $confirm = ''): string
    {
        $formId = 'lbh-row-' . $do . '-' . $id;
        $this->rowForms[] = '<form id="' . $formId . '" method="post" style="display:none">' . $this->ui->tokenField()
            . '<input type="hidden" name="do" value="' . $this->ui->e($do) . '"><input type="hidden" name="id" value="' . $id . '"></form>';

        return '<button type="submit" form="' . $formId . '" class="btn btn-xs btn-sm ' . $cls . '"' . ($confirm !== '' ? ' onclick="return confirm(' . $this->ui->e((string) json_encode($confirm, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) . ')"' : '') . '>' . $this->ui->e($label) . '</button>';
    }

    /** @param array<int|string,string> $opts */
    private function select(string $name, array $opts, string $selected, bool $block = true): string
    {
        $h = '<select name="' . $this->ui->e($name) . '" class="form-control"' . ($block ? '' : ' style="display:inline-block;width:auto"') . '>';
        foreach ($opts as $k => $label) {
            $h .= '<option value="' . $this->ui->e($k) . '"' . ((string) $k === $selected ? ' selected' : '') . '>' . $this->ui->e($label) . '</option>';
        }

        return $h . '</select>';
    }
}
