<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserReview;
use App\Notifications\TravelActivityNotification;
use App\Services\UserReputation;
use Illuminate\Http\Request;

class UserReviewController extends Controller
{
    private function ensureVisible(User $user): void
    {
        abort_if($user->is_blocked || $user->role !== 'user', 404);
    }

    public function index(Request $request, User $user, UserReputation $reputation)
    {
        $this->ensureVisible($user);
        $request->validate(['page' => 'sometimes|integer|min:1']);
        $reviews = UserReview::visible()->where('reviewed_user_id', $user->id)
            ->with('reviewer')->latest('created_at')->latest('id')->paginate(5);
        $viewer = $request->user('sanctum');
        $mine = $viewer && ! $viewer->is_blocked
            ? UserReview::where('reviewed_user_id', $user->id)->where('reviewer_id', $viewer->id)->first()
            : null;

        return response()->json([
            'summary' => $reputation->summary($user),
            'my_review' => $mine?->only(['rating', 'body']),
            'data' => $reviews->getCollection()->map(fn ($review) => [
                'id' => $review->id, 'rating' => $review->rating, 'body' => $review->body,
                'created_at' => $review->created_at->toIso8601String(),
                'updated_at' => $review->updated_at->toIso8601String(),
                'reviewer' => $review->reviewer->only(['id', 'name', 'profile_photo_url']),
            ]),
            'meta' => ['current_page' => $reviews->currentPage(), 'last_page' => $reviews->lastPage()],
        ]);
    }

    public function store(Request $request, User $user)
    {
        $this->ensureVisible($user);
        $viewer = $request->user();
        abort_if($viewer->is_blocked || $viewer->role !== 'user', 403);
        abort_if($viewer->id === $user->id, 403, 'You cannot review yourself.');
        $data = $request->validate(['rating' => 'required|integer|between:1,5', 'body' => 'required|string|max:2000']);
        $data['body'] = trim($data['body']);
        if ($data['body'] === '') {
            throw \Illuminate\Validation\ValidationException::withMessages(['body' => 'Write a review before saving.']);
        }
        $review = UserReview::updateOrCreate(['reviewer_id' => $viewer->id, 'reviewed_user_id' => $user->id], $data);
        if ($review->wasRecentlyCreated || $review->wasChanged()) {
            $user->notify(new TravelActivityNotification(
                'review_received',
                'New traveler review',
                "{$viewer->name} left you a review.",
                [
                    'review_id' => $review->id,
                    'reviewer_id' => $viewer->id,
                    'reviewer_name' => $viewer->name,
                    'rating' => $review->rating,
                    'url' => '/travelers/'.$user->id,
                ],
            ));
        }

        return response()->json(['message' => 'Your review has been saved.']);
    }

    public function destroy(Request $request, User $user)
    {
        $this->ensureVisible($user);
        abort_if($request->user()->is_blocked || $request->user()->role !== 'user', 403);
        // The authenticated author is the only review identity accepted for deletion.
        UserReview::where('reviewer_id', $request->user()->id)->where('reviewed_user_id', $user->id)->delete();

        return response()->noContent();
    }
}
