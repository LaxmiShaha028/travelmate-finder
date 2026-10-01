<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ChatMessageNotification extends Notification
{
    use Queueable;

    public function __construct(
        public int $conversationId,
        public int $senderId,
        public string $senderName,
        public string $message,
        public string $messageId
    ) {
    }

    /**
     * Notification channels
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Database notification data
     */
    public function toArray(object $notifiable): array
    {
        return [
            'message' => $this->senderName . ' sent you a message.',

            'sender_name' => $this->senderName,

            'sender_id' => $this->senderId,

            'conversation_id' => $this->conversationId,

            'message_id' => $this->messageId,

            'text' => $this->message,
        ];
    }
}