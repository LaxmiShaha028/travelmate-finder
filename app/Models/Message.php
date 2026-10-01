<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    use HasFactory;

    protected $fillable = [
        'conversation_id',
        'sender_id',
        'message',
    ];

    /**
     * Create a message for a sender.
     */
    public static function createForSender(
        Conversation $conversation,
        User $sender,
        string $body
    ): self {
        return self::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $sender->id,
            'message' => $body,
        ]);
    }

    /**
     * Conversation of this message.
     */
    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * Sender of this message.
     */
    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}