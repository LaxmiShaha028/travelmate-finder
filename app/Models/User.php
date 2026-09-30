<?php

namespace App\Models;

use App\Models\Conversation;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $appends = ['profile_photo_url'];

    public function getProfilePhotoUrlAttribute(): ?string
    {
        return $this->profile_photo
            ? url('/api/travelers/' . $this->id . '/photo') . '?v=' . substr(sha1($this->profile_photo), 0, 12)
            : null;
    }

    public function scopeDiscoverable($query)
    {
        return $query->where('is_blocked', false)
            ->where('role', 'user');
    }

    public function trips()
    {
        return $this->hasMany(Trip::class);
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'profile_photo',
        'date_of_birth',
        'gender',
        'bio',
        'role',
        'verification_status',
        'is_blocked',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_blocked' => 'boolean',
        ];
    }

    /**
     * User has one travel preference.
     */
    public function travelPreference()
    {
        return $this->hasOne(TravelPreference::class);
    }

    /**
     * User belongs to many conversations.
     */
    
    public function conversations()
{
    return $this->belongsToMany(Conversation::class)
        ->withTimestamps();
}
}