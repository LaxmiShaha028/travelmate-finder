<?php

use App\Models\Report;
use App\Models\User;
use App\Models\VerificationRequest;
use Laravel\Sanctum\Sanctum;

function adminTrip(User $owner)
{
    return $owner->trips()->create([
        'title' => 'Admin test trip', 'destination' => 'Sylhet', 'start_date' => today()->addDay(),
        'end_date' => today()->addDays(3), 'duration_days' => 3, 'budget' => 5000, 'max_travelers' => 2, 'status' => 'open',
    ]);
}

test('opening notifications marks only the current users notifications read', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    foreach ([$user, $other] as $recipient) {
        $recipient->notify(new \App\Notifications\TravelActivityNotification('report_received', 'New report', 'A report was submitted.'));
    }
    Sanctum::actingAs($user);
    $this->postJson('/api/notifications/read-all')->assertOk()->assertJsonPath('unread_count', 0);
    $this->getJson('/api/notifications')->assertOk()->assertJsonPath('unread_count', 0)->assertJsonCount(1, 'notifications');
    expect($other->unreadNotifications()->count())->toBe(1);
});

test('admin reports filter trips and mates before pagination', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    foreach (['trip', 'user'] as $type) {
        Report::create(['reporter_id' => $admin->id, 'target_type' => $type, 'target_id' => 1, 'reason' => 'Filter test report', 'status' => 'pending']);
    }
    Sanctum::actingAs($admin);
    foreach (['trip', 'user'] as $type) {
        $this->getJson('/api/admin/reports?target_type='.$type.'&status=pending&search=Filter')
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.target_type', $type);
    }
    $this->getJson('/api/admin/reports')->assertOk()->assertJsonPath('total', 2);
    $this->getJson('/api/admin/reports?target_type=invalid')->assertUnprocessable();
});

test('every admin endpoint rejects guests and regular users', function () {
    $endpoints = [
        ['get', '/overview'], ['get', '/users'], ['get', '/trips'], ['get', '/reports'], ['get', '/verifications'],
        ['patch', '/users/1'], ['patch', '/trips/1'], ['delete', '/trips/1'], ['patch', '/reports/1'], ['delete', '/reports/1'], ['patch', '/verifications/1'], ['delete', '/verifications/1'],
    ];
    $user = User::factory()->create(['role' => 'user']);
    adminTrip($user);
    Report::create(['reporter_id' => $user->id, 'target_type' => 'trip', 'target_id' => 1, 'reason' => 'A report for access testing']);
    VerificationRequest::create(['user_id' => $user->id, 'details' => 'Profile details for verification testing']);
    foreach ($endpoints as [$method, $path]) {
        $this->{$method.'Json'}('/api/admin'.$path)->assertUnauthorized();
    }
    Sanctum::actingAs($user);
    foreach ($endpoints as [$method, $path]) {
        $this->{$method.'Json'}('/api/admin'.$path)->assertForbidden();
    }
});

test('normal login supports database assigned admins without granting signup privileges', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $this->postJson('/api/login', ['email' => $admin->email, 'password' => 'password'])->assertOk()->assertJsonPath('user.role', 'admin');
    $user = User::factory()->create(['role' => 'user']);
    $this->postJson('/api/admin/login', ['email' => $user->email, 'password' => 'password'])->assertForbidden();
    expect($user->tokens()->count())->toBe(0);
    $this->postJson('/api/register', ['name' => 'Normal signup', 'email' => 'signup@example.test', 'password' => 'password', 'password_confirmation' => 'password', 'role' => 'admin', 'verification_status' => 'verified'])
        ->assertCreated()->assertJsonPath('user.role', 'user')->assertJsonPath('user.verification_status', 'unverified');
    $admin->update(['is_blocked' => true]);
    $this->postJson('/api/login', ['email' => $admin->email, 'password' => 'password'])->assertForbidden();
    Sanctum::actingAs($admin);
    $this->getJson('/api/admin/overview')->assertForbidden();
});

test('admins can search block and unblock users without changing roles', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $user = User::factory()->create(['name' => 'Manage Traveler', 'role' => 'user']);
    $user->createToken('existing');
    Sanctum::actingAs($admin);
    $this->getJson('/api/admin/users?search=Manage')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $user->id);
    $this->patchJson('/api/admin/users/'.$user->id, ['is_blocked' => true, 'role' => 'admin'])->assertOk();
    expect($user->fresh()->is_blocked)->toBeTrue();
    expect($user->fresh()->role)->toBe('user');
    expect($user->tokens()->count())->toBe(0);
    $this->patchJson('/api/admin/users/'.$admin->id, ['is_blocked' => true])->assertUnprocessable();
    Sanctum::actingAs($user->fresh());
    $this->getJson('/api/user')->assertForbidden();
    Sanctum::actingAs($admin);
    $this->patchJson('/api/admin/users/'.$user->id, ['is_blocked' => false])->assertOk();
    expect($user->fresh()->is_blocked)->toBeFalse();
});

