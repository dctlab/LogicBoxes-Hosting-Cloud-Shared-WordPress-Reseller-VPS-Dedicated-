<?php

declare(strict_types=1);

namespace LbHosting\WHMCS;

use Illuminate\Database\Capsule\Manager as Capsule;
use LbHosting\Catalog\ProductFamily;

/**
 * Builds the same $params array WHMCS passes to a server module, from the
 * database - for work the module starts itself (paid upgrades, hooks, cron)
 * rather than WHMCS.
 *
 * configoptions values follow WHMCS's own convention: quantity and yes/no
 * options give the quantity, dropdown options the sub-option name before "|".
 */
final class ServiceParams
{
    /** @return array<string,mixed> */
    public static function build(int $serviceId): array
    {
        $s = Capsule::table('tblhosting')->where('id', $serviceId)->first();
        if (!$s) {
            throw new \RuntimeException('Service #' . $serviceId . ' not found.');
        }
        $p = Capsule::table('tblproducts')->where('id', $s->packageid)->first();
        if (!$p || ProductFamily::forModule((string) $p->servertype) === null) {
            throw new \RuntimeException('Service #' . $serviceId . ' does not use a LogicBoxes Hosting module.');
        }
        $c = Capsule::table('tblclients')->where('id', $s->userid)->first();
        $params = [
            'serviceid' => $serviceId,
            'userid' => (int) $s->userid,
            'pid' => (int) $s->packageid,
            'domain' => (string) ($s->domain ?? ''),
            'configoptions' => self::configOptions($serviceId),
            'clientsdetails' => [
                'userid' => (int) $s->userid,
                'email' => (string) ($c->email ?? ''),
                'firstname' => (string) ($c->firstname ?? ''),
                'lastname' => (string) ($c->lastname ?? ''),
                'companyname' => (string) ($c->companyname ?? ''),
                'address1' => (string) ($c->address1 ?? ''),
                'address2' => (string) ($c->address2 ?? ''),
                'city' => (string) ($c->city ?? ''),
                'state' => (string) ($c->state ?? ''),
                'postcode' => (string) ($c->postcode ?? ''),
                'countrycode' => (string) ($c->country ?? ''),
                'phonenumber' => (string) ($c->phonenumber ?? ''),
                'language' => (string) ($c->language ?? ''),
            ],
        ];
        for ($i = 1; $i <= 5; $i++) {
            $params['configoption' . $i] = (string) ($p->{'configoption' . $i} ?? '');
        }

        return $params;
    }

    public static function family(int $serviceId): string
    {
        $module = (string) Capsule::table('tblhosting')
            ->join('tblproducts', 'tblproducts.id', '=', 'tblhosting.packageid')
            ->where('tblhosting.id', $serviceId)->value('tblproducts.servertype');
        $family = ProductFamily::forModule($module);
        if ($family === null) {
            throw new \RuntimeException('Service #' . $serviceId . ' does not use a LogicBoxes Hosting module.');
        }

        return $family;
    }

    /** @return array<string,mixed> option name (before "|") => value */
    public static function configOptions(int $serviceId): array
    {
        $out = [];
        foreach (Capsule::table('tblhostingconfigoptions')->where('relid', $serviceId)->get() as $sel) {
            $opt = Capsule::table('tblproductconfigoptions')->where('id', $sel->configid)->first();
            if (!$opt) {
                continue;
            }
            $name = trim(explode('|', (string) $opt->optionname)[0]);
            $type = (int) $opt->optiontype;
            if ($type === 3 || $type === 4) {
                $out[$name] = (int) $sel->qty;
            } else {
                $sub = (string) Capsule::table('tblproductconfigoptionssub')->where('id', $sel->optionid)->value('optionname');
                $out[$name] = trim(explode('|', $sub)[0]);
            }
        }

        return $out;
    }
}
