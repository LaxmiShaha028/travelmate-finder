<?php

use App\Models\User;
use App\Models\UserReview;
use App\Services\UserReputation;
use Laravel\Sanctum\Sanctum;

test('any signed in traveler can review another without sharing a trip and update one review', function () {
    $author = User::factory()->create();
    $target = User::factory()->create();
    $this->putJson('/api/travelers/'.$target->id.'/review', ['rating' => 5, 'body' => 'Friendly traveler'])->assertUnauthorized();
    Sanctum::actingAs($author);
    $this->putJson('/api/travelers/'.$target->id.'/review', ['rating' => 5, 'body' => 'Friendly traveler'])->assertOk();
    $this->putJson('/api/travelers/'.$target->id.'/review', ['rating' => 3, 'body' => 'Updated review'])->assertOk();
    $this->assertDatabaseCount('user_reviews', 1);
    $this->getJson('/api/travelers/'.$target->id.'/reviews')->assertOk()
        ->assertJsonPath('summary.count', 1)->assertJsonPath('data.0.rating', 3)
        ->assertJsonPath('my_review.body', 'Updated review');
});

test('self reviews invalid ratings blank text and blocked authors are rejected', function () {
    $author = User::factory()->create();
    $target = User::factory()->create();
    Sanctum::actingAs($author);
    $this->putJson('/api/travelers/'.$author->id.'/review', ['rating' => 5, 'body' => 'Self review'])->assertForbidden();
    foreach ([0, 6, 1.5] as $rating) {
        $this->putJson('/api/travelers/'.$target->id.'/review', ['rating' => $rating, 'body' => 'Review'])->assertUnprocessable();
    }
    $this->putJson('/api/travelers/'.$target->id.'/review', ['rating' => 4, 'body' => '   '])->assertUnprocessable();
    $this->putJson('/api/travelers/'.$target->id.'/review', ['rating' => 4, 'body' => str_repeat('a', 2001)])->assertUnprocessable();
    $author->update(['is_blocked' => true]);
    $this->putJson('/api/travelers/'.$target->id.'/review', ['rating' => 5, 'body' => 'Review'])->assertForbidden();
});

test('users can only delete their own review and received reviews are public without private data', function () {
    $target = User::factory()->create();
    $author = User::factory()->create();
    UserReview::create(['reviewer_id' => $author->id, 'reviewed_user_id' => $target->id, 'rating' => 5, 'body' => 'Helpful']);
    $response = $this->getJson('/api/travelers/'.$target->id.'/reviews')->assertOk()->assertJsonPath('my_review', null);
    expect($response->json('data.0.reviewer'))->not->toHaveKeys(['email', 'password', 'date_of_birth']);
    Sanctum::actingAs($target);
    $this->deleteJson('/api/travelers/'.$target->id.'/review', ['reviewer_id' => $author->id])->assertNoContent();
    $this->assertDatabaseCount('user_reviews', 1);
    Sanctum::actingAs($author);
    $this->deleteJson('/api/travelers/'.$target->id.'/review')->assertNoContent();
    $this->getJson('/api/travelers/'.$target->id.'/reviews')->assertJsonPath('summary.average', null)->assertJsonPath('summary.count', 0);
});

test('ratings distribution pagination and badges reflect current database records', function () {
    $target = User::factory()->create();
    foreach ([5, 5, 5, 5, 4, 4] as $rating) {
        UserReview::create(['reviewer_id' => User::factory()->create()->id, 'reviewed_user_id' => $target->id, 'rating' => $rating, 'body' => 'Review']);
    }
    $response = $this->getJson('/api/travelers/'.$target->id.'/reviews')->assertOk()
        ->assertJsonPath('summary.average', 4.7)->assertJsonPath('summary.count', 6)
        ->assertJsonPath('summary.distribution.0.count', 4)->assertJsonPath('summary.badges.3.earned', true)
        ->assertJsonCount(5, 'data');
    $this->getJson('/api/travelers/'.$target->id.'/reviews?page=2')->assertJsonCount(1, 'data');
    $this->getJson('/api/travelers/'.$target->id.'/reviews?page=0')->assertUnprocessable();
    $blockedAuthor = User::find($response->json('data.0.reviewer.id'));
    $blockedAuthor->update(['is_blocked' => true]);
    $this->getJson('/api/travelers/'.$target->id.'/reviews')->assertJsonPath('summary.count', 5);
    $target->update(['is_blocked' => true]);
    $this->getJson('/api/travelers/'.$target->id.'/reviews')->assertNotFound();
});

test('activity badges describe preferences hosting and reviews actually written', function () {
    $user = User::factory()->create();
    $reputation = app(UserReputation::class);
    expect(array_column($reputation->summary($user)['badges'], 'earned'))->toBe([false, false, false, false]);
    $user->travelPreference()->create(['answers' => ['destination' => 'Sylhet', 'date' => 'Next month', 'budget' => 'Flexible budget', 'style' => 'Adventure', 'companions' => 'Friends', 'interests' => 'Nature']]);
    $user->trips()->create(['title' => 'Sylhet', 'destination' => 'Sylhet', 'start_date' => today()->addDays(1), 'end_date' => today()->addDays(2), 'duration_days' => 2, 'budget' => 5000, 'status' => 'open']);
    foreach (range(1, 3) as $i) {
        UserReview::create(['reviewer_id' => $user->id, 'reviewed_user_id' => User::factory()->create()->id, 'rating' => 4, 'body' => 'Review']);
    }
    expect(array_column($reputation->summary($user)['badges'], 'earned'))->toBe([true, true, true, false]);
    UserReview::where('reviewer_id', $user->id)->first()->delete();
    expect($reputation->summary($user)['badges'][2]['earned'])->toBeFalse();
});
