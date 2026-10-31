<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'monthly_price' => $this->monthly_price->jsonSerialize(),
            'yearly_price' => $this->yearly_price->jsonSerialize(),
            'trial_days' => $this->trial_days,
            'max_branches' => $this->max_branches,
            'max_employees' => $this->max_employees,
            'features' => $this->whenLoaded('features', fn () => $this->features->mapWithKeys(fn ($f) => [$f->feature_key => $f->enabled])),
            'active' => $this->active,
            'public' => $this->public,
        ];
    }
}
