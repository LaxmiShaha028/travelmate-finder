<?php

use App\Models\User;

test('account routes require authentication', function () {
    $this->patchJson('/api/user', ['name' => 'Changed'])->assertUnauthorized();
    $this->putJson('/api/travel-preferences', [])->assertUnauthorized();
});

test('profile updates only the authenticated user and ignores privileged fields', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $token = $user->createToken('test')->plainTextToken;
    $this->withToken($token)->patchJson('/api/user', [
        'name' => 'Updated Traveler', 'bio' => 'I love hiking.', 'role' => 'admin',
    ])->assertOk()->assertJsonPath('user.name', 'Updated Traveler');
    expect($user->fresh()->role)->toBe('user');
    expect($other->fresh()->name)->toBe($other->name);
    $this->withToken($token)->getJson('/api/user')->assertJsonPath('user.bio', 'I love hiking.');
});

test('all six answers are required and persist without duplicate preferences', function () {
    $user = User::factory()->create();
    $token = $user->createToken('test')->plainTextToken;
    $this->withToken($token)->putJson('/api/travel-preferences', ['destination' => 'Sylhet'])
        ->assertUnprocessable()->assertJsonValidationErrors(['date', 'budget', 'style', 'companions', 'interests']);
    $answers = ['destination' => 'Sylhet', 'date' => 'Next month', 'budget' => 'Flexible budget',
        'style' => 'Adventure', 'companions' => 'Friends', 'interests' => 'Photography'];
    $this->withToken($token)->putJson('/api/travel-preferences', $answers)->assertOk();
    $answers['destination'] = 'Dhaka';
    $this->withToken($token)->putJson('/api/travel-preferences', $answers)->assertOk();
    $this->withToken($token)->getJson('/api/user')
        ->assertJsonPath('user.travel_preference.answers', $answers);
    $this->assertDatabaseCount('travel_preferences', 1);
});

test('logout revokes the current token', function () {
    $user = User::factory()->create();
    $token = $user->createToken('test')->plainTextToken;
    $this->withToken($token)->postJson('/api/logout')->assertOk();
    $this->assertDatabaseCount('personal_access_tokens', 0);
});
