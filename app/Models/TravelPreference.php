<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TravelPreference extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'answers',
        'travel_start',
        'travel_end',
        'duration_days',
        'min_budget',
        'max_budget',
        'travel_style',
        'interests',
        'preferred_destinations',
    ];

    protected function casts(): array
    {
        return [
            'answers' => 'array',
            'travel_start' => 'date:Y-m-d',
            'travel_end' => 'date:Y-m-d',
            'duration_days' => 'integer',
            'min_budget' => 'decimal:2',
            'max_budget' => 'decimal:2',
            'interests' => 'array',
            'preferred_destinations' => 'array',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
