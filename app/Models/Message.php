<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class Message extends Model
{
    use HasFactory;

    protected $fillable = ['conversation_id', 'user_id', 'sender_id', 'message'];
    private static ?bool $hasLegacyUserIdColumn = null;

    public static function createForSender(Conversation $conversation, User $sender, string $body): self
    {
        $attributes = [
            'conversation_id' => $conversation->id,
            'sender_id' => $sender->id,
            'message' => $body,
        ];

        if (self::$hasLegacyUserIdColumn ??= Schema::hasColumn('messages', 'user_id')) {
            $attributes['user_id'] = $sender->id;
        }

        return self::create($attributes);
    }

    public function getMessageAttribute($value): ?string
    {
        if ($value !== null && $value !== '') {
            return $value;
        }

        foreach (['text', 'body', 'content'] as $legacyField) {
            if (! empty($this->attributes[$legacyField])) {
                return $this->attributes[$legacyField];
            }
        }

        return $value;
    }

    public function getSenderIdAttribute($value): ?int
    {
        return $value ?? ($this->attributes['user_id'] ?? null);
    }

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}