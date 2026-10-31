<?php

namespace App\Services\Settings;

use App\Models\Branch;
use App\Models\BranchSetting;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\TenantSetting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Typed key/value settings at platform, tenant and branch level.
 * Secret values are stored encrypted (is_encrypted = 1).
 */
final class SettingsRepository
{
    public function tenant(Tenant $tenant): array
    {
        return $this->decodeRows(TenantSetting::withoutGlobalScopes()->where('tenant_id', $tenant->id)->get());
    }

    public function branch(Branch $branch): array
    {
        return $this->decodeRows(BranchSetting::withoutGlobalScopes()->where('branch_id', $branch->id)->get());
    }

    public function platform(): array
    {
        return $this->decodeRows(PlatformSetting::all());
    }

    public function setTenant(Tenant $tenant, array $values, array $secretKeys = []): void
    {
        foreach ($values as $key => $value) {
            $this->upsert(TenantSetting::withoutGlobalScopes(), ['tenant_id' => $tenant->id, 'key' => $key], $value, in_array($key, $secretKeys, true));
        }
    }

    public function setBranch(Branch $branch, array $values, array $secretKeys = []): void
    {
        foreach ($values as $key => $value) {
            $this->upsert(BranchSetting::withoutGlobalScopes(), ['tenant_id' => $branch->tenant_id, 'branch_id' => $branch->id, 'key' => $key], $value, in_array($key, $secretKeys, true));
        }
    }

    public function forgetBranch(Branch $branch, array $keys): void
    {
        BranchSetting::withoutGlobalScopes()->where('branch_id', $branch->id)->whereIn('key', $keys)->delete();
    }

    public function setPlatform(array $values, array $secretKeys = []): void
    {
        foreach ($values as $key => $value) {
            $this->upsert(PlatformSetting::query(), ['key' => $key], $value, in_array($key, $secretKeys, true));
        }
    }

    private function upsert($query, array $identity, mixed $value, bool $secret): void
    {
        [$type, $raw] = match (true) {
            is_bool($value) => ['bool', $value ? '1' : '0'],
            is_int($value) => ['int', (string) $value],
            is_array($value) => ['json', json_encode($value, JSON_UNESCAPED_UNICODE)],
            default => ['string', $value === null ? null : (string) $value],
        };
        if ($secret && $raw !== null) {
            $raw = Crypt::encryptString($raw);
        }
        /** @var Model|null $row */
        $row = (clone $query)->where($identity)->first();
        $attrs = ['value' => $raw, 'type' => $type, 'is_encrypted' => $secret];
        if ($row) {
            $row->forceFill($attrs)->save();
        } else {
            $query->getModel()->newInstance()->forceFill($identity + $attrs)->save();
        }
    }

    private function decodeRows($rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $raw = $row->value;
            if ($row->is_encrypted && $raw !== null) {
                $raw = Crypt::decryptString($raw);
            }
            $out[$row->key] = match ($row->type) {
                'bool' => $raw === '1',
                'int' => (int) $raw,
                'json' => json_decode((string) $raw, true),
                default => $raw,
            };
        }

        return $out;
    }
}
