<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Trip;
use App\Services\PreferenceMatcher;
use App\Services\TripPreferenceMatcher;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class TripMatchController extends Controller
{
    public function index(Request $request, TripPreferenceMatcher $matcher, PreferenceMatcher $preferences)
    {
        $input = $request->validate(['page' => 'sometimes|integer|min:1']);
        $page = (int) ($input['page'] ?? 1);
        $answers = $request->user()->travelPreference()->first()?->answers ?? [];
        $complete = $preferences->isComplete($answers);
        $today = CarbonImmutable::today();
        $matches = collect();

        if ($complete) {
            // Rank all eligible trips before taking a page of recommendations.
            foreach (Trip::visible()->with('user')->where('user_id', '!=', $request->user()->id)
                ->whereDate('start_date', '>=', $today->toDateString())->lazyById(200) as $trip) {
                $score = $matcher->compare($answers, $trip, $today);
                if ($score['matched_count'] > 0) {
                    $matches->push([
                        'id' => $trip->id,
                        'title' => $trip->title,
                        'destination' => $trip->destination,
                        'start_date' => $trip->start_date->format('Y-m-d'),
                        'end_date' => $trip->end_date->format('Y-m-d'),
                        'budget' => $trip->budget,
                        'travel_style' => $trip->travel_style,
                        'status' => $trip->status,
                        'duration_days' => $trip->duration_days,
                        'organizer' => [
                            'id' => $trip->user->id,
                            'name' => $trip->user->name,
                            'profile_photo_url' => $trip->user->profile_photo_url,
                        ],
                        ...$score,
                    ]);
                }
            }
        }

        $matches = $matches->sortBy([['matched_count', 'desc'], ['start_date', 'asc'], ['id', 'asc']])->values();
        $others = $matches->slice(1)->values();
        $lastPage = max(1, (int) ceil($others->count() / 6));
        $page = min($page, $lastPage);

        return response()->json([
            'preferences_complete' => $complete,
            'best_match' => $matches->first(),
            'data' => $others->forPage($page, 6)->values(),
            'meta' => ['current_page' => $page, 'last_page' => $lastPage, 'total' => $matches->count()],
        ]);
    }
}
