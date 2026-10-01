<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserReview;

class UserReputation
{
    public function summary(User $user): array
    {
        $counts = UserReview::visible()->where('reviewed_user_id', $user->id)
            ->selectRaw('rating, COUNT(*) as total')->groupBy('rating')->pluck('total', 'rating');
        $distribution = [];
        $total = 0;
        $sum = 0;
        foreach (range(5, 1) as $stars) {
            $count = (int) ($counts[$stars] ?? 0);
            $distribution[] = ['rating' => $stars, 'count' => $count];
            $total += $count;
            $sum += $stars * $count;
        }
        $average = $total ? $sum / $total : null;
        $answers = $user->travelPreference()->first()?->answers ?? [];
        $hosted = $user->trips()->whereIn('status', ['open', 'completed'])->count();
        $written = UserReview::where('reviewer_id', $user->id)
            ->whereIn('reviewed_user_id', User::discoverable()->select('id'))->count();

        return [
            'average' => $average === null ? null : round($average, 1),
            'count' => $total,
            'distribution' => $distribution,
            'hosted_trips' => $hosted,
            'badges' => [
                ['id' => 'ready', 'name' => 'Travel Ready', 'description' => 'Complete all six travel preferences.',
                    'earned' => app(PreferenceMatcher::class)->isComplete($answers)],
                ['id' => 'host', 'name' => 'Trip Host', 'description' => 'Host at least one open or completed trip.',
                    'earned' => $hosted >= 1],
                ['id' => 'voice', 'name' => 'Community Voice', 'description' => 'Review at least three other travelers.',
                    'earned' => $written >= 3],
                ['id' => 'rated', 'name' => 'Highly Rated', 'description' => 'Receive at least five reviews with an average of 4.5 or higher.',
                    'earned' => $total >= 5 && $average >= 4.5],
            ],
        ];
    }
}
