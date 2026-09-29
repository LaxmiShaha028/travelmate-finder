<?php

namespace App\Notifications;

use App\Models\Message;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ChatMessageNotification extends Notification
{
    use Queueable;

    public function __construct(public Message $message)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'message' => $this->message->sender->name.' sent you a message.',
            'conversation_id' => $this->message->conversation_id,
            'message_id' => $this->message->id,
        ];
    }
}