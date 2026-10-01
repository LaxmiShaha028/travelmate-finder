<?php

namespace App\Services;

use App\Models\Trip;
use App\Models\User;

class TripConversationAccess
{
    public function allows(array $userIds): bool
    {
        $memberIds = collect($userIds)->map(fn ($id) => (int) $id)->unique()->values();
        if ($memberIds->count() < 2 || User::whereKey($memberIds)->where('role', 'user')->where('is_blocked', false)->count() !== $memberIds->count()) {
            return false;
        }

        foreach ($memberIds as $ownerId) {
            $travelerIds = $memberIds->reject(fn ($id) => $id === $ownerId)->values();
            $acceptedCount = $travelerIds->count();

            if (Trip::query()->where('user_id', $ownerId)
                ->whereHas('travelRequests', fn ($query) => $query
                    ->whereIn('user_id', $travelerIds)
                    ->where('status', 'accepted'), '=', $acceptedCount)
                ->exists()) {
                return true;
            }
        }

        return false;
    }
}