<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/** Platform-level banner (not tenant-scoped). */
class AdCampaign extends Model
{
    /** Where a banner can appear => admin label. */
    public const PLACEMENTS = [
        'panel_dashboard' => 'İşletme paneli > Özet (salon sahipleri)',
        'home' => 'Ana sayfa',
        'search' => 'Salon arama sonuçları',
    ];

    protected $guarded = ['id', 'impressions', 'clicks'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'active' => 'boolean',
        ];
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /** Active and inside its date window right now. */
    public function scopeRunning(Builder $q): Builder
    {
        $now = now();

        return $q->where('active', true)
            ->where(fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>', $now));
    }

    public function isRunning(): bool
    {
        return $this->active && (! $this->starts_at || $this->starts_at->isPast()) && (! $this->ends_at || $this->ends_at->isFuture());
    }

    public function imageUrl(): string
    {
        return Storage::disk('public')->url($this->image_path);
    }

    public function mobileImageUrl(): ?string
    {
        return $this->image_mobile_path ? Storage::disk('public')->url($this->image_mobile_path) : null;
    }

    public function ctr(): ?float
    {
        return $this->impressions > 0 ? round($this->clicks / $this->impressions * 100, 2) : null;
    }
}
