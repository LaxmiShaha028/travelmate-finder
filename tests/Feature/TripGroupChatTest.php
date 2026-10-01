<?php

use App\Models\User;
use Laravel\Sanctum\Sanctum;

test('accepted trip travelers share one group and removed travelers lose access', function () {
    $owner = User::factory()->create();
    $first = User::factory()->create();
    $second = User::factory()->create();
    $trip = $owner->trips()->create(['title' => 'Sylhet group', 'destination' => 'Sylhet', 'start_date' => today()->addDay(), 'end_date' => today()->addDays(2), 'duration_days' => 2, 'budget' => 2000, 'max_travelers' => 3, 'status' => 'open']);
    $application = $trip->travelRequests()->create(['user_id' => $first->id, 'status' => 'accepted']);
    Sanctum::actingAs($second);
    $this->postJson('/api/trips/'.$trip->id.'/chat')->assertForbidden();
    Sanctum::actingAs($owner);
    $group = $this->postJson('/api/trips/'.$trip->id.'/chat')->assertOk()->assertJsonPath('type', 'group')->json('id');
    Sanctum::actingAs($first);
    $this->postJson('/api/trips/'.$trip->id.'/chat')->assertJsonPath('id', $group);
    $this->postJson('/api/messages', ['conversation_id' => $group, 'message' => 'Hello group'])->assertSuccessful();
    $next = $trip->travelRequests()->create(['user_id' => $second->id]);
    Sanctum::actingAs($owner);
    $this->patchJson('/api/trips/'.$trip->id.'/requests/'.$next->id, ['status' => 'accepted'])->assertOk();
    Sanctum::actingAs($second);
    $this->getJson('/api/conversations')->assertJsonPath('0.id', $group);
    $this->getJson('/api/conversations/'.$group.'/messages')->assertOk();
    Sanctum::actingAs($owner);
    $this->patchJson('/api/trips/'.$trip->id.'/requests/'.$application->id, ['status' => 'pending'])->assertOk();
    Sanctum::actingAs($first);
    $this->getJson('/api/conversations')->assertJsonCount(0);
    $this->postJson('/api/trips/'.$trip->id.'/chat')->assertForbidden();
    $this->getJson('/api/conversations/'.$group.'/messages')->assertNotFound();
    $this->postJson('/api/messages', ['conversation_id' => $group, 'message' => 'No access'])->assertNotFound();
    $this->postJson('/api/conversations/'.$group.'/messages', ['message' => 'No access'])->assertForbidden();
    Sanctum::actingAs($second);
    $this->postJson('/api/conversations', ['type' => 'private', 'user_ids' => [$owner->id]])->assertForbidden();
    $trip->update(['max_travelers' => 1]);
    $this->postJson('/api/trips/'.$trip->id.'/chat')->assertForbidden();
});
