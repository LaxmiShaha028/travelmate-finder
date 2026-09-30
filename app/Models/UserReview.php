<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserReview extends Model
{
    protected $fillable = ['reviewer_id', 'reviewed_user_id', 'rating', 'body'];

    protected function casts(): array
    {
        return ['rating' => 'integer'];
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function scopeVisible($query)
    {
        return $query->whereHas('reviewer', fn ($user) => $user->discoverable());
    }
}
