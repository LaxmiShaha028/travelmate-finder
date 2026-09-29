<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class TripResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'destination' => $this->destination,
            'description' => $this->description,
            'start_date' => $this->start_date->format('Y-m-d'),
            'end_date' => $this->end_date->format('Y-m-d'),
            'duration_days' => $this->duration_days,
            'budget' => $this->budget,
            'currency' => 'BDT',
            'travel_style' => $this->travel_style,
            'max_travelers' => $this->max_travelers,
            'status' => $this->status,
            'organizer' => new TravelerResource($this->whenLoaded('user')),
        ];
    }
}
