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
            'created_at' => $this->created_at?->toISOString(),
            'destination' => $this->destination,
            'description' => $this->description,
            'start_date' => $this->start_date->format('Y-m-d'),
            'end_date' => $this->end_date->format('Y-m-d'),
            'duration_days' => $this->duration_days,
            'budget' => $this->budget,
            'currency' => 'BDT',
            'travel_style' => $this->travel_style,
            'max_travelers' => $this->max_travelers,
            'accepted_count' => (int) ($this->accepted_count ?? 0),
            'available_spots' => max(0, $this->max_travelers - ($this->accepted_count ?? 0)),
            'request_count' => (int) ($this->travel_requests_count ?? 0),
            'my_request_status' => $this->relationLoaded('travelRequests')
                ? $this->travelRequests->firstWhere('user_id', $request->user('sanctum')?->id)?->status : null,
            'status' => $this->status,
            'organizer' => new TravelerResource($this->whenLoaded('user')),
        ];
    }
}
