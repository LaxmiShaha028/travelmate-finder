<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TravelPreference extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'min_budget',
        'max_budget',
        'travel_style',
        'interests',
        'preferred_destinations',
    ];

    protected function casts(): array
    {
        return [
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