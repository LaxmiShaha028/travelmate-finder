<?php

namespace App\Rules;

use Closure;
use DateTimeImmutable;
use Illuminate\Contracts\Validation\ValidationRule;

class PreferenceAnswer implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $choices = json_decode(file_get_contents(resource_path('preferenceChoices.json')), true);
        if (is_string($value) && in_array($value, $choices[$attribute] ?? [], true)) {
            return;
        }

        if ($attribute === 'budget') {
            if (is_string($value) && preg_match('/^\d{1,8}(\.\d{1,2})?$/D', $value)
                && (float) $value > 0 && (float) $value <= 99999999) {
                return;
            }
            $fail('Enter a positive amount in BDT, up to 99,999,999 (at most 2 decimal places).');
        } elseif ($attribute === 'date') {
            $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
            if ($date && $date->format('Y-m-d') === $value && $value >= now('Asia/Dhaka')->toDateString()) {
                return;
            }
            $fail('Choose today or a future travel date.');
        } else {
            $fail($attribute === 'destination'
                ? 'Choose a destination from the supported list.'
                : 'Choose one of the available options for this question.');
        }
    }
}
