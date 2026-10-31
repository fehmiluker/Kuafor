<?php

namespace App\Http\Resources;

use App\Models\WorkingHour;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BranchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'city' => $this->whenLoaded('city', fn () => $this->city ? ['id' => $this->city->id, 'name' => $this->city->name, 'slug' => $this->city->slug] : null),
            'district' => $this->whenLoaded('district', fn () => $this->district ? ['id' => $this->district->id, 'name' => $this->district->name, 'slug' => $this->district->slug] : null),
            'lat' => $this->lat, 'lng' => $this->lng,
            'map_url' => $this->mapUrl(),
            'timezone' => $this->timezone,
            'status' => $this->status?->value,
            'working_hours' => $this->whenLoaded('workingHours', fn () => $this->workingHours->sortBy('day_of_week')->values()->map(fn ($h) => [
                'day_of_week' => $h->day_of_week, 'day' => WorkingHour::DAY_NAMES[$h->day_of_week],
                'opens' => substr($h->starts_local, 0, 5), 'closes' => substr($h->ends_local, 0, 5),
            ])),
        ];
    }
}
