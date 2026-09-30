<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Trip extends Model
{
    protected $fillable = [
        'title', 'destination', 'description', 'start_date', 'end_date',
        'duration_days', 'budget', 'travel_style', 'max_travelers', 'status',
    ];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date', 'budget' => 'decimal:2'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopeVisible($query)
    {
        return $query->where('status', 'open')->whereHas('user', fn ($q) => $q->discoverable());
    }

    public function scopeByStartDate($query)
    {
        return $query
            ->orderByRaw('CASE WHEN start_date >= ? THEN 0 ELSE 1 END', [now('Asia/Dhaka')->toDateString()])
            ->orderBy('start_date')
            ->orderBy('id');
    }
}
