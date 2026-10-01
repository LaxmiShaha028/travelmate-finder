<?php

namespace App\Services;

use App\Models\TravelRequest;
use App\Models\User;
use App\Notifications\TravelActivityNotification;
use Illuminate\Database\Eloquent\Builder;

class TripReminderSender
{
    public function sendForTomorrow(): int
    {
        $date = today()->addDay()->toDateString();
        $sent = 0;

        $this->acceptedRequestsFor($date)->chunkById(100, function ($requests) use (&$sent) {
            foreach ($requests as $travelRequest) {
                $sent += $this->sendToTraveler($travelRequest->user, $travelRequest->trip) ? 1 : 0;
            }
        });

        return $sent;
    }

    public function sendForUser(User $user): int
    {
        $date = today()->addDay()->toDateString();
        $requests = $this->acceptedRequestsFor($date)->where('user_id', $user->id)->get();
        $sent = 0;

        foreach ($requests as $travelRequest) {
            $sent += $this->sendToTraveler($user, $travelRequest->trip) ? 1 : 0;
        }

        return $sent;
    }

    private function acceptedRequestsFor(string $date): Builder
    {
        return TravelRequest::query()
            ->where('status', 'accepted')
            ->whereHas('trip', fn ($query) => $query->where('status', 'open')->whereDate('start_date', $date))
            ->with(['user', 'trip.user']);
    }

    private function sendToTraveler(User $traveler, $trip): bool
    {
        if ($traveler->is_blocked || $traveler->role !== 'user' || $trip->user->is_blocked) {
            return false;
        }

        $alreadySent = $traveler->notifications()
            ->where('type', TravelActivityNotification::class)
            ->where('data->event_type', 'trip_reminder')
            ->where('data->trip_id', $trip->id)
            ->exists();

        if ($alreadySent) {
            return false;
        }

        $traveler->notify(new TravelActivityNotification(
            'trip_reminder',
            'Trip reminder',
            "Your trip to {$trip->destination} starts tomorrow.",
            [
                'trip_id' => $trip->id,
                'trip_title' => $trip->title,
                'destination' => $trip->destination,
                'start_date' => $trip->start_date->format('Y-m-d'),
                'url' => '/trips/'.$trip->id,
            ],
        ));

        return true;
    }
}