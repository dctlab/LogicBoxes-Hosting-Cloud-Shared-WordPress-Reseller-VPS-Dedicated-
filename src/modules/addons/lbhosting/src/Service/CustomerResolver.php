<?php

declare(strict_types=1);

namespace LbHosting\Service;

use LbHosting\Api\ApiException;
use LbHosting\Api\LogicBoxesClient;

/**
 * Finds the LogicBoxes customer for a WHMCS client (by email/username, the
 * same key the official ResellerClub modules use) or creates one.
 */
final class CustomerResolver
{
    private LogicBoxesClient $client;

    public function __construct(LogicBoxesClient $client)
    {
        $this->client = $client;
    }

    /** @param array<string,mixed> $clientDetails WHMCS $params['clientsdetails'] */
    public function findOrCreate(array $clientDetails, ?int $serviceId = null): int
    {
        $email = trim((string) ($clientDetails['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('The client has no valid email address, which LogicBoxes needs as the customer username.');
        }

        $existing = $this->findByEmail($email, $serviceId);
        if ($existing !== null) {
            return $existing;
        }

        $res = $this->client->post('customers/signup.json', self::signupPayload($clientDetails), 'CustomerSignup', $serviceId);
        $id = is_array($res) ? ($res['customerid'] ?? $res['entityid'] ?? null) : $res;
        if (!is_numeric($id)) {
            throw new \RuntimeException('LogicBoxes customer signup did not return a customer ID.');
        }

        return (int) $id;
    }

    public function findByEmail(string $email, ?int $serviceId = null): ?int
    {
        try {
            $res = $this->client->get('customers/details.json', ['username' => $email], 'CustomerLookup', $serviceId);
        } catch (ApiException $e) {
            if ($e->isNotFound() || stripos($e->getMessage(), 'customer') !== false) {
                return null;
            }
            throw $e;
        }

        return (is_array($res) && isset($res['customerid']) && is_numeric($res['customerid'])) ? (int) $res['customerid'] : null;
    }

    /**
     * @param array<string,mixed> $c
     * @return array<string,mixed>
     */
    public static function signupPayload(array $c): array
    {
        $cc = preg_replace('/\D/', '', (string) ($c['phonecc'] ?? ''));
        $phone = preg_replace('/\D/', '', (string) ($c['phonenumber'] ?? ''));
        if ($cc !== '' && str_starts_with($phone, $cc) && strlen($phone) > strlen($cc) + 6) {
            $phone = substr($phone, strlen($cc));
        }
        $name = trim(($c['firstname'] ?? '') . ' ' . ($c['lastname'] ?? ''));
        $country = strtoupper((string) ($c['countrycode'] ?? $c['country'] ?? ''));
        $lang = strtolower(substr((string) ($c['language'] ?? ''), 0, 2));

        return [
            'username' => (string) $c['email'],
            // Random - the customer never needs it: the module signs them in
            // with a login token. 12 chars (inside every LogicBoxes length rule
            // seen in the wild, 8-15) with lower, upper, digit and one symbol.
            'passwd' => self::randomPassword(),
            'name' => $name !== '' ? $name : (string) $c['email'],
            'company' => trim((string) ($c['companyname'] ?? '')) !== '' ? (string) $c['companyname'] : 'N/A',
            'address-line-1' => trim((string) ($c['address1'] ?? '')) !== '' ? (string) $c['address1'] : 'N/A',
            'address-line-2' => (string) ($c['address2'] ?? ''),
            'city' => trim((string) ($c['city'] ?? '')) !== '' ? (string) $c['city'] : 'N/A',
            'state' => trim((string) ($c['state'] ?? '')) !== '' ? (string) $c['state'] : 'N/A',
            'country' => $country !== '' ? $country : 'US',
            'zipcode' => trim((string) ($c['postcode'] ?? '')) !== '' ? (string) $c['postcode'] : '000000',
            'phone-cc' => $cc !== '' ? $cc : '1',
            'phone' => $phone !== '' ? $phone : '0000000000',
            'lang-pref' => in_array($lang, ['en', 'es', 'fr', 'de', 'it', 'pt', 'nl', 'tr', 'ru', 'zh', 'ja', 'ko'], true) ? $lang : 'en',
        ];
    }

    public static function randomPassword(): string
    {
        $sets = ['abcdefghijkmnpqrstuvwxyz', 'ABCDEFGHJKLMNPQRSTUVWXYZ', '23456789', '@#%*!'];
        $chars = [];
        foreach ($sets as $set) {
            $chars[] = $set[random_int(0, strlen($set) - 1)];
        }
        $all = $sets[0] . $sets[1] . $sets[2]; // only ONE symbol - some panels cap special characters
        while (count($chars) < 12) {
            $chars[] = $all[random_int(0, strlen($all) - 1)];
        }
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }
}