test('blocked traveler profiles stay hidden from admins and public visitors', function () {
    $blockedUser = User::factory()->create(['role' => 'user', 'is_blocked' => true]);
    $admin = User::factory()->create(['role' => 'admin']);

    $this->getJson('/api/travelers/'.$blockedUser->id)->assertNotFound();

    Sanctum::actingAs($admin);
    $this->getJson('/api/travelers/'.$blockedUser->id)->assertNotFound();
});

test('admins manage trips and analytics reflect the actual database', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $user = User::factory()->create(['role' => 'user']);
    $trip = adminTrip($user);
    $trip->travelRequests()->create(['user_id' => $admin->id, 'status' => 'accepted']);
    Sanctum::actingAs($admin);
    $this->getJson('/api/admin/overview')->assertOk()->assertJsonPath('data.users', 1)->assertJsonPath('data.trips', 1)->assertJsonPath('data.accepted_requests', 1)->assertJsonCount(6, 'data.months');
    $this->getJson('/api/admin/trips?search=Sylhet&status=open')->assertOk()->assertJsonPath('total', 1);
    $this->patchJson('/api/admin/trips/'.$trip->id, ['status' => 'cancelled'])->assertOk();
    $this->getJson('/api/trips')->assertJsonPath('meta.total', 0);
    $this->patchJson('/api/admin/trips/'.$trip->id, ['status' => 'invalid'])->assertUnprocessable();
    $this->deleteJson('/api/admin/trips/'.$trip->id)->assertNoContent();
    $this->assertDatabaseCount('travel_requests', 0);
    $this->getJson('/api/admin/overview')->assertJsonPath('data.trips', 0);
});

test('reports can be submitted and acted on only by admins', function () {
    $reporter = User::factory()->create(['role' => 'user']);
    $owner = User::factory()->create(['role' => 'user']);
    $admin = User::factory()->create(['role' => 'admin']);
    $trip = adminTrip($owner);
    Sanctum::actingAs($reporter);
    $input = ['target_type' => 'trip', 'target_id' => $trip->id, 'reason' => 'The trip information is misleading.'];
    $this->postJson('/api/reports', $input + ['status' => 'resolved'])->assertCreated()->assertJsonPath('data.status', 'pending');
    $this->postJson('/api/reports', $input)->assertCreated();
    $this->assertDatabaseCount('reports', 1);
    expect($admin->notifications()->count())->toBe(1);
    expect($owner->notifications()->count())->toBe(0);
    $this->postJson('/api/reports', ['target_type' => 'user', 'target_id' => $reporter->id, 'reason' => 'Cannot report myself'])->assertUnprocessable();
    $report = Report::first();
    Sanctum::actingAs($admin);
    $this->getJson('/api/admin/reports?status=pending')->assertOk()->assertJsonPath('total', 1);
    $this->getJson('/api/notifications')->assertOk()->assertJsonPath('notifications.0.data.event_type', 'report_received');
    $this->patchJson('/api/admin/reports/'.$report->id, ['status' => 'resolved'])->assertOk();
    expect($report->fresh()->reviewed_by)->toBe($admin->id);
    expect($trip->fresh()->status)->toBe('hidden');
    $this->getJson('/api/trips')->assertJsonPath('meta.total', 0);
    $this->getJson('/api/admin/trips?status=hidden')->assertJsonPath('total', 1);
    Sanctum::actingAs($owner);
    $this->getJson('/api/notifications')->assertOk()->assertJsonPath('notifications.0.data.event_type', 'report_accepted');
    $this->patchJson('/api/trips/'.$trip->id, ['status' => 'open'])->assertOk()->assertJsonPath('data.status', 'hidden');
    Sanctum::actingAs($admin);
    $this->patchJson('/api/admin/reports/'.$report->id, ['status' => 'dismissed'])->assertStatus(409);
    $this->deleteJson('/api/admin/reports/'.$report->id)->assertNoContent();
    expect($trip->fresh()->status)->toBe('open');
    $this->assertDatabaseMissing('reports', ['id' => $report->id]);
});

