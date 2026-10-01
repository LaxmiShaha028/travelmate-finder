<?php

use App\Models\User;
use Laravel\Sanctum\Sanctum;

test('trip cards expose creation time and put today before upcoming and past trips across pages', function () {
    $this->travelTo(\Carbon\Carbon::parse('2026-09-30 12:00:00', 'Asia/Dhaka'));
    $user = User::factory()->create();
    $trips = [];
    foreach (['2026-10-05', '2026-09-29', '2026-10-01', '2026-09-30', '2026-09-30'] as $start) {
        $trips[] = $user->trips()->create([
            'title' => 'Trip '.$start, 'destination' => 'Sylhet',
            'start_date' => $start, 'end_date' => $start,
            'duration_days' => 1, 'budget' => 5000, 'status' => 'open',
        ]);
    }
    $expected = [$trips[3]->id, $trips[4]->id, $trips[2]->id, $trips[0]->id, $trips[1]->id];
    $response = $this->getJson('/api/trips')->assertOk();
    expect(array_column($response->json('data'), 'id'))->toBe($expected);
    $response->assertJsonPath('data.0.created_at', $trips[3]->created_at->toISOString());
    $this->getJson('/api/trips?per_page=2&page=2')->assertOk()
        ->assertJsonPath('data.0.id', $trips[2]->id)->assertJsonPath('data.1.id', $trips[0]->id);
    Sanctum::actingAs($user);
    $mine = $this->getJson('/api/user/trips')->assertOk();
    expect(array_column($mine->json('data'), 'id'))->toBe($expected);
    $this->travelBack();
});
