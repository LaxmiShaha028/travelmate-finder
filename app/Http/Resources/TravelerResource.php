<?php

namespace App\Http\Resources;

use Carbon\Carbon;
use Illuminate\Http\Resources\Json\JsonResource;

class TravelerResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'bio' => $this->bio,
            'profile_photo_url' => $this->profile_photo_url,
            'age' => $this->date_of_birth ? Carbon::parse($this->date_of_birth)->age : null,
            'verification_status' => $this->verification_status,
            'can_message' => (bool) $this->getAttribute('can_message'),
            'preferences' => $this->whenLoaded('travelPreference', function () {
                $p = $this->travelPreference;
                if (! $p) {
                    return null;
                }

                return [
                    'destinations' => $p->preferred_destinations ?? [],
                    'travel_style' => $p->travel_style,
                    'interests' => $p->interests ?? [],
                    'min_budget' => $p->min_budget,
                    'max_budget' => $p->max_budget,
                    'travel_start' => $p->travel_start?->format('Y-m-d'),
                    'travel_end' => $p->travel_end?->format('Y-m-d'),
                    'duration_days' => $p->duration_days,
                    'answers' => $p->answers,
                ];
            }),
        ];
    }
}
