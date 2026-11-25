<?php
/**
 * Shared offline test bootstrap: in-memory Capsule fake, WHMCS function
 * stubs, a scripted HTTP transport and tiny assertion helpers.
 * CLI only - never reachable from the web.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('WHMCS', true);
define('LBH_TESTING', true);
require __DIR__ . '/FakeCapsule.php';
require __DIR__ . '/../autoload.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use LbHosting\Api\HttpTransport;
use LbHosting\Support\Settings;

// ---- WHMCS function stubs ---------------------------------------------------
$GLOBALS['moduleLog'] = [];
$GLOBALS['localApi'] = [];
function logModuleCall($m, $a, $req, $res, $proc, $replace = []) { $GLOBALS['moduleLog'][] = compact('m', 'a', 'req', 'res', 'proc', 'replace'); }
function logActivity($msg) {}
function encrypt($v) { return 'enc:' . base64_encode($v); }
function decrypt($v) { return base64_decode(substr($v, 4)); }
function localAPI($cmd, $params = [])
{
    $GLOBALS['localApi'][] = [$cmd, $params];
    if ($cmd === 'AddProduct') {
        $id = Capsule::table('tblproducts')->insertGetId(['type' => $params['type'], 'gid' => $params['gid'], 'name' => $params['name'], 'servertype' => $params['module'], 'hidden' => !empty($params['hidden']) ? 1 : 0, 'description' => $params['description'] ?? '',
            'configoption1' => '', 'configoption2' => '', 'configoption3' => '', 'configoption4' => '', 'configoption5' => '']);

        return ['result' => 'success', 'pid' => $id];
    }
    if ($cmd === 'OpenTicket') {
        $id = Capsule::table('tbltickets')->insertGetId(['userid' => $params['clientid'], 'did' => $params['deptid'], 'title' => $params['subject'], 'message' => $params['message'], 'service' => $params['serviceid'] ?? null]);

        return ['result' => 'success', 'id' => $id, 'tid' => 'ABC-' . $id];
    }

    if ($cmd === 'CreateInvoice') {
        if (!empty($GLOBALS['failCreateInvoice'])) {
            return ['result' => 'error', 'message' => 'Client ID Not Found'];
        }
        $id = Capsule::table('tblinvoices')->insertGetId(['userid' => $params['userid'], 'status' => $params['status'], 'total' => $params['itemamount1'], 'notes' => $params['itemdescription1']]);
        Capsule::table('tblinvoiceitems')->insert(['invoiceid' => $id, 'userid' => $params['userid'], 'type' => '', 'relid' => 0, 'description' => $params['itemdescription1'], 'amount' => $params['itemamount1']]);

        return ['result' => 'success', 'invoiceid' => $id, 'status' => $params['status']];
    }

    if ($cmd === 'AddOrder') {
        if (!empty($GLOBALS['failAddOrder'])) {
            return ['result' => 'error', 'message' => 'Product ID Not Found'];
        }
        $oid = Capsule::table('tblorders')->insertGetId(['userid' => $params['clientid'], 'invoiceid' => 0]);
        $sid = Capsule::table('tblhosting')->insertGetId(['userid' => $params['clientid'], 'packageid' => $params['pid'][0], 'domain' => $params['domain'][0], 'billingcycle' => ['monthly' => 'Monthly', 'annually' => 'Annually', 'quarterly' => 'Quarterly', 'semiannually' => 'Semi-Annually', 'biennially' => 'Biennially', 'triennially' => 'Triennially'][$params['billingcycle'][0]], 'domainstatus' => 'Pending', 'amount' => '0.00', 'orderid' => $oid, 'paymentmethod' => $params['paymentmethod']]);

        return ['result' => 'success', 'orderid' => $oid, 'serviceids' => (string) $sid];
    }
    if ($cmd === 'AcceptOrder') {
        return ['result' => 'success'];
    }
    if ($cmd === 'AddClient') {
        $id = Capsule::table('tblclients')->insertGetId(['email' => $params['email'], 'firstname' => $params['firstname'], 'lastname' => $params['lastname'], 'country' => $params['country'], 'currency' => 1]);

        return ['result' => 'success', 'clientid' => $id];
    }
    if ($cmd === 'DomainGetNameservers') {
        $d = Capsule::table('tbldomains')->where('id', $params['domainid'])->first();
        $out = ['result' => 'success'];
        foreach (explode(',', (string) ($d->ns ?? '')) as $i => $ns) {
            $out['ns' . ($i + 1)] = $ns;
        }

        return $out;
    }
    if ($cmd === 'DomainUpdateNameservers') {
        if (!empty($GLOBALS['failRegistrar'])) {
            return ['result' => 'error', 'message' => 'Registrar Error: domain is locked'];
        }
        $ns = [];
        for ($i = 1; $i <= 5; $i++) {
            if (!empty($params['ns' . $i])) {
                $ns[] = $params['ns' . $i];
            }
        }
        Capsule::table('tbldomains')->where('id', $params['domainid'])->update(['ns' => implode(',', $ns)]);

        return ['result' => 'success'];
    }

    return ['result' => 'error', 'message' => 'unstubbed ' . $cmd];
}

// ---- assertion helpers -------------------------------------------------------
$GLOBALS['pass'] = 0;
$GLOBALS['fail'] = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    if ($ok) {
        $GLOBALS['pass']++;
        echo "  ok   $name\n";
    } else {
        $GLOBALS['fail']++;
        echo "  FAIL $name" . ($detail !== '' ? " -- $detail" : '') . "\n";
    }
}
function throwsLike(string $name, callable $fn, string $needle): void
{
    try {
        $fn();
        check($name, false, 'no exception');
    } catch (\Throwable $e) {
        check($name, stripos($e->getMessage(), $needle) !== false, get_class($e) . ': ' . $e->getMessage());
    }
}
function section(string $t): void
{
    echo "\n== $t\n";
}
function done(): void
{
    echo "\n" . $GLOBALS['pass'] . ' passed, ' . $GLOBALS['fail'] . " failed\n";
    exit($GLOBALS['fail'] ? 1 : 0);
}

// ---- scripted transport -----------------------------------------------------
final class ScriptedTransport implements HttpTransport
{
    /** @var array<string, array<int, array{0:int,1:string}>> "METHOD path" => queue */
    public array $script = [];
    public array $calls = [];

    /** @param mixed $body */
    public function on(string $method, string $path, int $status, $body): void
    {
        $this->script[strtoupper($method) . ' ' . $path][] = [$status, is_string($body) ? $body : json_encode($body)];
    }

    public function send(string $method, string $url, ?string $body = null): array
    {
        $parts = parse_url($url);
        $path = ltrim((string) $parts['path'], '/');
        $q = self::parse((string) ($parts['query'] ?? ''));
        if ($body !== null && $body !== '') {
            $q = array_merge($q, self::parse($body));
        }
        $this->calls[] = ['method' => $method, 'path' => $path, 'query' => $q, 'host' => $parts['host'] ?? '', 'body' => $body];
        $k = strtoupper($method) . ' ' . $path;
        if (!empty($this->script[$k])) {
            $r = count($this->script[$k]) > 1 ? array_shift($this->script[$k]) : $this->script[$k][0];

            return ['status' => $r[0], 'body' => $r[1]];
        }

        return ['status' => 500, 'body' => json_encode(['status' => 'ERROR', 'message' => 'unscripted ' . $k])];
    }

    /** Repeated keys (addon=a&addon=b) become arrays, like LogicBoxes reads them. */
    public static function parse(string $qs): array
    {
        $out = [];
        foreach ($qs === '' ? [] : explode('&', $qs) as $pair) {
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
            $k = rawurldecode($k);
            $v = rawurldecode($v);
            if (array_key_exists($k, $out)) {
                $out[$k] = array_merge((array) $out[$k], [$v]);
            } else {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    public function count(string $method, string $path): int
    {
        return count(array_filter($this->calls, static fn ($c) => $c['method'] === $method && $c['path'] === $path));
    }

    public function last(string $method, string $path): ?array
    {
        $m = array_values(array_filter($this->calls, static fn ($c) => $c['method'] === $method && $c['path'] === $path));

        return $m ? end($m) : null;
    }

    /** @return string[] "METHOD path" in call order */
    public function trail(): array
    {
        return array_map(static fn ($c) => $c['method'] . ' ' . $c['path'], $this->calls);
    }
}

/**
 * Fresh in-memory WHMCS with one API account, one client, one product of
 * $module and service 10 using it.
 */
function freshDb(string $module, string $plan, array $productOptions = [], array $service = []): void
{
    Capsule::reset();
    Settings::override(Settings::DEFAULTS);
    \LbHosting\Catalog\ProductFamily::resetDiscovered();
    \LbHosting\I18n\Lang::reset();
    foreach (glob(__DIR__ . '/../migrations/*.php') as $f) {
        Capsule::table('mod_lbh_migrations')->insert(['migration' => basename($f, '.php')]);
    }
    Capsule::table('mod_lbh_accounts')->insert([
        'id' => 1, 'name' => 'RC Test', 'brand' => 'resellerclub', 'reseller_id' => '12345', 'api_key_enc' => encrypt('SECRETKEY123'),
        'test_mode' => 1, 'api_url' => '', 'branding_url' => 'manage.example.com', 'nameservers' => "ns1.example.net\nns2.example.net",
        'is_default' => 1, 'status' => 'active',
    ]);
    Capsule::table('tblclients')->insert(['id' => 1, 'email' => 'jane@example.com', 'currency' => 1]);
    Capsule::table('tblcurrencies')->insert(['id' => 1, 'code' => 'INR', 'rate' => 1.0, 'default' => 1]);
    Capsule::table('tblcurrencies')->insert(['id' => 2, 'code' => 'USD', 'rate' => 0.012, 'default' => 0]);
    Capsule::table('tblticketdepartments')->insert(['id' => 4, 'name' => 'Support', 'order' => 1]);
    Capsule::table('tblorders')->insert(['id' => 5, 'ipaddress' => '49.36.1.1', 'invoiceid' => 70]);
    Capsule::table('tblproducts')->insert(array_merge(['id' => 3, 'name' => 'Test product', 'servertype' => $module,
        'configoption1' => '1', 'configoption2' => $plan, 'configoption3' => 'NoInvoice', 'configoption4' => '', 'configoption5' => ''], $productOptions));
    Capsule::table('tblhosting')->insert(array_merge(['id' => 10, 'userid' => 1, 'orderid' => 5, 'packageid' => 3, 'billingcycle' => 'Annually', 'nextduedate' => '2027-10-01', 'domain' => 'example.com', 'domainstatus' => 'Active', 'amount' => '1200.00'], $service));
}

/** @return array<string,mixed> WHMCS-style module params for service 10 */
function params(string $plan, array $over = []): array
{
    $configOptions = $over['configoptions'] ?? [];
    unset($over['configoptions']);
    $p = array_replace([
        'serviceid' => 10, 'userid' => 1, 'pid' => 3, 'domain' => 'example.com', 'status' => 'Active',
        'configoption1' => '1', 'configoption2' => $plan, 'configoption3' => 'NoInvoice', 'configoption4' => '', 'configoption5' => '',
        'clientsdetails' => ['userid' => 1, 'email' => 'jane@example.com', 'firstname' => 'Jane', 'lastname' => 'Doe', 'countrycode' => 'IN', 'phonecc' => '91', 'phonenumber' => '9876543210', 'language' => 'english'],
    ], $over);
    $p['configoptions'] = $configOptions;

    return $p;
}

/** A transport that already knows the customer. */
function transportWithCustomer(): ScriptedTransport
{
    $t = new ScriptedTransport();
    $t->on('GET', 'api/customers/details.json', 200, ['customerid' => '555', 'username' => 'jane@example.com']);

    return $t;
}
