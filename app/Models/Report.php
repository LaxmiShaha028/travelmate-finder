<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Report extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['moderation_previous_state' => 'array'];
    }

    public function reporter()
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }
}
