<?php
/**
 * Offline tests: locations LogicBoxes sells but does not document (Business
 * Hosting and WordPress Hosting outside the one region in the KB) are found
 * on the reseller account and work end to end.
 * Run: php tests/run_locations.php
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/fixtures.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use LbHosting\Accounts\AccountRepository;
use LbHosting\Catalog\ProductFamily;
use LbHosting\Pricing\PriceCatalog;
use LbHosting\Products\ProductGenerator;
use LbHosting\Service\Provisioner;
use LbHosting\Support\Settings;
use LbHosting\WHMCS\ServerModule;

section('Key recognition');
freshDb('lbhbusiness', 'businesshostinglinuxin:41');
$c = ProductFamily::classify('businesshostinglinuxus');
check('businesshostinglinuxus -> Business, businesshosting/linux/us', $c[0] === 'business' && $c[1]['path'] === 'businesshosting/linux/us' && $c[1]['region'] === 'us' && $c[1]['conf'] === 'PATTERN');
check('businesshostingwindowsuk', ProductFamily::classify('businesshostingwindowsuk')[1]['path'] === 'businesshosting/windows/uk');
check('bare "businesshosting" = Linux US, like the older products', ProductFamily::classify('businesshosting')[1]['path'] === 'businesshosting/linux/us');
$c = ProductFamily::classify('wordpresshostingindia');
check('wordpresshostingindia -> WordPress, REST path = key, region India', $c[0] === 'wordpress' && $c[1]['path'] === 'wordpresshostingindia' && $c[1]['region'] === 'in');
check('wordpresshostingin too', ProductFamily::classify('wordpresshostingin')[1]['region'] === 'in');
check('documented keys and foreign products are not "discovered"', ProductFamily::classify('businesshostinglinuxin') === null && ProductFamily::classify('wordpresshostingusa') === null && ProductFamily::classify('titanmailindia') === null && ProductFamily::classify('dotcom') === null && ProductFamily::classify('businesshosting/../x') === null);
check('nothing discovered on a fresh install', ProductFamily::discovered() === [] && count(ProductFamily::keys('business')) === 1 && count(ProductFamily::keys('wordpress')) === 1);

section('Found when prices are fetched');
$t = new ScriptedTransport();
ServerModule::$transport = $t;
$prices = priceFixture() + [
    'businesshostinglinuxus' => ['51' => ['renew' => ['12' => 5], 'ssl' => 2]],
    'wordpresshostingindia' => ['plans' => ['400100' => ['renew' => ['12' => 300]]], 'addons' => ['cpu_1' => 50]],
];
$plans = planFixture() + ['businesshostinglinuxus' => ['51' => ['plan_name' => 'Biz US']], 'wordpresshostingindia' => ['400100' => ['plan_name' => 'WP India']]];
$t->on('GET', 'api/products/customer-price.json', 200, $prices);
$t->on('GET', 'api/products/plan-details.json', 200, $plans);
$acc = (new AccountRepository())->find(1);
$res = (new PriceCatalog($t))->refresh($acc);
check('both new locations reported', ($res['discovered'] ?? []) === ['businesshostinglinuxus', 'wordpresshostingindia']);
check('they are now locations of their product', isset(ProductFamily::keys('business')['businesshostinglinuxus']) && isset(ProductFamily::keys('wordpress')['wordpresshostingindia']) && ProductFamily::forKey('wordpresshostingindia') === 'wordpress');
check('labels', ProductFamily::keyLabel('business', 'businesshostinglinuxus') === 'Linux - United States (Texas)' && ProductFamily::keyLabel('wordpress', 'wordpresshostingindia') === 'Linux - India');
check('their prices and plans are in the catalogue', Capsule::table('mod_lbh_prices')->where('product_key', 'businesshostinglinuxus')->count() >= 2 && Capsule::table('mod_lbh_prices')->where('product_key', 'wordpresshostingindia')->count() >= 2 && Capsule::table('mod_lbh_plans')->where('product_key', 'wordpresshostingindia')->value('plan_name') === 'WP India');
ProductFamily::resetDiscovered();
check('remembered across requests', isset(ProductFamily::keys('business')['businesshostinglinuxus']));
check('a second fetch finds nothing new', !isset((new PriceCatalog($t))->refresh($acc)['discovered']));

section('Usable end to end');
$gen = new ProductGenerator();
$r = $gen->generate('business', 1, [['product_key' => 'businesshostinglinuxus', 'plan_id' => 51, 'plan_name' => 'Biz US']], genOpts(['new_group' => 'Business', 'markup_pct' => '0']));
$p = Capsule::table('tblproducts')->where('id', $r['created'][0])->first();
check('generator: Business US product with its price and Dedicated IP option', $p->servertype === 'lbhbusiness' && $p->configoption2 === 'businesshostinglinuxus:51' && $r['options'] === 1 && str_contains($p->name, 'United States'));
$r = $gen->generate('wordpress', 1, [['product_key' => 'wordpresshostingindia', 'plan_id' => 400100, 'plan_name' => 'WP India']], genOpts(['new_group' => 'WordPress', 'markup_pct' => '0']));
check('generator: WordPress India product', Capsule::table('tblproducts')->where('id', $r['created'][0])->value('configoption2') === 'wordpresshostingindia:400100');

freshDb('lbhbusiness', 'businesshostinglinuxus:51');
Settings::set('discovered_keys', json_encode(['businesshostinglinuxus', 'wordpresshostingindia']));
ProductFamily::resetDiscovered();
$t = transportWithCustomer();
$t->on('POST', 'api/businesshosting/linux/us/add.json', 200, ['entityid' => '9001', 'actionstatus' => 'Success']);
$t->on('GET', 'api/businesshosting/linux/us/orderid.json', 500, ['status' => 'ERROR', 'message' => 'Order Id not found for domain']);
$t->on('GET', 'api/businesshosting/linux/us/details.json', 200, ['entityid' => '9001', 'currentstatus' => 'Active', 'planid' => '51']);
(new Provisioner('business', null, null, $t))->create(params('businesshostinglinuxus:51'));
check('Create: Business Hosting US order goes to businesshosting/linux/us/add.json', ($t->last('POST', 'api/businesshosting/linux/us/add.json')['query']['plan-id'] ?? '') === '51');

freshDb('lbhwordpress', 'wordpresshostingindia:400100');
Settings::set('discovered_keys', json_encode(['wordpresshostingindia']));
ProductFamily::resetDiscovered();
$t = transportWithCustomer();
$t->on('GET', 'restapi/product/wordpresshostingindia', 404, ['status' => 'ERROR', 'message' => 'No order found for domain']);
$t->on('POST', 'restapi/product/wordpresshostingindia/order', 200, ['entityid' => '9002', 'actionstatus' => 'Success']);
$t->on('GET', 'restapi/product/wordpresshostingindia/order', 200, ['entityid' => '9002', 'currentstatus' => 'Active']);
$t->on('GET', 'restapi/product/wordpresshostingindia/details', 200, ['entityid' => '9002', 'currentstatus' => 'Active']);
try {
    (new Provisioner('wordpress', null, null, $t))->create(params('wordpresshostingindia:400100'));
} catch (\Throwable $e) {
}
check('Create: WordPress India order goes to restapi/product/wordpresshostingindia/order', $t->count('POST', 'restapi/product/wordpresshostingindia/order') === 1 && ($t->last('POST', 'restapi/product/wordpresshostingindia/order')['query']['plan-id'] ?? '') === '400100', json_encode($t->trail()));

section('Typed in by hand (Settings > Locations)');
freshDb('lbhbusiness', 'businesshostinglinuxin:41');
Settings::set('extra_product_keys', "businesshostinglinuxuk\nnot-a-key");
ProductFamily::resetDiscovered();
check('a valid extra key becomes a location; junk is ignored', isset(ProductFamily::keys('business')['businesshostinglinuxuk']) && count(ProductFamily::keys('business')) === 2);

done();
