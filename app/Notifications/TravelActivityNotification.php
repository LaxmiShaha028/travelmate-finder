<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class TravelActivityNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $eventType,
        public string $title,
        public string $text,
        public array $details = [],
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return array_merge([
            'event_type' => $this->eventType,
            'title' => $this->title,
            'text' => $this->text,
            'icon' => match ($this->eventType) {
                'trip_reminder' => '◷',
                'review_received' => '★',
                default => '✦',
            },
        ], $this->details);
    }
}