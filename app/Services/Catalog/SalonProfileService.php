<?php

namespace App\Services\Catalog;

use App\Models\Tenant;
use App\Models\TenantMedia;
use App\Rules\Phone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class SalonProfileService
{
    public function __construct(private readonly TenantCatalogService $images) {}

    public static function rules(?Tenant $tenant = null): array
    {
        $image = ['image', 'mimes:jpeg,png,webp', 'max:4096', 'dimensions:min_width=100,min_height=100,max_width=5000,max_height=5000'];

        return [
            'cover' => ['nullable', ...$image],
            'logo' => ['nullable', ...$image],
            'gallery' => ['nullable', 'array', 'max:12'],
            'gallery.*' => ['required', ...$image],
            'remove_media' => ['nullable', 'array', 'max:12'],
            'remove_media.*' => ['integer', 'distinct', Rule::exists('tenant_media', 'id')->where('tenant_id', $tenant?->id ?? 0)],
            'remove_cover' => ['nullable', 'boolean'],
            'remove_logo' => ['nullable', 'boolean'],
            'whatsapp_phone' => ['nullable', new Phone],
            'instagram_url' => ['nullable', 'url:https', 'max:500', function ($attribute, $value, $fail) {
                if (! self::socialUrl($value, 'instagram')) {
                    $fail('Geçerli bir Instagram profil bağlantısı girin.');
                }
            }],
            'facebook_url' => ['nullable', 'url:https', 'max:500', function ($attribute, $value, $fail) {
                if (! self::socialUrl($value, 'facebook')) {
                    $fail('Geçerli bir Facebook sayfa bağlantısı girin.');
                }
            }],
        ];
    }

    private static function socialUrl(?string $url, string $network): bool
    {
        $hosts = $network === 'instagram' ? ['instagram.com', 'www.instagram.com'] : ['facebook.com', 'www.facebook.com', 'm.facebook.com', 'fb.com', 'www.fb.com'];

        return $url && filter_var($url, FILTER_VALIDATE_URL) && parse_url($url, PHP_URL_SCHEME) === 'https'
            && in_array(strtolower((string) parse_url($url, PHP_URL_HOST)), $hosts, true)
            && ! parse_url($url, PHP_URL_USER) && ! parse_url($url, PHP_URL_PASS);
    }

    public static function links(Tenant $tenant): array
    {
        $contact = $tenant->settings['public_contact'] ?? [];
        $phone = $contact['whatsapp_phone'] ?? null;

        return [
            'whatsapp' => is_string($phone) && preg_match('/^\+[1-9][0-9]{7,14}$/', $phone) ? 'https://wa.me/'.substr($phone, 1) : null,
            'instagram' => self::socialUrl($contact['instagram_url'] ?? null, 'instagram') ? $contact['instagram_url'] : null,
            'facebook' => self::socialUrl($contact['facebook_url'] ?? null, 'facebook') ? $contact['facebook_url'] : null,
        ];
    }

    public function save(Tenant $tenant, array $data): void
    {
        $newPaths = [];
        $oldPaths = [];
        try {
            DB::transaction(function () use ($tenant, $data, &$newPaths, &$oldPaths) {
                $remove = $data['remove_media'] ?? [];
                $existing = TenantMedia::withoutGlobalScopes()->where('tenant_id', $tenant->id)->lockForUpdate()->get();
                if ($existing->whereNotIn('id', $remove)->count() + count($data['gallery'] ?? []) > 12) {
                    throw ValidationException::withMessages(['gallery' => 'En fazla 12 galeri fotoğrafı yükleyebilirsiniz.']);
                }
                foreach (['cover' => 'cover_path', 'logo' => 'logo_path'] as $field => $column) {
                    if (! empty($data['remove_'.$field])) {
                        $oldPaths[] = $tenant->{$column};
                        $tenant->{$column} = null;
                    }
                    if (! empty($data[$field])) {
                        $oldPaths[] = $tenant->{$column};
                        $path = $this->images->storeImage($data[$field], "tenants/{$tenant->id}/media");
                        $newPaths[] = $path;
                        $tenant->{$column} = $path;
                    }
                }
                $contact = [];
                foreach (['whatsapp_phone', 'instagram_url', 'facebook_url'] as $key) {
                    $contact[$key] = $data[$key] ?? null;
                }
                $tenant->settings = array_replace($tenant->settings ?? [], ['public_contact' => $contact]);
                $tenant->save();
                foreach ($existing->whereIn('id', $remove) as $media) {
                    $oldPaths[] = $media->path;
                    $media->delete();
                }
                $sort = (int) $existing->max('sort_order') + 1;
                foreach ($data['gallery'] ?? [] as $file) {
                    $path = $this->images->storeImage($file, "tenants/{$tenant->id}/media");
                    $newPaths[] = $path;
                    TenantMedia::withoutGlobalScopes()->create([
                        'tenant_id' => $tenant->id, 'path' => $path, 'mime' => 'image/jpeg',
                        'size' => Storage::disk('public')->size($path), 'alt_text' => $tenant->name.' iç mekân fotoğrafı',
                        'visibility' => 'public', 'sort_order' => $sort++,
                    ]);
                }
                DB::afterCommit(fn () => Storage::disk('public')->delete(array_filter($oldPaths)));
            });
        } catch (\Throwable $e) {
            Storage::disk('public')->delete($newPaths);
            throw $e;
        }
    }
}
