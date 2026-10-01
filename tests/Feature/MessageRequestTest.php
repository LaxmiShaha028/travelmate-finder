<?php

use App\Models\Conversation;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

test('only the recipient can accept a request and unlock messaging', function () {
    $sender = User::factory()->create();
    $recipient = User::factory()->create();
    $outsider = User::factory()->create();
    Sanctum::actingAs($sender);
    $this->postJson('/api/conversations', ['type' => 'private', 'user_ids' => [$recipient->id]])->assertForbidden();
    $id = $this->postJson('/api/users/'.$recipient->id.'/message-request')->assertCreated()->json('data.id');
    $this->postJson('/api/users/'.$recipient->id.'/message-request')->assertOk()->assertJsonPath('data.id', $id);
    expect($recipient->notifications()->count())->toBe(1);
    $this->patchJson('/api/message-requests/'.$id, ['status' => 'accepted'])->assertForbidden();
    $conversation = Conversation::create(['type' => 'private']);
    $conversation->users()->attach([$sender->id, $recipient->id]);
    $this->postJson('/api/messages', ['conversation_id' => $conversation->id, 'message' => 'Blocked'])->assertForbidden();
    $this->postJson('/api/conversations/'.$conversation->id.'/messages', ['message' => 'Blocked'])->assertForbidden();
    Sanctum::actingAs($outsider);
    $this->getJson('/api/message-requests')->assertJsonPath('total', 0);
    $this->patchJson('/api/message-requests/'.$id, ['status' => 'accepted'])->assertForbidden();
    Sanctum::actingAs($recipient);
    $this->getJson('/api/message-requests')->assertJsonPath('total', 1);
    $this->patchJson('/api/message-requests/'.$id, ['status' => 'accepted'])->assertOk();
    $this->patchJson('/api/message-requests/'.$id, ['status' => 'rejected'])->assertStatus(409);
    Sanctum::actingAs($sender);
    $this->postJson('/api/conversations', ['type' => 'private', 'user_ids' => [$recipient->id]])->assertOk();
    $this->postJson('/api/messages', ['conversation_id' => $conversation->id, 'message' => 'Hello'])->assertSuccessful();
});

test('rejection does not grant messaging and cannot be bypassed by a reverse request', function () {
    $sender = User::factory()->create();
    $recipient = User::factory()->create();
    Sanctum::actingAs($sender);
    $this->postJson('/api/users/'.$sender->id.'/message-request')->assertUnprocessable();
    $id = $this->postJson('/api/users/'.$recipient->id.'/message-request')->json('data.id');
    Sanctum::actingAs($recipient);
    $this->patchJson('/api/message-requests/'.$id, ['status' => 'rejected'])->assertOk();
    $this->postJson('/api/users/'.$sender->id.'/message-request')->assertJsonPath('data.status', 'rejected');
    $this->postJson('/api/conversations', ['type' => 'private', 'user_ids' => [$sender->id]])->assertForbidden();
});
