<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\MessageRequest;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TripConversationAccess
{
    public function tripMembers(Trip $trip): array
    {
        return User::where('is_blocked', false)->where(function ($query) use ($trip) {
            $query->whereKey($trip->user_id)->orWhereIn('id', $trip->travelRequests()->where('status', 'accepted')->select('user_id'));
        })->pluck('id')->all();
    }

    public function syncTrip(Trip $trip, bool $create = false): ?Conversation
    {
        return DB::transaction(function () use ($trip, $create) {
            $trip = Trip::whereKey($trip->id)->lockForUpdate()->firstOrFail();
            $conversation = Conversation::where('trip_id', $trip->id)->first();
            if (! $conversation && $create && $trip->max_travelers > 1) {
                $conversation = Conversation::create(['trip_id' => $trip->id, 'type' => 'group', 'name' => $trip->title, 'messages' => []]);
            }
            if ($conversation) {
                $conversation->update(['name' => $trip->title]);
                $conversation->users()->sync($this->tripMembers($trip));
            }

            return $conversation;
        });
    }

    public function forConversation(Conversation $conversation, int $viewerId): bool
    {
        if (! $conversation->trip_id) {
            return $this->allows($conversation->users()->pluck('users.id')->all());
        }
        $trip = Trip::find($conversation->trip_id);
        if (! $trip || $trip->max_travelers < 2 || $trip->user->is_blocked || in_array($trip->status, ['hidden', 'cancelled'], true)) {
            return false;
        }
        if (! in_array($viewerId, $this->tripMembers($trip), true)) {
            return false;
        }
        $this->syncTrip($trip);

        return true;
    }

    public function allows(array $userIds): bool
    {
        $ids = collect($userIds)->map(fn ($id) => (int) $id)->unique()->values();
        if ($ids->count() < 2 || User::whereKey($ids)->where('is_blocked', false)->count() !== $ids->count()) {
            return false;
        }
        foreach ($ids as $i => $first) {
            foreach ($ids->slice($i + 1) as $second) {
                if (! MessageRequest::where('status', 'accepted')->whereIn('sender_id', [$first, $second])->whereIn('recipient_id', [$first, $second])->exists()) {
                    return false;
                }
            }
        }

        return true;
    }
}
