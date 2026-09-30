<?php

use App\Models\Trip;
use App\Models\User;
use App\Services\TripPreferenceMatcher;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;

function tripMatchAnswers(): array
{
    return ['destination' => 'Sylhet', 'date' => 'Next month', 'budget' => '৳5,000 – ৳10,000',
        'style' => 'Adventure', 'companions' => 'Friends', 'interests' => 'Photography'];
}

function tripMatchCandidate(User $host, array $attributes = []): Trip
{
    return $host->trips()->create($attributes + [
        'title' => 'Tea garden adventure', 'destination' => 'Sylhet',
        'start_date' => '2026-10-10', 'end_date' => '2026-10-12',
        'duration_days' => 3, 'budget' => 7500, 'travel_style' => 'Adventure', 'status' => 'open',
    ]);
}

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-09-30 12:00:00'));
});

test('trip scoring compares month budget destination and style independently', function () {
    $matcher = new TripPreferenceMatcher;
    $trip = new Trip(['destination' => ' SYLHET ', 'start_date' => '2026-10-31', 'budget' => 10000, 'travel_style' => 'adventure']);
    $today = CarbonImmutable::parse('2026-09-30');
    expect($matcher->compare(tripMatchAnswers(), $trip, $today)['percentage'])->toBe(100);
    $trip->start_date = '2026-11-01';
    expect($matcher->compare(tripMatchAnswers(), $trip, $today)['percentage'])->toBe(75);
    $trip->budget = 10001;
    $trip->travel_style = null;
    expect($matcher->compare(tripMatchAnswers(), $trip, $today)['percentage'])->toBe(25);
    $answers = array_replace(tripMatchAnswers(), ['date' => 'Not decided yet', 'budget' => 'Flexible budget']);
    expect($matcher->compare($answers, $trip, $today)['percentage'])->toBe(50);
    $answers['budget'] = 'A custom budget';
    expect($matcher->compare($answers, $trip, $today)['percentage'])->toBe(25);
});

test('timing ranges handle year boundaries and do not guess ambiguous timing', function () {
    $matcher = new TripPreferenceMatcher;
    $today = CarbonImmutable::parse('2026-12-31');
    $trip = new Trip(['destination' => 'Sylhet', 'start_date' => '2027-01-01', 'budget' => 7500, 'travel_style' => 'Adventure']);
    expect($matcher->compare(tripMatchAnswers(), $trip, $today)['criteria'][1]['matched'])->toBeTrue();
    foreach (['Weekend trip', 'Long vacation', 'During holidays', 'Not decided yet', 'Some time'] as $answer) {
        expect($matcher->compare(array_replace(tripMatchAnswers(), ['date' => $answer]), $trip, $today)['criteria'][1]['matched'])->toBeFalse();
    }
    $trip->start_date = '2027-03-31';
    expect($matcher->compare(array_replace(tripMatchAnswers(), ['date' => 'In 2–3 months']), $trip, $today)['criteria'][1]['matched'])->toBeTrue();
    $trip->start_date = '2027-04-01';
    expect($matcher->compare(array_replace(tripMatchAnswers(), ['date' => 'In 2–3 months']), $trip, $today)['criteria'][1]['matched'])->toBeFalse();
});

test('trip recommendations require authentication and completed answers', function () {
    $this->getJson('/api/user/trip-matches')->assertUnauthorized();
    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/user/trip-matches')->assertOk()->assertJsonPath('preferences_complete', false)
        ->assertJsonPath('best_match', null)->assertJsonPath('data', []);
    $this->getJson('/api/user/trip-matches?page=0')->assertUnprocessable();
});

test('trip ranking is global paginated stable and excludes unavailable trips', function () {
    $viewer = User::factory()->create();
    $viewer->travelPreference()->create(['answers' => tripMatchAnswers()]);
    $host = User::factory()->create();
    $others = collect(range(1, 8))->map(fn () => tripMatchCandidate($host, ['travel_style' => 'Beach']));
    $best = tripMatchCandidate($host);
    tripMatchCandidate($viewer);
    tripMatchCandidate(User::factory()->create(['is_blocked' => true]));
    tripMatchCandidate(User::factory()->create(['role' => 'admin']));
    foreach (['draft', 'completed', 'cancelled'] as $status) {
        tripMatchCandidate($host, ['status' => $status]);
    }
    tripMatchCandidate($host, ['start_date' => '2026-09-29']);
    tripMatchCandidate($host, ['destination' => 'Dhaka', 'start_date' => '2026-12-01', 'end_date' => '2026-12-03', 'budget' => 30000, 'travel_style' => 'Luxury']);
    Sanctum::actingAs($viewer);
    $response = $this->getJson('/api/user/trip-matches')->assertOk()
        ->assertJsonPath('best_match.id', $best->id)->assertJsonPath('best_match.percentage', 100)
        ->assertJsonPath('meta.total', 9)->assertJsonCount(6, 'data')->assertJsonPath('data.0.id', $others[0]->id);
    expect($response->json('best_match'))->not->toHaveKeys(['email', 'password']);
    $this->getJson('/api/user/trip-matches?page=2')->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonPath('best_match.id', $best->id)->assertJsonPath('data.0.id', $others[6]->id);
    $this->putJson('/api/travel-preferences', array_replace(tripMatchAnswers(), ['style' => 'Beach']))->assertOk();
    $this->getJson('/api/user/trip-matches')->assertJsonPath('best_match.id', $others[0]->id);
});

test('no matching upcoming trips returns an empty result', function () {
    $viewer = User::factory()->create();
    $viewer->travelPreference()->create(['answers' => tripMatchAnswers()]);
    Sanctum::actingAs($viewer);
    $this->getJson('/api/user/trip-matches')->assertOk()->assertJsonPath('preferences_complete', true)
        ->assertJsonPath('best_match', null)->assertJsonPath('data', [])->assertJsonPath('meta.total', 0);
});
