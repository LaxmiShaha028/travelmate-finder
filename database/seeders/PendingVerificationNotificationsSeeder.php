<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\VerificationRequest;
use App\Notifications\TravelActivityNotification;
use Illuminate\Database\Seeder;

class PendingVerificationNotificationsSeeder extends Seeder
{
    public function run(): void
    {
        VerificationRequest::with('user')->where('status', 'pending')->each(function ($application) {
            if (! $application->user) {
                return;
            }
            User::where('role', 'admin')->where('is_blocked', false)->each(function ($admin) use ($application) {
                if ($admin->notifications()->where('data->event_type', 'verification_requested')
                    ->where('data->verification_id', $application->id)->exists()) {
                    return;
                }
                $admin->notify(new TravelActivityNotification(
                    'verification_requested', 'New verification request',
                    $application->user->name.' requested profile verification.',
                    ['verification_id' => $application->id, 'sender' => $application->user->name],
                ));
            });
        });
    }
}
