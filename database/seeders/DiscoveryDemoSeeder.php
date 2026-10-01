<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DiscoveryDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Demo data can only be seeded in local or testing environments.');
        }
        $destinations = ["Cox's Bazar", 'Sylhet', 'Bandarban', 'Sajek Valley', 'Dhaka', 'Chattogram'];
        $styles = ['Adventure', 'Relaxed', 'Budget', 'Luxury', 'Backpacking', 'Photography'];
        $names = ['Amina', 'Rafi', 'Nadia', 'Sami', 'Maya', 'Arif',
            'Lina', 'Fahim', 'Sara', 'Rohan', 'Tania', 'Imran'];
        foreach ($names as $i => $name) {
            // Stable demo-only email keys make reruns safe without touching real accounts.
            $user = User::firstOrCreate(['email' => 'traveler-'.($i + 1).'@demo.travelmate.test'], [
                'name' => $name,
                'password' => Str::random(64),
                'date_of_birth' => today()->subYears(20 + $i * 3)->format('Y-m-d'),
                'bio' => 'Enjoys discovering new places and meeting travel companions.',
            ]);
            $destination = $destinations[$i % count($destinations)];
            $style = $styles[$i % count($styles)];
            $start = today()->addDays(7 + $i * 2);
            $duration = [2, 4, 6, 10, 16, 3][$i % 6];
            $end = $start->copy()->addDays($duration - 1);
            $budget = 2500 + $i * 2500;
            $user->travelPreference()->updateOrCreate([], [
                'answers' => ['destination' => $destination, 'date' => $start->format('Y-m-d'),
                    'budget' => 'BDT '.$budget, 'style' => $style, 'companions' => 'Small Groups', 'interests' => 'Photography'],
                'preferred_destinations' => [$destination], 'travel_style' => $style, 'interests' => ['Photography'],
                'min_budget' => $budget, 'max_budget' => $budget + 2500,
                'travel_start' => $start, 'travel_end' => $end, 'duration_days' => $duration,
            ]);
            $user->trips()->updateOrCreate(['title' => 'Demo journey to '.$destination], [
                'destination' => $destination, 'description' => 'A sample itinerary for testing the discovery API.',
                'start_date' => $start, 'end_date' => $end, 'duration_days' => $duration,
                'budget' => $budget, 'travel_style' => $style, 'max_travelers' => 4, 'status' => 'open',
            ]);
        }
    }
}
