<?php

declare(strict_types=1);

namespace LbHosting\Accounts;

use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * CRUD for mod_lbh_accounts. API keys are stored with WHMCS's own
 * encrypt()/decrypt() helpers (keyed by cc_encryption_hash in
 * configuration.php) - never in plaintext.
 */
final class AccountRepository
{
    private const TABLE = 'mod_lbh_accounts';

    public function find(int $id): ?Account
    {
        $row = Capsule::table(self::TABLE)->where('id', $id)->first();

        return $row ? Account::fromRow($row, self::decryptKey((string) $row->api_key_enc)) : null;
    }

    public function findActive(int $id): ?Account
    {
        $a = $this->find($id);

        return ($a && $a->active) ? $a : null;
    }

    public function default(): ?Account
    {
        $row = Capsule::table(self::TABLE)->where('status', 'active')->orderBy('is_default', 'desc')->orderBy('id')->first();

        return $row ? Account::fromRow($row, self::decryptKey((string) $row->api_key_enc)) : null;
    }

    /** Account for a product: configured one if active, else the default. */
    public function resolve(?int $id): Account
    {
        $acc = $id ? $this->findActive($id) : null;
        $acc = $acc ?? $this->default();
        if (!$acc) {
            throw new \RuntimeException('No active LogicBoxes API account is configured. Add one under Addons > LogicBoxes Hosting > API Accounts.');
        }

        return $acc;
    }

    /** @return object[] rows without the key column */
    public function listRows(): array
    {
        return Capsule::table(self::TABLE)
            ->select(['id', 'name', 'brand', 'reseller_id', 'test_mode', 'api_url', 'branding_url', 'nameservers', 'is_default', 'status', 'last_test_at', 'last_test_ok', 'last_test_error'])
            ->orderBy('name')->get()->all();
    }

    /** @return array<int,string> id => label, for dropdowns */
    public function options(): array
    {
        $out = [];
        foreach ($this->listRows() as $r) {
            $out[(int) $r->id] = $r->name . ($r->test_mode ? ' [TEST]' : '') . ($r->status !== 'active' ? ' [disabled]' : '');
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $data validated input; api_key omitted/empty = keep existing
     */
    public function save(?int $id, array $data): int
    {
        $row = [
            'name' => $data['name'],
            'brand' => $data['brand'],
            'reseller_id' => $data['reseller_id'],
            'test_mode' => $data['test_mode'] ? 1 : 0,
            'api_url' => $data['api_url'],
            'branding_url' => $data['branding_url'],
            'nameservers' => $data['nameservers'],
            'is_default' => $data['is_default'] ? 1 : 0,
            'status' => $data['status'],
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        if (!empty($data['api_key'])) {
            $row['api_key_enc'] = self::encryptKey((string) $data['api_key']);
        }

        if ($row['is_default']) {
            Capsule::table(self::TABLE)->update(['is_default' => 0]);
        }

        if ($id) {
            Capsule::table(self::TABLE)->where('id', $id)->update($row);

            return $id;
        }
        if (empty($row['api_key_enc'])) {
            throw new \InvalidArgumentException('An API key is required for a new account.');
        }
        $row['created_at'] = $row['updated_at'];
        if (!Capsule::table(self::TABLE)->where('is_default', 1)->exists()) {
            $row['is_default'] = 1;
        }

        return (int) Capsule::table(self::TABLE)->insertGetId($row);
    }

    public function delete(int $id): void
    {
        if (Capsule::table('mod_lbh_services')->where('account_id', $id)->exists()) {
            throw new \RuntimeException('This account still has linked services. Disable it instead, or move the services first.');
        }
        Capsule::table(self::TABLE)->where('id', $id)->delete();
    }

    public function recordTest(int $id, bool $ok, string $error = '', ?string $brandingUrl = null): void
    {
        $upd = [
            'last_test_at' => date('Y-m-d H:i:s'),
            'last_test_ok' => $ok ? 1 : 0,
            'last_test_error' => $ok ? null : substr($error, 0, 1000),
        ];
        if ($brandingUrl !== null && $brandingUrl !== '') {
            $current = Capsule::table(self::TABLE)->where('id', $id)->value('branding_url');
            if (empty($current)) {
                $upd['branding_url'] = $brandingUrl;
            }
        }
        Capsule::table(self::TABLE)->where('id', $id)->update($upd);
    }

    private static function encryptKey(string $key): string
    {
        if (!function_exists('encrypt')) {
            throw new \RuntimeException('WHMCS encrypt() helper is unavailable.');
        }

        return (string) encrypt($key);
    }

    private static function decryptKey(string $enc): string
    {
        if ($enc === '' || !function_exists('decrypt')) {
            return '';
        }

        return (string) decrypt($enc);
    }
}
