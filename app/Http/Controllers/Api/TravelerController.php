<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DiscoveryRequest;
use App\Http\Resources\TravelerResource;
use App\Models\User;
use App\Services\TripConversationAccess;
use Illuminate\Http\Request;

class TravelerController extends Controller
{
    public function index(DiscoveryRequest $request)
    {
        $f = $request->validated();
        $query = User::discoverable()->with('travelPreference');
        if (isset($f['min_age'])) {
            $query->whereDate('date_of_birth', '<=', today()->subYears((int) $f['min_age'])->toDateString());
        }
        if (isset($f['max_age'])) {
            $query->whereDate('date_of_birth', '>', today()->subYears((int) $f['max_age'] + 1)->toDateString());
        }
        $preferenceFilters = array_intersect_key($f, array_flip([
            'destination', 'date', 'travel_style', 'min_budget', 'max_budget', 'min_duration', 'max_duration',
        ]));
        if (count(array_filter($preferenceFilters, fn ($v) => $v !== null && $v !== ''))) {
            $query->whereHas('travelPreference', function ($q) use ($f) {
                if (! empty($f['destination'])) {
                    $q->whereJsonContains('preferred_destinations', $f['destination']);
                }
                if (! empty($f['travel_style'])) {
                    $q->where('travel_style', $f['travel_style']);
                }
                if (! empty($f['date'])) {
                    $q->whereDate('travel_start', '<=', $f['date'])->whereDate('travel_end', '>=', $f['date']);
                }
                foreach (['min_duration' => '>=', 'max_duration' => '<='] as $key => $operator) {
                    if (isset($f[$key])) {
                        $q->where('duration_days', $operator, $f[$key]);
                    }
                }
                if (isset($f['min_budget']) || isset($f['max_budget'])) {
                    $q->where(fn ($q) => $q->whereNotNull('min_budget')->orWhereNotNull('max_budget'));
                }
                if (isset($f['min_budget'])) {
                    $q->where(fn ($q) => $q->whereNull('max_budget')->orWhere('max_budget', '>=', $f['min_budget']));
                }
                if (isset($f['max_budget'])) {
                    $q->where(fn ($q) => $q->whereNull('min_budget')->orWhere('min_budget', '<=', $f['max_budget']));
                }
            });
        }

        return TravelerResource::collection($query->orderBy('id')->paginate($f['per_page'] ?? 12)->withQueryString());
    }

    public function show(Request $request, User $user, TripConversationAccess $conversationAccess)
    {
        abort_if($user->role !== 'user' || $user->is_blocked, 404);
        $viewer = $request->user('sanctum');
        $user->setAttribute('can_message', $viewer
            ? $conversationAccess->allows([$viewer->id, $user->id])
            : false);

        return new TravelerResource($user->load('travelPreference'));
    }
}
