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

it('resolves a legacy message sender from user_id', function () {
    $message = new Message();
    $message->setRawAttributes(['sender_id' => null, 'user_id' => 42]);

    expect($message->sender_id)->toBe(42);
});

it('exposes legacy message body fields through the message attribute', function () {
    $message = new Message();
    $message->setRawAttributes(['message' => null, 'body' => 'Message from older chat data']);

    expect($message->message)->toBe('Message from older chat data');
});

it('keeps the conversation list empty until the signed-in user is a member', function () {
    $viewer = User::factory()->create();
    $firstMember = User::factory()->create();
    $secondMember = User::factory()->create();
    makeConversationBetween($firstMember, $secondMember);

    $this->actingAs($viewer, 'sanctum')
        ->getJson('/api/conversations')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $created = $this->actingAs($viewer, 'sanctum')
        ->postJson('/api/conversations', ['user_id' => $firstMember->id])
        ->assertCreated();

    $this->actingAs($viewer, 'sanctum')
        ->getJson('/api/conversations')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $created->json('data.id'));
});

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

    $this->assertDatabaseHas('messages', [
        'conversation_id' => $conversation->id,
        'user_id' => $member->id,
        'sender_id' => $member->id,
        'message' => 'Meet at the station',
    ]);

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

it('allows a conversation member to remove their own sidebar entry only', function () {
    $member = User::factory()->create();
    $otherMember = User::factory()->create();
    $outsider = User::factory()->create();
    $conversation = makeConversationBetween($member, $otherMember);

    $this->actingAs($outsider, 'sanctum')
        ->deleteJson('/api/conversations/'.$conversation->id)
        ->assertForbidden();

    $this->actingAs($member, 'sanctum')
        ->deleteJson('/api/conversations/'.$conversation->id)
        ->assertOk();

    expect($conversation->fresh()->users()->pluck('users.id')->all())->toBe([$otherMember->id]);
});

it('lets both private chat members send and retrieve persisted messages', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();
    $conversation = makeConversationBetween($first, $second);

    $firstMessage = $this->actingAs($first, 'sanctum')
        ->postJson('/api/messages', [
            'conversation_id' => $conversation->id,
            'message' => 'Hello from the first traveler',
        ])
        ->assertCreated()
        ->assertJsonPath('sender.id', $first->id)
        ->json('id');

    $secondMessage = $this->actingAs($second, 'sanctum')
        ->postJson('/api/messages', [
            'conversation_id' => $conversation->id,
            'message' => 'Hello from the second traveler',
        ])
        ->assertCreated()
        ->assertJsonPath('sender.id', $second->id)
        ->json('id');

    $this->actingAs($first, 'sanctum')
        ->getJson('/api/conversations/'.$conversation->id.'/messages')
        ->assertOk()
        ->assertJsonCount(2)
        ->assertJsonPath('0.id', $firstMessage)
        ->assertJsonPath('0.message', 'Hello from the first traveler')
        ->assertJsonPath('1.id', $secondMessage)
        ->assertJsonPath('1.message', 'Hello from the second traveler');

    $this->actingAs($second, 'sanctum')
        ->getJson('/api/conversations/'.$conversation->id.'/messages')
        ->assertOk()
        ->assertJsonCount(2);
});

it('lets all group members share messages and keeps outsiders out', function () {
    $owner = User::factory()->create();
    $members = User::factory()->count(2)->create();
    $outsider = User::factory()->create();

    $conversationId = $this->actingAs($owner, 'sanctum')
        ->postJson('/api/conversations', [
            'type' => 'group',
            'name' => 'Shared trip',
            'user_ids' => $members->pluck('id')->all(),
        ])
        ->assertCreated()
        ->assertJsonCount(3, 'data.users')
        ->json('data.id');

    $this->actingAs($owner, 'sanctum')
        ->postJson('/api/messages', [
            'conversation_id' => $conversationId,
            'message' => 'Planning starts here',
        ])
        ->assertCreated();

    $this->actingAs($members[0], 'sanctum')
        ->postJson('/api/messages', [
            'conversation_id' => $conversationId,
            'message' => 'I can join',
        ])
        ->assertCreated();

    $this->actingAs($members[1], 'sanctum')
        ->getJson('/api/conversations/'.$conversationId.'/messages')
        ->assertOk()
        ->assertJsonCount(2)
        ->assertJsonPath('0.message', 'Planning starts here')
        ->assertJsonPath('1.message', 'I can join');

    $this->actingAs($members[0], 'sanctum')
        ->getJson('/api/conversations')
        ->assertOk()
        ->assertJsonPath('data.0.id', $conversationId);

    $this->actingAs($outsider, 'sanctum')
        ->getJson('/api/conversations/'.$conversationId.'/messages')
        ->assertNotFound();
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
    User::factory()->create(['name' => 'Blocked Traveler', 'is_blocked' => true]);
    User::factory()->create(['name' => 'Travel Admin', 'role' => 'admin']);

    $this->actingAs($currentUser, 'sanctum')
        ->getJson('/api/users?search=Travel')
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.id', $match->id);
});

    it('searches contact names by case-insensitive terms in any order', function () {
        $currentUser = User::factory()->create();
        $match = User::factory()->create(['name' => 'Shaha Laxmi']);

        $this->actingAs($currentUser, 'sanctum')
        ->getJson('/api/users?search=LAXMI%20Shaha')
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