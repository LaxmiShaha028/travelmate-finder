<?php

use App\Models\User;
use Laravel\Sanctum\Sanctum;

function validPreferenceAnswers(): array
{
    return ['destination' => 'Sylhet', 'date' => 'Next month', 'budget' => 'Flexible budget',
        'style' => 'Adventure', 'companions' => 'Small Groups', 'interests' => 'Photography'];
}

test('rejects invalid preference values without overwriting saved answers', function (string $field, mixed $value) {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $this->putJson('/api/travel-preferences', validPreferenceAnswers())->assertOk();
    $this->putJson('/api/travel-preferences', array_replace(validPreferenceAnswers(), [$field => $value]))
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    expect($user->fresh()->travelPreference->answers)->toBe(validPreferenceAnswers());
})->with([
    ['destination', '123'], ['destination', 'John'], ['destination', ['Sylhet']],
    ['date', '2000-01-01'], ['date', '2099-02-30'], ['date', 'someday'],
    ['budget', 'zero'], ['budget', '-1'], ['budget', '0'], ['budget', '1.234'],
    ['budget', '1e4'], ['budget', '100000000'], ['style', 'John'],
    ['companions', '123'], ['interests', 'anything'], ['destination', ''],
]);

test('accepts supported alternatives and valid custom budget and date', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $answers = ['destination' => 'Sreemangal', 'date' => now('Asia/Dhaka')->toDateString(),
        'budget' => '15000.50', 'style' => 'Camping', 'companions' => 'Family', 'interests' => 'History'];
    $this->putJson('/api/travel-preferences', $answers)->assertOk();
    expect($user->fresh()->travelPreference->answers)->toBe($answers);
});
