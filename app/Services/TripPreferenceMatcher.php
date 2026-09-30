<?php

namespace App\Services;

use App\Models\Trip;
use Carbon\CarbonImmutable;

class TripPreferenceMatcher
{
    private function normalize(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', str_replace(['’', '–', '—'], ["'", '-', '-'], $value))));
    }

    private function budgetMatches(string $answer, mixed $budget): bool
    {
        if (! is_numeric($budget)) {
            return false;
        }

        $amount = (float) $budget;

        return match ($this->normalize($answer)) {
            'flexible budget' => true,
            'under ৳5,000' => $amount < 5000,
            '৳5,000 - ৳10,000' => $amount >= 5000 && $amount <= 10000,
            '৳10,000 - ৳20,000' => $amount >= 10000 && $amount <= 20000,
            '৳20,000 - ৳30,000' => $amount >= 20000 && $amount <= 30000,
            '৳30,000+' => $amount >= 30000,
            default => false,
        };
    }

    private function timingMatches(string $answer, Trip $trip, CarbonImmutable $today): bool
    {
        if (! $trip->start_date) {
            return false;
        }

        $month = $today->startOfMonth();
        $range = match ($this->normalize($answer)) {
            'this month' => [$month, $month->endOfMonth()],
            'next month' => [$month->addMonths(1), $month->addMonths(1)->endOfMonth()],
            'in 2-3 months' => [$month->addMonths(2), $month->addMonths(3)->endOfMonth()],
            'in 3-6 months' => [$month->addMonths(3), $month->addMonths(6)->endOfMonth()],
            default => null,
        };

        return $range !== null && $trip->start_date->betweenIncluded($range[0], $range[1]);
    }

    public function compare(array $answers, Trip $trip, CarbonImmutable $today): array
    {
        $criteria = [
            ['field' => 'destination', 'label' => 'Destination', 'answer' => $answers['destination'] ?? '',
                'matched' => $this->normalize($answers['destination'] ?? '') !== ''
                    && $this->normalize($answers['destination'] ?? '') === $this->normalize($trip->destination)],
            ['field' => 'date', 'label' => 'Timing', 'answer' => $answers['date'] ?? '',
                'matched' => $this->timingMatches($answers['date'] ?? '', $trip, $today)],
            ['field' => 'budget', 'label' => 'Budget', 'answer' => $answers['budget'] ?? '',
                'matched' => $this->budgetMatches($answers['budget'] ?? '', $trip->budget)],
            ['field' => 'style', 'label' => 'Travel style', 'answer' => $answers['style'] ?? '',
                'matched' => $this->normalize($answers['style'] ?? '') !== ''
                    && $this->normalize($answers['style'] ?? '') === $this->normalize($trip->travel_style)],
        ];
        $count = count(array_filter($criteria, fn ($criterion) => $criterion['matched']));

        return ['matched_count' => $count, 'total_preferences' => 4,
            'percentage' => $count * 25, 'criteria' => $criteria];
    }
}
