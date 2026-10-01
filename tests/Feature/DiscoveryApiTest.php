<?php

use App\Models\User;
use Database\Seeders\DiscoveryDemoSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function discoveryUser(array $attributes = []): User
{
    return User::factory()->create($attributes);
}

function discoveryTrip(User $user, array $attributes = [])
{
    return $user->trips()->create($attributes + [
        'title' => 'Tea garden adventure', 'destination' => 'Sylhet',
        'start_date' => today()->addDays(10), 'end_date' => today()->addDays(13),
        'duration_days' => 4, 'budget' => 7500, 'travel_style' => 'Adventure', 'status' => 'open',
    ]);
}

test('unfiltered travelers include users without preferences and exclude blocked and admin accounts', function () {
    $user = discoveryUser();
    discoveryUser();
    discoveryUser(['is_blocked' => true]);
    discoveryUser(['role' => 'admin']);
    $response = $this->getJson('/api/travelers?per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2);
    expect($response->json('data.0'))->not->toHaveKeys(['email', 'password', 'date_of_birth', 'remember_token']);
    $this->getJson('/api/travelers/'.$user->id)->assertOk()->assertJsonPath('data.preferences', null);
});

test('traveler filters combine destination date age style duration and overlapping budget', function () {
    $match = discoveryUser(['date_of_birth' => today()->subYears(25)]);
    $match->travelPreference()->create([
        'preferred_destinations' => ['Sylhet'], 'travel_style' => 'Adventure',
        'travel_start' => today()->addDays(10), 'travel_end' => today()->addDays(13),
        'duration_days' => 4, 'min_budget' => 5000, 'max_budget' => 10000,
    ]);
    $tooYoung = discoveryUser(['date_of_birth' => today()->subYears(25)->addDay()]);
    $tooYoung->travelPreference()->create($match->travelPreference->only([
        'preferred_destinations', 'travel_style', 'travel_start', 'travel_end', 'duration_days', 'min_budget', 'max_budget',
    ]));
    discoveryUser();
    $params = ['destination' => 'Sylhet', 'date' => today()->addDays(12)->format('Y-m-d'),
        'travel_style' => 'Adventure', 'min_age' => 25, 'max_age' => 34,
        'min_duration' => 3, 'max_duration' => 4, 'min_budget' => 8000, 'max_budget' => 12000];
    $this->getJson('/api/travelers?'.http_build_query($params))->assertOk()
        ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $match->id);
    $params['destination'] = 'Dhaka';
    $this->getJson('/api/travelers?'.http_build_query($params))->assertOk()->assertJsonPath('data', []);
});

test('invalid filters return validation errors and upper-only budgets work', function () {
    $this->getJson('/api/travelers?min_age=40&max_age=20&per_page=999')->assertUnprocessable();
    $this->getJson('/api/trips?min_budget=9000&max_budget=1000&date=tomorrow')->assertUnprocessable();
    $this->getJson('/api/trips?max_budget=5000')->assertOk();
    $this->getJson('/api/travelers?max_age=24')->assertOk();
});

test('oversized discovery page sizes are capped at fifty', function () {
    $this->getJson('/api/travelers?per_page=999')
        ->assertOk()
        ->assertJsonPath('meta.per_page', 50);

    $this->getJson('/api/trips?per_page=999')
        ->assertOk()
        ->assertJsonPath('meta.per_page', 50);
});

test('trip creation ownership visibility filters and dynamic statistics', function () {
    $owner = discoveryUser();
    $other = discoveryUser();
    $payload = ['title' => 'Sylhet weekend', 'destination' => 'Sylhet',
        'start_date' => today()->addDays(10)->format('Y-m-d'), 'end_date' => today()->addDays(13)->format('Y-m-d'),
        'budget' => 7500, 'travel_style' => 'Adventure', 'max_travelers' => 4];
    $this->postJson('/api/trips', $payload)->assertUnauthorized();
    $token = $owner->createToken('test')->plainTextToken;
    $created = $this->withToken($token)->postJson('/api/trips', $payload + ['user_id' => $other->id])->assertCreated()
        ->assertJsonPath('data.duration_days', 4)->assertJsonPath('data.organizer.id', $owner->id);
    $id = $created->json('data.id');
    $this->getJson('/api/trips?destination=Sylhet&min_duration=3&max_duration=4&max_budget=8000')
        ->assertOk()->assertJsonPath('meta.total', 1);
    $this->getJson('/api/stats')->assertJsonPath('data.travelers', 2)->assertJsonPath('data.trips', 1)->assertJsonPath('data.destinations', 1);
    app('auth')->forgetGuards();
    $this->withToken($other->createToken('other')->plainTextToken)->patchJson('/api/trips/'.$id, ['status' => 'cancelled'])->assertForbidden();
    app('auth')->forgetGuards();
    $this->withToken($token)->patchJson('/api/trips/'.$id, ['status' => 'cancelled'])->assertOk();
    $this->getJson('/api/trips')->assertJsonPath('meta.total', 0);
    $this->getJson('/api/stats')->assertJsonPath('data.trips', 0)->assertJsonPath('data.destinations', 0);
    $this->withToken($token)->getJson('/api/user/trips')->assertJsonPath('data.0.status', 'cancelled');
    app('auth')->forgetGuards();
    $this->withToken($other->createToken('other2')->plainTextToken)->getJson('/api/trips/'.$id)->assertNotFound();
});

