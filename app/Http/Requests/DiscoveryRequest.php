<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DiscoveryRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $perPage = $this->input('per_page');

        if ((is_int($perPage) || is_string($perPage))
            && filter_var($perPage, FILTER_VALIDATE_INT) !== false
            && (int) $perPage > 50) {
            $this->merge(['per_page' => 50]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'destination' => 'nullable|string|max:255',
            'date' => 'nullable|date_format:Y-m-d',
            'travel_style' => 'nullable|string|max:255',
            'min_age' => 'nullable|integer|min:0|max:120',
            'max_age' => ['nullable', 'integer', 'min:0', 'max:120', ...($this->filled('min_age') ? ['gte:min_age'] : [])],
            'min_budget' => 'nullable|numeric|min:0|max:99999999',
            'max_budget' => ['nullable', 'numeric', 'min:0', 'max:99999999', ...($this->filled('min_budget') ? ['gte:min_budget'] : [])],
            'min_duration' => 'nullable|integer|min:1|max:365',
            'max_duration' => ['nullable', 'integer', 'min:1', 'max:365', ...($this->filled('min_duration') ? ['gte:min_duration'] : [])],
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:50',
        ];
    }
}
