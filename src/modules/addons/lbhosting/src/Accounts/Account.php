<?php

declare(strict_types=1);

namespace LbHosting\Accounts;

use LbHosting\Api\HostingApi;
use LbHosting\Api\HttpTransport;
use LbHosting\Api\LogicBoxesClient;

/**
 * One LogicBoxes reseller account (ResellerClub, NetEarthOne, or any other
 * LogicBoxes-powered brand). The API key is only ever held decrypted in memory.
 */
final class Account
{
    public const BRANDS = [
        'resellerclub' => 'ResellerClub',
        'netearthone' => 'NetEarthOne',
        'logicboxes' => 'Other LogicBoxes brand',
    ];

    public int $id;
    public string $name;
    public string $brand;
    public string $resellerId;
    public string $apiKey;
    public bool $testMode;
    public string $apiUrl;
    public string $brandingUrl;
    /** Reseller-branded name servers (one per line / comma), used by the NS automation. */
    public string $nameservers;
    public bool $isDefault;
    public bool $active;

    public static function fromRow(object $row, string $plainKey): self
    {
        $a = new self();
        $a->id = (int) $row->id;
        $a->name = (string) $row->name;
        $a->brand = (string) $row->brand;
        $a->resellerId = (string) $row->reseller_id;
        $a->apiKey = $plainKey;
        $a->testMode = (bool) $row->test_mode;
        $a->apiUrl = (string) ($row->api_url ?? '');
        $a->brandingUrl = (string) ($row->branding_url ?? '');
        $a->nameservers = (string) ($row->nameservers ?? '');
        $a->isDefault = (bool) $row->is_default;
        $a->active = ($row->status ?? 'active') === 'active';

        return $a;
    }

    public function client(?HttpTransport $transport = null): LogicBoxesClient
    {
        return new LogicBoxesClient($this->resellerId, $this->apiKey, $this->testMode, $this->apiUrl !== '' ? $this->apiUrl : null, $transport);
    }

    public function api(?HttpTransport $transport = null): HostingApi
    {
        return new HostingApi($this->client($transport));
    }

    /** @return string[] */
    public function nameserverList(): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', strtolower($this->nameservers)) ?: [] as $ns) {
            $ns = rtrim(trim($ns), '.');
            if ($ns !== '' && preg_match('/^([a-z0-9-]+\.)+[a-z]{2,}$/', $ns)) {
                $out[] = $ns;
            }
        }

        return array_values(array_unique($out));
    }

    public function label(): string
    {
        return $this->name . ' (' . (self::BRANDS[$this->brand] ?? $this->brand) . ($this->testMode ? ', TEST' : '') . ')';
    }
}