test('invalid trip dates and statuses are rejected', function () {
    $token = discoveryUser()->createToken('test')->plainTextToken;
    $payload = ['title' => 'Trip', 'destination' => 'Dhaka', 'start_date' => today()->addDays(4)->format('Y-m-d'),
        'end_date' => today()->addDays(2)->format('Y-m-d'), 'budget' => 100];
    $this->withToken($token)->postJson('/api/trips', $payload)->assertUnprocessable()->assertJsonValidationErrors('end_date');
    $payload['status'] = 'invalid';
    $this->withToken($token)->postJson('/api/trips', $payload)->assertUnprocessable()->assertJsonValidationErrors('status');
});

test('structured preferences persist and legacy questionnaire remains compatible', function () {
    $user = discoveryUser();
    $token = $user->createToken('test')->plainTextToken;
    $answers = ['destination' => 'Sylhet', 'date' => 'Next month', 'budget' => 'Flexible budget',
        'style' => 'Adventure', 'companions' => 'Friends', 'interests' => 'Nature'];
    $this->withToken($token)->putJson('/api/travel-preferences', $answers + [
        'travel_start' => '2027-01-01', 'travel_end' => '2027-01-04', 'min_budget' => 5000, 'max_budget' => 10000, 'duration_days' => 4,
    ])->assertOk();
    $this->getJson('/api/travelers?date=2027-01-02')->assertJsonPath('meta.total', 1);
    $this->withToken($token)->putJson('/api/travel-preferences', $answers)->assertOk();
    $this->getJson('/api/travelers?date=2027-01-02')->assertJsonPath('meta.total', 0);
});

test('photo upload returns a public URL replaces old files and can be removed', function () {
    Storage::fake('public');
    $user = discoveryUser();
    $token = $user->createToken('test')->plainTextToken;
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
    $image = fn () => UploadedFile::fake()->createWithContent('avatar.png', $png);
    $this->postJson('/api/user/photo', ['photo' => $image()])->assertUnauthorized();
    $response = $this->withToken($token)->postJson('/api/user/photo', ['photo' => $image()])->assertOk();
    expect($response->json('user.profile_photo_url'))->toStartWith(url('/api/travelers/'.$user->id.'/photo').'?v=');
    $old = $user->fresh()->profile_photo;
    Storage::disk('public')->assertExists($old);
    $this->get('/api/travelers/'.$user->id.'/photo')->assertOk()->assertHeader('Content-Type', 'image/png');
    $this->withToken($token)->postJson('/api/user/photo', ['photo' => $image()])->assertOk();
    Storage::disk('public')->assertMissing($old);
    $current = $user->fresh()->profile_photo;
    $this->withToken($token)->deleteJson('/api/user/photo')->assertOk()->assertJsonPath('user.profile_photo_url', null);
    Storage::disk('public')->assertMissing($current);
    $this->get('/api/travelers/'.$user->id.'/photo')->assertNotFound();
});

test('admin profile photos can be fetched from their profile photo URL', function () {
    Storage::fake('public');
    $admin = discoveryUser(['role' => 'admin']);
    $token = $admin->createToken('test')->plainTextToken;
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');

    $upload = $this->withToken($token)->postJson('/api/user/photo', [
        'photo' => UploadedFile::fake()->createWithContent('admin-avatar.png', $png),
    ])->assertOk();

    $this->get($upload->json('user.profile_photo_url'))->assertOk()->assertHeader('Content-Type', 'image/png');
});

test('photo uploads reject non-images and oversized files', function () {
    Storage::fake('public');
    $token = discoveryUser()->createToken('test')->plainTextToken;
    $this->withToken($token)->postJson('/api/user/photo', ['photo' => UploadedFile::fake()->create('script.svg', 1, 'image/svg+xml')])
        ->assertUnprocessable()->assertJsonValidationErrors('photo');
    $this->withToken($token)->postJson('/api/user/photo', ['photo' => UploadedFile::fake()->create('huge.png', 6000, 'image/png')])
        ->assertUnprocessable()->assertJsonValidationErrors('photo');
});

test('demo seeder is repeatable and filter options expose matching values', function () {
    $this->seed(DiscoveryDemoSeeder::class);
    $this->seed(DiscoveryDemoSeeder::class);
    $this->assertDatabaseCount('users', 12);
    $this->assertDatabaseCount('trips', 12);
    $this->assertDatabaseCount('travel_preferences', 12);
    $this->getJson('/api/stats')->assertJsonPath('data.travelers', 12)->assertJsonPath('data.trips', 12)->assertJsonPath('data.destinations', 6);
    $options = $this->getJson('/api/filter-options')->assertOk();
    expect($options->json('data.destinations'))->toContain('Sylhet');
});
