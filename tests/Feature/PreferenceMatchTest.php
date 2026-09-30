<?php

use App\Models\User;
use App\Services\PreferenceMatcher;
use Laravel\Sanctum\Sanctum;

function matchAnswers(): array
{
    return ['destination' => 'Sylhet', 'date' => 'Next month', 'budget' => 'Flexible budget',
        'style' => 'Adventure', 'companions' => 'Small Groups', 'interests' => 'Photography'];
}

function matchingTraveler(array $answers, array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    $user->travelPreference()->create(['answers' => $answers]);

    return $user;
}

test('matching normalizes whitespace and case but does not treat flexible answers as wildcards', function () {
    $matcher = new PreferenceMatcher;
    $score = $matcher->compare(matchAnswers(), array_replace(matchAnswers(), [
        'destination' => ' SYLHET ', 'companions' => 'small   groups', 'style' => 'Beach', 'interests' => 'Relaxing',
    ]));
    expect($score['matched_count'])->toBe(4)->and($score['percentage'])->toBe(67);
    expect($matcher->compare([], [])['percentage'])->toBe(0);
    expect($matcher->compare(['budget' => 'Flexible budget'], ['budget' => 'Under 5000'])['matched_count'])->toBe(0);
    expect($matcher->isComplete(['destination' => 'Sylhet']))->toBeFalse();
});

test('matches require authentication and completed preferences', function () {
    $this->getJson('/api/user/matches')->assertUnauthorized();
    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/user/matches')->assertOk()->assertJsonPath('preferences_complete', false)
        ->assertJsonPath('best_match', null)->assertJsonPath('data', []);
    $this->getJson('/api/user/matches?page=0')->assertUnprocessable();
});

test('global ranking excludes ineligible users and paginates others with deterministic ties', function () {
    $mine = matchingTraveler(matchAnswers());
    $partial = array_replace(matchAnswers(), ['style' => 'Beach', 'interests' => 'Relaxing']);
    $others = collect(range(1, 8))->map(fn () => matchingTraveler($partial));
    $best = matchingTraveler(matchAnswers());
    matchingTraveler(matchAnswers(), ['is_blocked' => true]);
    matchingTraveler(matchAnswers(), ['role' => 'admin']);
    matchingTraveler(['destination' => 'Sylhet']);
    User::factory()->create();
    matchingTraveler(array_fill_keys(PreferenceMatcher::FIELDS, 'Different'));
    Sanctum::actingAs($mine);
    $response = $this->getJson('/api/user/matches')->assertOk()
        ->assertJsonPath('best_match.id', $best->id)->assertJsonPath('best_match.percentage', 100)
        ->assertJsonPath('meta.total', 9)->assertJsonCount(6, 'data')
        ->assertJsonPath('data.0.id', $others[0]->id)->assertJsonPath('data.0.percentage', 67);
    expect($response->json('best_match'))->not->toHaveKeys(['email', 'password', 'date_of_birth']);
    $this->getJson('/api/user/matches?page=2')->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonPath('best_match.id', $best->id)->assertJsonPath('data.0.id', $others[6]->id);
    $this->putJson('/api/travel-preferences', $partial)->assertOk();
    $this->getJson('/api/user/matches')->assertJsonPath('best_match.id', $others[0]->id);
});

test('completed preferences with no shared answers return an empty result', function () {
    Sanctum::actingAs(matchingTraveler(matchAnswers()));
    matchingTraveler(array_fill_keys(PreferenceMatcher::FIELDS, 'Different'));
    $this->getJson('/api/user/matches')->assertOk()->assertJsonPath('preferences_complete', true)
        ->assertJsonPath('best_match', null)->assertJsonPath('data', [])->assertJsonPath('meta.total', 0);
});
