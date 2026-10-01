<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TripResource;
use App\Models\Trip;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TravelRequestController extends Controller
{
    public function index(Request $request, Trip $trip)
    {
        $this->authorizeOwner($request, $trip);

        return $this->requestList($trip, $request->user()->id);
    }

    public function store(Request $request, Trip $trip)
    {
        abort_if($request->user()->is_blocked || $request->user()->role !== 'user', 403);
        abort_if($trip->user_id === $request->user()->id, 422, 'You cannot request your own trip.');

        return DB::transaction(function () use ($request, $trip) {
            $trip = Trip::whereKey($trip->id)->lockForUpdate()->firstOrFail();
            abort_unless($trip->status === 'open' && ! $trip->user->is_blocked && $trip->user->role === 'user', 422, 'This trip is not accepting requests.');
            // Requests may wait for a spot even when all places are filled.
            $trip->travelRequests()->firstOrCreate(['user_id' => $request->user()->id]);

            return new TripResource(Trip::with('user')->withRequestSummary($request->user()->id)->findOrFail($trip->id));
        }, 3);
    }

    public function update(Request $request, Trip $trip, int $travelRequest)
    {
        $this->authorizeOwner($request, $trip);
        $data = $request->validate(['status' => ['required', Rule::in(['accepted', 'rejected', 'pending'])]]);

        return DB::transaction(function () use ($request, $trip, $travelRequest, $data) {
            // Every capacity-changing operation locks this trip before counting places.
            $trip = Trip::whereKey($trip->id)->lockForUpdate()->firstOrFail();
            $application = $trip->travelRequests()->findOrFail($travelRequest);
            if ($data['status'] === 'accepted') {
                abort_unless($trip->status === 'open', 422, 'Only open trips can accept travelers.');
                abort_if($application->user->is_blocked || $application->user->role !== 'user', 422, 'This traveler cannot be accepted.');
                $accepted = $trip->travelRequests()->where('status', 'accepted')->where('id', '!=', $application->id)->count();
                abort_if($accepted >= $trip->max_travelers, 409, 'This trip is full. Remove an accepted traveler first.');
            }
            $application->update($data);

            return $this->requestList($trip, $request->user()->id);
        }, 3);
    }

    public function destroy(Request $request, Trip $trip, int $travelRequest)
    {
        $this->authorizeOwner($request, $trip);

        return DB::transaction(function () use ($request, $trip, $travelRequest) {
            $trip = Trip::whereKey($trip->id)->lockForUpdate()->firstOrFail();
            $trip->travelRequests()->findOrFail($travelRequest)->delete();

            return $this->requestList($trip, $request->user()->id);
        }, 3);
    }

    public function destroyMine(Request $request, Trip $trip)
    {
        abort_if($request->user()->is_blocked, 403);

        return DB::transaction(function () use ($request, $trip) {
            $trip = Trip::whereKey($trip->id)->lockForUpdate()->firstOrFail();
            $trip->travelRequests()->where('user_id', $request->user()->id)->delete();

            return new TripResource(Trip::with('user')->withRequestSummary($request->user()->id)->findOrFail($trip->id));
        }, 3);
    }

    private function authorizeOwner(Request $request, Trip $trip): void
    {
        abort_unless($trip->user_id === $request->user()->id && ! $request->user()->is_blocked, 403);
    }

    private function requestList(Trip $trip, int $userId): array
    {
        return [
            'trip' => new TripResource(Trip::with('user')->withRequestSummary($userId)->findOrFail($trip->id)),
            'data' => $trip->travelRequests()->with('user')->orderBy('id')->get()->map(fn ($application) => [
                'id' => $application->id,
                'status' => $application->status,
                'created_at' => $application->created_at->toISOString(),
                'traveler' => [
                    'id' => $application->user->id,
                    'name' => $application->user->name,
                    'profile_photo_url' => $application->user->profile_photo_url,
                ],
            ]),
        ];
    }
}
