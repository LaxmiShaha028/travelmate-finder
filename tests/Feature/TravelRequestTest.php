<?php

use App\Models\User;
use App\Notifications\TravelActivityNotification;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

function requestTrip(User $owner, int $capacity = 1)
{
    return $owner->trips()->create([
        'title' => 'Request test trip', 'destination' => 'Sylhet',
        'start_date' => today()->addDays(10), 'end_date' => today()->addDays(12),
        'duration_days' => 3, 'budget' => 5000, 'max_travelers' => $capacity, 'status' => 'open',
    ]);
}

test('owners can delete requests in every status and accepted deletions free a spot', function (string $status) {
    $owner = User::factory()->create(['role' => 'user']);
    $traveler = User::factory()->create(['role' => 'user']);
    $trip = requestTrip($owner);
    $application = $trip->travelRequests()->create(['user_id' => $traveler->id, 'status' => $status]);
    $url = "/api/trips/{$trip->id}/requests/{$application->id}";
    $this->deleteJson($url)->assertUnauthorized();
    Sanctum::actingAs($traveler);
    $this->deleteJson($url)->assertForbidden();
    Sanctum::actingAs($owner);
    $other = requestTrip($owner);
    $this->deleteJson("/api/trips/{$other->id}/requests/{$application->id}")->assertNotFound();
    $this->deleteJson($url)->assertOk()->assertJsonCount(0, 'data')
        ->assertJsonPath('trip.available_spots', 1)->assertJsonPath('trip.request_count', 0);
    $this->assertDatabaseMissing('travel_requests', ['id' => $application->id]);
    Sanctum::actingAs($traveler);
    $this->postJson("/api/trips/{$trip->id}/requests")->assertOk()->assertJsonPath('data.my_request_status', 'pending');
})->with(['pending', 'accepted', 'rejected']);

test('travelers can delete only their own request and send it again', function (string $status) {
    $owner = User::factory()->create(['role' => 'user']);
    $traveler = User::factory()->create(['role' => 'user']);
    $trip = requestTrip($owner);
    $application = $trip->travelRequests()->create(['user_id' => $traveler->id, 'status' => $status]);
    $other = $trip->travelRequests()->create(['user_id' => User::factory()->create(['role' => 'user'])->id]);
    Sanctum::actingAs($traveler);
    $this->deleteJson("/api/trips/{$trip->id}/requests/mine", ['user_id' => $other->user_id])->assertOk()
        ->assertJsonPath('data.my_request_status', null)->assertJsonPath('data.available_spots', 1);
    $this->assertDatabaseMissing('travel_requests', ['id' => $application->id]);
    $this->assertDatabaseHas('travel_requests', ['id' => $other->id]);
    $this->postJson("/api/trips/{$trip->id}/requests")->assertOk()->assertJsonPath('data.my_request_status', 'pending');
})->with(['pending', 'accepted', 'rejected']);

test('requests require authentication and are unique with private owner lists', function () {
    $owner = User::factory()->create(['role' => 'user']);
    $traveler = User::factory()->create(['role' => 'user']);
    $trip = requestTrip($owner);
    $url = "/api/trips/{$trip->id}/requests";
    $this->postJson($url)->assertUnauthorized();
    Sanctum::actingAs($owner);
    $this->postJson($url)->assertUnprocessable();
    Sanctum::actingAs($traveler);
    $this->postJson($url)->assertOk()->assertJsonPath('data.my_request_status', 'pending');
    $this->postJson($url)->assertOk();
    $this->assertDatabaseCount('travel_requests', 1);
    $this->getJson($url)->assertForbidden();
    $application = $trip->travelRequests()->first();
    $this->patchJson("$url/{$application->id}", ['status' => 'accepted'])->assertForbidden();
    Sanctum::actingAs($owner);
    $response = $this->getJson($url)->assertOk()->assertJsonCount(1, 'data');
    expect($response->json('data.0.traveler'))->not->toHaveKeys(['email', 'password']);
    $this->getJson('/api/user/trips')->assertJsonPath('data.0.request_count', 1);
});

