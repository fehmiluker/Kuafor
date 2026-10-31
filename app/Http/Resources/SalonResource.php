<?php

namespace App\Http\Resources;

use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class SalonResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'logo_url' => $this->logo_path ? Storage::disk('public')->url($this->logo_path) : null,
            'cover_url' => $this->cover_path ? Storage::disk('public')->url($this->cover_path) : null,
            'rating' => ['average' => $this->rating_avg !== null ? (float) $this->rating_avg : null, 'count' => (int) $this->rating_count],
            'min_price' => $this->min_service_price !== null ? Money::parse((string) $this->min_service_price)->jsonSerialize() : null,
            'branches' => BranchResource::collection($this->whenLoaded('branches')),
            'url' => route('salon.show', ['slug' => $this->slug]),
        ];
    }
}
