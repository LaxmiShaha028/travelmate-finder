<?php

use App\Models\User;

test('api login reports whether the user has saved travel preferences', function () {
    $user = User::factory()->create();
    $user->travelPreference()->create([
        'answers' => ['destination' => 'Cox’s Bazar'],
    ]);

    $response = $this->postJson('/api/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('user.has_preferences', true)
        ->assertJsonPath('user.travel_preference.answers.destination', 'Cox’s Bazar');
});