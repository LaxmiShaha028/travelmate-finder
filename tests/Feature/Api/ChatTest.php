<?php

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

function makeConversationBetween(User $first, User $second): Conversation
{
    $conversation = Conversation::create(['type' => 'private']);
    $conversation->users()->attach([$first->id, $second->id]);

    return $conversation;
}

it('creates a private conversation once and includes it in the user list', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();

    $this->actingAs($first, 'sanctum')
        ->postJson('/api/conversations', ['user_id' => $second->id])
        ->assertCreated()
        ->assertJsonPath('data.type', 'private');

    $this->actingAs($first, 'sanctum')
        ->postJson('/api/conversations', ['user_ids' => [$second->id]])
        ->assertOk();

    $this->actingAs($first, 'sanctum')
        ->getJson('/api/conversations')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('uses the existing Laravel session and includes the signed-in user in a private chat', function () {
    $owner = User::factory()->create();
    $contact = User::factory()->create();
    $origin = config('app.url');

    $this->actingAs($owner)
        ->withHeader('Origin', $origin)
        ->postJson('/api/conversations', ['user_id' => $contact->id])
        ->assertCreated()
        ->assertJsonFragment(['id' => $owner->id, 'name' => $owner->name])
        ->assertJsonFragment(['id' => $contact->id, 'name' => $contact->name]);

    $this->actingAs($owner)
        ->withHeader('Origin', $origin)
        ->getJson('/api/conversations')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('creates a group conversation with the requested members', function () {
    $owner = User::factory()->create();
    $members = User::factory()->count(2)->create();

    $this->actingAs($owner, 'sanctum')
        ->postJson('/api/conversations', [
            'type' => 'group',
            'name' => 'Weekend trip',
            'user_ids' => $members->pluck('id')->all(),
        ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Weekend trip')
        ->assertJsonCount(3, 'data.users');
});

it('creates a group conversation from comma-separated traveler ids', function () {
    $owner = User::factory()->create();
    $members = User::factory()->count(2)->create();

    $this->actingAs($owner, 'sanctum')
        ->postJson('/api/conversations', [
            'type' => 'group',
            'name' => 'Weekend trip',
            'traveler_ids' => $members->pluck('id')->implode(', '),
        ])
        ->assertCreated()
        ->assertJsonPath('data.type', 'group')
        ->assertJsonCount(3, 'data.users');
});

it('returns messages in order only to conversation members', function () {
    $member = User::factory()->create();
    $otherMember = User::factory()->create();
    $outsider = User::factory()->create();
    $conversation = makeConversationBetween($member, $otherMember);
    $firstMessage = Message::create([
        'conversation_id' => $conversation->id,
        'sender_id' => $member->id,
        'message' => 'First',
    ]);
    Message::create([
        'conversation_id' => $conversation->id,
        'sender_id' => $otherMember->id,
        'message' => 'Second',
    ]);

    $this->actingAs($member, 'sanctum')
        ->getJson("/api/conversations/{$conversation->id}/messages")
        ->assertOk()
        ->assertJsonPath('0.id', $firstMessage->id)
        ->assertJsonCount(2);

    $this->actingAs($outsider, 'sanctum')
        ->getJson("/api/conversations/{$conversation->id}/messages")
        ->assertNotFound();
});

it('sends messages as the authenticated member and blocks outsiders', function () {
    $member = User::factory()->create();
    $otherMember = User::factory()->create();
    $outsider = User::factory()->create();
    $conversation = makeConversationBetween($member, $otherMember);
    $payload = ['conversation_id' => $conversation->id, 'message' => 'Meet at the station'];

    $this->actingAs($member, 'sanctum')
        ->postJson('/api/messages', $payload)
        ->assertCreated()
        ->assertJsonPath('sender.id', $member->id)
        ->assertJsonPath('message', 'Meet at the station');

    $this->actingAs($outsider, 'sanctum')
        ->postJson('/api/messages', $payload)
        ->assertNotFound();

    $notificationResponse = $this->actingAs($otherMember, 'sanctum')
        ->getJson('/api/notifications')
        ->assertOk()
        ->assertJsonPath('unread_count', 1)
        ->assertJsonPath('notifications.0.data.conversation_id', $conversation->id);

    $this->actingAs($otherMember, 'sanctum')
        ->postJson('/api/notifications/'.$notificationResponse->json('notifications.0.id').'/read')
        ->assertOk()
        ->assertJsonPath('unread_count', 0);
});

it('supports sending a message through the conversation route', function () {
    $member = User::factory()->create();
    $otherMember = User::factory()->create();
    $outsider = User::factory()->create();
    $conversation = makeConversationBetween($member, $otherMember);

    $this->actingAs($member, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/messages", ['message' => 'Hello from chat'])
        ->assertCreated()
        ->assertJsonPath('data.message', 'Hello from chat')
        ->assertJsonPath('data.sender.id', $member->id);

    $this->actingAs($outsider, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/messages", ['message' => 'Not allowed'])
        ->assertForbidden();
});

it('searches travelers without returning the signed-in user', function () {
    $currentUser = User::factory()->create(['name' => 'Current Traveler']);
    $match = User::factory()->create(['name' => 'Travel Partner']);
    User::factory()->create(['name' => 'Different Person']);

    $this->actingAs($currentUser, 'sanctum')
        ->getJson('/api/users?search=Travel')
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.id', $match->id);
});

it('shares active member presence with conversation participants', function () {
    $activeMember = User::factory()->create();
    $otherMember = User::factory()->create();
    $conversation = makeConversationBetween($activeMember, $otherMember);
    Cache::forget('travelmate:online:'.$activeMember->id);
    Cache::forget('travelmate:online:'.$otherMember->id);

    $this->actingAs($activeMember, 'sanctum')
        ->postJson('/api/presence')
        ->assertOk()
        ->assertJsonPath('online', true);

    $this->actingAs($otherMember, 'sanctum')
        ->getJson('/api/conversations')
        ->assertOk()
        ->assertJsonFragment([
            'id' => $activeMember->id,
            'name' => $activeMember->name,
            'is_online' => true,
        ])
        ->assertJsonFragment([
            'id' => $otherMember->id,
            'name' => $otherMember->name,
            'is_online' => false,
        ]);
});