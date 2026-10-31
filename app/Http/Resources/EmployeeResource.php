<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class EmployeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'display_name' => $this->display_name,
            'bio' => $this->bio,
            'photo_url' => $this->photo ? Storage::disk('public')->url($this->photo) : null,
            'status' => $this->status?->value,
            'branch_ids' => $this->whenLoaded('branches', fn () => $this->branches->pluck('id')),
            'service_ids' => $this->whenLoaded('services', fn () => $this->services->pluck('id')->unique()->values()),
        ];
    }
}
