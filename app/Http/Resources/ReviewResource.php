<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Public review: masked author name, no phone/email/appointment ids. */
class ReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'rating' => $this->rating,
            'employee_rating' => $this->employee_rating,
            'employee' => $this->whenLoaded('employee', fn () => $this->employee?->display_name),
            'body' => $this->body,
            'author' => $this->authorDisplayName(),
            'verified' => $this->verified_at !== null,
            'status' => $this->status->value,
            'reply' => $this->whenLoaded('reply', fn () => $this->reply ? ['body' => $this->reply->body, 'created_at' => $this->reply->updated_at?->toIso8601ZuluString()] : null),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