test('travel history contains only the signed-in users requested and hosted trips', function () {
    $traveler = User::factory()->create(['role' => 'user']);
    $organizer = User::factory()->create(['role' => 'user']);
    $requestTrip = requestTrip($organizer);
    $requestTrip->travelRequests()->create(['user_id' => $traveler->id, 'status' => 'accepted']);
    $hostedTrip = requestTrip($traveler);
    $hostedTrip->update(['status' => 'completed']);
    requestTrip($traveler);
    requestTrip(User::factory()->create(['role' => 'user']));

    $this->getJson('/api/user/travel-history')->assertUnauthorized();

    Sanctum::actingAs($traveler);
    $this->getJson('/api/user/travel-history')
        ->assertOk()
        ->assertJsonCount(1, 'data.requested')
        ->assertJsonPath('data.requested.0.trip.id', $requestTrip->id)
        ->assertJsonPath('data.requested.0.request_status', 'accepted')
        ->assertJsonCount(1, 'data.hosted')
        ->assertJsonPath('data.hosted.0.id', $hostedTrip->id)
        ->assertJsonPath('data.hosted.0.status', 'completed');
});

test('request creation notifies the owner and decisions notify only the requester', function () {
    Notification::fake();
    $owner = User::factory()->create(['role' => 'user']);
    $acceptedTraveler = User::factory()->create(['role' => 'user']);
    $rejectedTraveler = User::factory()->create(['role' => 'user']);
    $trip = requestTrip($owner, 2);

    Sanctum::actingAs($acceptedTraveler);
    $this->postJson("/api/trips/{$trip->id}/requests")->assertOk();
    Notification::assertSentTo($owner, TravelActivityNotification::class, fn ($notification) => $notification->eventType === 'travel_request');
    Notification::assertNotSentTo($acceptedTraveler, TravelActivityNotification::class);

    Sanctum::actingAs($rejectedTraveler);
    $this->postJson("/api/trips/{$trip->id}/requests")->assertOk();
    Sanctum::actingAs($owner);
    $requests = $trip->travelRequests()->get()->keyBy('user_id');
    $this->patchJson("/api/trips/{$trip->id}/requests/{$requests[$acceptedTraveler->id]->id}", ['status' => 'accepted'])->assertOk();
    $this->patchJson("/api/trips/{$trip->id}/requests/{$requests[$rejectedTraveler->id]->id}", ['status' => 'rejected'])->assertOk();

    Notification::assertSentTo($acceptedTraveler, TravelActivityNotification::class, fn ($notification) => $notification->eventType === 'request_accepted');
    Notification::assertSentTo($rejectedTraveler, TravelActivityNotification::class, fn ($notification) => $notification->eventType === 'request_rejected');
    Notification::assertNotSentTo($owner, TravelActivityNotification::class, fn ($notification) => str_starts_with($notification->eventType, 'request_'));
});

test('one day trip reminders go only to accepted travelers and are not duplicated', function () {
    $owner = User::factory()->create(['role' => 'user']);
    $acceptedTraveler = User::factory()->create(['role' => 'user']);
    $pendingTraveler = User::factory()->create(['role' => 'user']);
    $trip = requestTrip($owner, 2);
    $trip->update(['start_date' => today()->addDay(), 'end_date' => today()->addDays(3)]);
    $trip->travelRequests()->create(['user_id' => $acceptedTraveler->id, 'status' => 'accepted']);
    $trip->travelRequests()->create(['user_id' => $pendingTraveler->id, 'status' => 'pending']);

    expect(app(\App\Services\TripReminderSender::class)->sendForTomorrow())->toBe(1);
    expect($acceptedTraveler->notifications()->count())->toBe(1)
        ->and($pendingTraveler->notifications()->count())->toBe(0)
        ->and(app(\App\Services\TripReminderSender::class)->sendForTomorrow())->toBe(0)
        ->and($acceptedTraveler->notifications()->count())->toBe(1);
    expect($acceptedTraveler->notifications()->first()->data['event_type'])->toBe('trip_reminder');
});

