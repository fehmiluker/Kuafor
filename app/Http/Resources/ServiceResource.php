<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'category' => $this->whenLoaded('category', fn () => $this->category ? ['id' => $this->category->id, 'name' => $this->category->name, 'slug' => $this->category->slug] : null),
            'duration_minutes' => (int) ($this->effective_duration ?? $this->duration_minutes),
            'buffer_minutes' => $this->buffer_minutes,
            'price' => ($this->effective_price ?? $this->base_price)?->jsonSerialize(),
            'active' => $this->active,
            'branch_ids' => $this->whenLoaded('branchServices', fn () => $this->branchServices->where('active', true)->pluck('branch_id')->values()),
        ];
    }
}
