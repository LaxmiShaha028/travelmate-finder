<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TravelPreference;
use App\Models\Trip;
use App\Models\User;

class DiscoveryController extends Controller
{
    public function stats()
    {
        return response()->json(['data' => [
            'travelers' => User::discoverable()->count(),
            'trips' => Trip::visible()->count(),
            'destinations' => Trip::visible()->distinct()->count('destination'),
        ]]);
    }

    public function filters()
    {
        $preferences = TravelPreference::whereHas('user', fn ($q) => $q->discoverable());
        $destinations = (clone $preferences)->pluck('preferred_destinations')->flatten()
            ->merge(Trip::visible()->pluck('destination'))->filter()->unique()->sort()->values();
        $styles = (clone $preferences)->pluck('travel_style')
            ->merge(Trip::visible()->pluck('travel_style'))->filter()->unique()->sort()->values();

        return response()->json(['data' => [
            'destinations' => $destinations,
            'travel_styles' => $styles,
            'currency' => 'BDT',
            'age_ranges' => [
                ['label' => 'Any age', 'params' => (object) []],
                ['label' => '18–24', 'params' => ['min_age' => 18, 'max_age' => 24]],
                ['label' => '25–34', 'params' => ['min_age' => 25, 'max_age' => 34]],
                ['label' => '35–44', 'params' => ['min_age' => 35, 'max_age' => 44]],
                ['label' => '45+', 'params' => ['min_age' => 45]],
            ],
            'duration_ranges' => [
                ['label' => 'Any duration', 'params' => (object) []],
                ['label' => '1–2 days', 'params' => ['min_duration' => 1, 'max_duration' => 2]],
                ['label' => '3–4 days', 'params' => ['min_duration' => 3, 'max_duration' => 4]],
                ['label' => '5–7 days', 'params' => ['min_duration' => 5, 'max_duration' => 7]],
                ['label' => '1–2 weeks', 'params' => ['min_duration' => 7, 'max_duration' => 14]],
                ['label' => '2+ weeks', 'params' => ['min_duration' => 15]],
            ],
            'budget_ranges' => [
                ['label' => 'Any budget', 'params' => (object) []],
                ['label' => 'Under ৳5,000', 'params' => ['max_budget' => 4999.99]],
                ['label' => '৳5,000 – ৳10,000', 'params' => ['min_budget' => 5000, 'max_budget' => 10000]],
                ['label' => '৳10,000 – ৳20,000', 'params' => ['min_budget' => 10000, 'max_budget' => 20000]],
                ['label' => '৳20,000+', 'params' => ['min_budget' => 20000]],
            ],
        ]]);
    }
}
