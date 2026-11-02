<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where a home visit takes place. Address text is encrypted at rest, shown only on
 * the appointment detail (staff with access) and to the customer, and purged
 * (address_line/address_detail = null) some days after the visit.
 */
class AppointmentAddress extends Model
{
    use BelongsToTenant;

    public const PLACE_TYPES = ['home' => 'Ev', 'work' => 'İşyeri'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['address_line' => 'encrypted', 'address_detail' => 'encrypted', 'purged_at' => 'immutable_datetime'];
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function placeLabel(): string
    {
        return self::PLACE_TYPES[$this->place_type] ?? 'Adres';
    }

    /** "İşyeri · Nilüfer, Bursa" — safe for lists (no street address). */
    public function shortLabel(): string
    {
        $d = $this->district;

        return $this->placeLabel().' · '.collect([$d?->name, $d?->city?->name])->filter()->implode(', ');
    }

    public function mapUrl(): ?string
    {
        if (! $this->address_line) {
            return null;
        }
        $d = $this->district;

        return 'https://www.openstreetmap.org/search?query='.urlencode(collect([$this->address_line, $d?->name, $d?->city?->name])->filter()->implode(', '));
    }
}
