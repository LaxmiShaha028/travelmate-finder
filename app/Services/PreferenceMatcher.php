<?php

namespace App\Services;

class PreferenceMatcher
{
    public const FIELDS = ['destination', 'date', 'budget', 'style', 'companions', 'interests'];

    private function normalize(mixed $answer): string
    {
        return is_string($answer) ? mb_strtolower(trim(preg_replace('/\s+/u', ' ', $answer))) : '';
    }

    public function isComplete(array $answers): bool
    {
        foreach (self::FIELDS as $field) {
            if ($this->normalize($answers[$field] ?? null) === '') {
                return false;
            }
        }

        return true;
    }

    public function compare(array $mine, array $theirs): array
    {
        $matched = [];
        foreach (self::FIELDS as $field) {
            $answer = $this->normalize($mine[$field] ?? null);
            if ($answer !== '' && $answer === $this->normalize($theirs[$field] ?? null)) {
                $matched[] = ['field' => $field, 'answer' => $mine[$field]];
            }
        }

        return [
            'matched_count' => count($matched),
            'total_preferences' => count(self::FIELDS),
            'percentage' => (int) round(count($matched) / count(self::FIELDS) * 100),
            'matched_preferences' => $matched,
        ];
    }
}