test('capacity is enforced and removing a traveler allows replacement and reacceptance', function (int $capacity) {
    $owner = User::factory()->create(['role' => 'user']);
    $trip = requestTrip($owner, $capacity);
    $url = "/api/trips/{$trip->id}/requests";
    $applications = [];
    for ($i = 0; $i <= $capacity; $i++) {
        $traveler = User::factory()->create(['role' => 'user']);
        Sanctum::actingAs($traveler);
        $this->postJson($url)->assertOk();
        $applications[] = $trip->travelRequests()->where('user_id', $traveler->id)->first();
    }
    Sanctum::actingAs($owner);
    foreach (array_slice($applications, 0, $capacity) as $application) {
        $this->patchJson("$url/{$application->id}", ['status' => 'accepted'])->assertOk();
    }
    $first = $applications[0];
    $last = $applications[$capacity];
    $this->getJson($url)->assertJsonPath('trip.accepted_count', $capacity)->assertJsonPath('trip.available_spots', 0);
    $this->patchJson("$url/{$first->id}", ['status' => 'accepted'])->assertOk();
    $this->patchJson("$url/{$last->id}", ['status' => 'accepted'])->assertStatus(409);
    $this->patchJson("$url/{$last->id}", ['status' => 'rejected'])->assertOk();
    $this->patchJson("$url/{$first->id}", ['status' => 'pending'])->assertOk()->assertJsonPath('trip.available_spots', 1);
    $this->patchJson("$url/{$last->id}", ['status' => 'accepted'])->assertOk()->assertJsonPath('trip.available_spots', 0);
    $this->patchJson("$url/{$last->id}", ['status' => 'pending'])->assertOk();
    $this->patchJson("$url/{$first->id}", ['status' => 'accepted'])->assertOk();
    $this->assertDatabaseCount('travel_requests', $capacity + 1);
    Sanctum::actingAs($first->user);
    $this->getJson('/api/trips')->assertJsonPath('data.0.accepted_count', $capacity)->assertJsonPath('data.0.my_request_status', 'accepted');
    $this->postJson($url)->assertOk()->assertJsonPath('data.my_request_status', 'accepted');
    // New requests are still permitted while full, without consuming a place.
    Sanctum::actingAs(User::factory()->create(['role' => 'user']));
    $this->postJson($url)->assertOk()->assertJsonPath('data.available_spots', 0);
})->with([1, 5]);

test('owners cannot lower capacity below accepted count or modify another trips request', function () {
    $owner = User::factory()->create(['role' => 'user']);
    $trip = requestTrip($owner, 2);
    $other = requestTrip($owner);
    $one = $trip->travelRequests()->create(['user_id' => User::factory()->create(['role' => 'user'])->id, 'status' => 'accepted']);
    $trip->travelRequests()->create(['user_id' => User::factory()->create(['role' => 'user'])->id, 'status' => 'accepted']);
    Sanctum::actingAs($owner);
    $this->patchJson("/api/trips/{$trip->id}", ['max_travelers' => 1])->assertUnprocessable();
    $this->patchJson("/api/trips/{$other->id}/requests/{$one->id}", ['status' => 'pending'])->assertNotFound();
    $this->patchJson("/api/trips/{$trip->id}/requests/{$one->id}", ['status' => 'unknown'])->assertUnprocessable();
});

test('closed trips and blocked users cannot send or accept requests', function () {
    $owner = User::factory()->create(['role' => 'user']);
    $traveler = User::factory()->create(['role' => 'user']);
    $trip = requestTrip($owner);
    $url = "/api/trips/{$trip->id}/requests";
    Sanctum::actingAs($traveler);
    $this->postJson($url)->assertOk();
    $application = $trip->travelRequests()->first();
    $trip->update(['status' => 'cancelled']);
    $this->postJson($url)->assertUnprocessable();
    Sanctum::actingAs($owner);
    $this->patchJson("$url/{$application->id}", ['status' => 'accepted'])->assertUnprocessable();
    $trip->update(['status' => 'open']);
    $traveler->update(['is_blocked' => true]);
    $this->patchJson("$url/{$application->id}", ['status' => 'accepted'])->assertUnprocessable();
    Sanctum::actingAs($traveler);
    $this->postJson($url)->assertForbidden();
    $owner->update(['is_blocked' => true]);
    Sanctum::actingAs($owner);
    $this->getJson($url)->assertForbidden();
});
