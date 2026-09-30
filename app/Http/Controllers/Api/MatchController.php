<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PreferenceMatcher;
use Illuminate\Http\Request;

class MatchController extends Controller
{
    public function index(Request $request, PreferenceMatcher $matcher)
    {
        $input = $request->validate(['page' => 'sometimes|integer|min:1']);
        $page = (int) ($input['page'] ?? 1);
        $mine = $request->user()->travelPreference()->first()?->answers ?? [];
        $complete = $matcher->isComplete($mine);
        $matches = collect();

        if ($complete) {
            // Scan all eligible travelers before pagination so the best match is global.
            foreach (User::discoverable()->where('id', '!=', $request->user()->id)
                ->whereHas('travelPreference')->with('travelPreference')->lazyById(200) as $person) {
                $answers = $person->travelPreference->answers ?? [];
                if (! $matcher->isComplete($answers)) {
                    continue;
                }
                $score = $matcher->compare($mine, $answers);
                if ($score['matched_count'] > 0) {
                    $matches->push([
                        'id' => $person->id,
                        'name' => $person->name,
                        'profile_photo_url' => $person->profile_photo_url,
                        ...$score,
                    ]);
                }
            }
        }

        $matches = $matches->sortBy([['matched_count', 'desc'], ['id', 'asc']])->values();
        // The featured person is separate and never duplicated in the card list.
        $others = $matches->slice(1)->values();
        $perPage = 6;

        return response()->json([
            'preferences_complete' => $complete,
            'best_match' => $matches->first(),
            'data' => $others->forPage($page, $perPage)->values(),
            'meta' => [
                'current_page' => $page,
                'last_page' => max(1, (int) ceil($others->count() / $perPage)),
                'total' => $matches->count(),
            ],
        ]);
    }
}