test('resolving a user report bans the account and revokes its tokens', function () {
    $reporter = User::factory()->create(['role' => 'user']);
    $reportedUser = User::factory()->create(['role' => 'user']);
    $admin = User::factory()->create(['role' => 'admin']);
    $reportedUser->createToken('session');

    Sanctum::actingAs($reporter);
    $report = Report::create([
        'reporter_id' => $reporter->id,
        'target_type' => 'user',
        'target_id' => $reportedUser->id,
        'reason' => 'This account is abusive.',
        'status' => 'pending',
    ]);

    Sanctum::actingAs($admin);
    $this->patchJson('/api/admin/reports/'.$report->id, ['status' => 'resolved'])->assertOk();

    expect($reportedUser->fresh()->is_blocked)->toBeTrue()
        ->and($reportedUser->tokens()->count())->toBe(0);
    expect($reportedUser->notifications()->first()->data['event_type'])->toBe('report_accepted');
    $this->deleteJson('/api/admin/reports/'.$report->id)->assertNoContent();
    expect($reportedUser->fresh()->is_blocked)->toBeFalse();
});

test('deleting older resolved reports restores their targets without saved state', function () {
    $reporter = User::factory()->create(['role' => 'user']);
    $reportedUser = User::factory()->create(['role' => 'user', 'is_blocked' => true]);
    $owner = User::factory()->create(['role' => 'user']);
    $trip = adminTrip($owner);
    $trip->update(['status' => 'hidden']);
    $admin = User::factory()->create(['role' => 'admin']);
    $userReport = Report::create([
        'reporter_id' => $reporter->id, 'target_type' => 'user', 'target_id' => $reportedUser->id,
        'reason' => 'Legacy resolved user report.', 'status' => 'resolved', 'reviewed_at' => now(),
    ]);
    $tripReport = Report::create([
        'reporter_id' => $reporter->id, 'target_type' => 'trip', 'target_id' => $trip->id,
        'reason' => 'Legacy resolved trip report.', 'status' => 'resolved', 'reviewed_at' => now(),
    ]);

    Sanctum::actingAs($admin);
    $this->deleteJson('/api/admin/reports/'.$userReport->id)->assertNoContent();
    $this->deleteJson('/api/admin/reports/'.$tripReport->id)->assertNoContent();

    expect($reportedUser->fresh()->is_blocked)->toBeFalse()
        ->and($trip->fresh()->status)->toBe('open');
});

test('verification approval and rejection update the user profile and allow resubmission', function () {
    $user = User::factory()->create(['role' => 'user', 'verification_status' => 'unverified']);
    $admin = User::factory()->create(['role' => 'admin']);
    Sanctum::actingAs($user);
    $this->postJson('/api/user/verification', ['details' => 'Please review my completed travel profile.'])->assertCreated();
    expect($user->fresh()->verification_status)->toBe('pending');
    $this->postJson('/api/user/verification', ['details' => 'Duplicate verification request details'])->assertStatus(409);
    $verification = VerificationRequest::first();
    expect($admin->notifications()->where('data->event_type', 'verification_requested')->count())->toBe(1);
    Sanctum::actingAs($admin);
    $this->getJson('/api/admin/verifications?status=pending')->assertOk()->assertJsonPath('total', 1);
    $this->patchJson('/api/admin/verifications/'.$verification->id, ['status' => 'rejected', 'admin_notes' => 'Please add more profile information.'])->assertOk();
    expect($user->fresh()->verification_status)->toBe('unverified');
    Sanctum::actingAs($user->fresh());
    $this->postJson('/api/user/verification', ['details' => 'I have now completed my travel profile and background.'])->assertCreated();
    Sanctum::actingAs($admin);
    $this->patchJson('/api/admin/verifications/'.$verification->id, ['status' => 'approved', 'admin_notes' => 'Profile information reviewed.'])->assertOk();
    expect($user->fresh()->verification_status)->toBe('verified');
    expect($user->notifications()->where('data->event_type', 'verification_approved')->count())->toBe(1);
    expect($user->notifications()->where('data->event_type', 'verification_rejected')->count())->toBe(1);
    $this->deleteJson('/api/admin/verifications/'.$verification->id)->assertNoContent();
    expect($user->notifications()->where('data->event_type', 'verification_removed')->count())->toBe(1);
    expect($user->fresh()->verification_status)->toBe('unverified');
    Sanctum::actingAs($user->fresh());
    $this->postJson('/api/user/verification', ['details' => 'The profile is ready for a new verification review.'])->assertCreated();
});
