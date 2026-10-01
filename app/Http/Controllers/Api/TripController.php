<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DiscoveryRequest;
use App\Http\Resources\TripResource;
use App\Models\TravelRequest;
use App\Models\Trip;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TripController extends Controller
{
    public function index(DiscoveryRequest $request)
    {
        $f = $request->validated();
        $q = Trip::visible()->with('user')->withRequestSummary($request->user('sanctum')?->id);
        foreach (['destination', 'travel_style'] as $key) {
            if (! empty($f[$key])) {
                $q->where($key, $f[$key]);
            }
        }
        if (! empty($f['date'])) {
            $q->whereDate('start_date', '<=', $f['date'])->whereDate('end_date', '>=', $f['date']);
        }
        foreach (['min_budget' => ['budget', '>='], 'max_budget' => ['budget', '<='],
            'min_duration' => ['duration_days', '>='], 'max_duration' => ['duration_days', '<=']] as $key => [$column, $operator]) {
            if (isset($f[$key])) {
                $q->where($column, $operator, $f[$key]);
            }
        }

        return TripResource::collection($q->byStartDate()->paginate($f['per_page'] ?? 12)->withQueryString());
    }

    public function show(Request $request, Trip $trip)
    {
        $owner = $request->user('sanctum');
        abort_unless(($trip->status === 'open' && ! $trip->user->is_blocked && $trip->user->role === 'user')
            || ($owner && ! $owner->is_blocked && $owner->id === $trip->user_id), 404);

        return new TripResource(Trip::with('user')->withRequestSummary($owner?->id)->findOrFail($trip->id));
    }

    public function mine(Request $request)
    {
        $request->validate(['page' => 'sometimes|integer|min:1']);

        return TripResource::collection($request->user()->trips()->with('user')->withRequestSummary($request->user()->id)->byStartDate()->paginate(12));
    }

    public function history(Request $request)
    {
        $user = $request->user();
        $requestedTrips = TravelRequest::where('user_id', $user->id)
            ->with('trip.user')
            ->latest()
            ->get()
            ->map(fn (TravelRequest $travelRequest) => [
                'request_id' => $travelRequest->id,
                'request_status' => $travelRequest->status,
                'requested_at' => $travelRequest->created_at?->toISOString(),
                'trip' => $this->historyTrip($travelRequest->trip),
            ])
            ->values();
        $hostedTrips = $user->trips()
            ->where(fn ($query) => $query
                ->whereDate('end_date', '<', today())
                ->orWhereIn('status', ['completed', 'cancelled', 'hidden']))
            ->with('user')
            ->withCount(['travelRequests as accepted_count' => fn ($query) => $query->where('status', 'accepted')])
            ->orderByDesc('end_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Trip $trip) => $this->historyTrip($trip) + ['accepted_count' => $trip->accepted_count])
            ->values();

        return response()->json([
            'data' => [
                'requested' => $requestedTrips,
                'hosted' => $hostedTrips,
            ],
        ]);
    }

    private function historyTrip(Trip $trip): array
    {
        return [
            'id' => $trip->id,
            'title' => $trip->title,
            'destination' => $trip->destination,
            'start_date' => $trip->start_date->format('Y-m-d'),
            'end_date' => $trip->end_date->format('Y-m-d'),
            'duration_days' => $trip->duration_days,
            'budget' => $trip->budget,
            'travel_style' => $trip->travel_style,
            'status' => $trip->status,
            'organizer' => [
                'id' => $trip->user->id,
                'name' => $trip->user->name,
                'profile_photo_url' => $trip->user->profile_photo_url,
            ],
        ];
    }

    public function store(Request $request)
    {
        abort_if($request->user()->is_blocked, 403);
        $data = $this->validateTrip($request);
        $trip = $request->user()->trips()->create($data);

        return (new TripResource($trip->load('user')))->response()->setStatusCode(201);
    }

    public function update(Request $request, Trip $trip)
    {
        abort_unless($trip->user_id === $request->user()->id && ! $request->user()->is_blocked, 403);
        return DB::transaction(function () use ($request, $trip) {
            $trip = Trip::whereKey($trip->id)->lockForUpdate()->firstOrFail();
            $data = $this->validateTrip($request, $trip);
            if (isset($data['max_travelers']) && $data['max_travelers'] < $trip->travelRequests()->where('status', 'accepted')->count()) {
                throw ValidationException::withMessages(['max_travelers' => 'Remove accepted travelers before reducing the number of places.']);
            }
            if ($trip->status === 'hidden') {
                $data['status'] = 'hidden';
            }
            $trip->update($data);

            return new TripResource(Trip::with('user')->withRequestSummary($request->user()->id)->findOrFail($trip->id));
        }, 3);
    }

    private function validateTrip(Request $request, ?Trip $trip = null): array
    {
        $required = $trip ? 'sometimes' : 'required';
        $statuses = ['draft', 'open', 'completed', 'cancelled'];
        if ($trip?->status === 'hidden') {
            $statuses[] = 'hidden';
        }
        $data = $request->validate([
            'title' => "$required|string|max:255",
            'destination' => "$required|string|max:255",
            'description' => 'nullable|string|max:5000',
            'start_date' => "$required|date_format:Y-m-d",
            'end_date' => "$required|date_format:Y-m-d",
            'budget' => "$required|numeric|min:0|max:99999999",
            'travel_style' => 'nullable|string|max:255',
            'max_travelers' => 'sometimes|integer|min:1|max:100',
            'status' => ['sometimes', Rule::in($statuses)],
        ]);
        $start = Carbon::parse($data['start_date'] ?? $trip->start_date)->startOfDay();
        $end = Carbon::parse($data['end_date'] ?? $trip->end_date)->startOfDay();
        if ($end->lt($start) || $start->diffInDays($end) >= 365) {
            throw ValidationException::withMessages([
                'end_date' => 'The end date must be on or after the start date, with a duration of at most 365 days.',
            ]);
        }
        if ((! $trip || isset($data['start_date'])) && $start->lt(today())) {
            throw ValidationException::withMessages(['start_date' => 'Choose today or a future date.']);
        }
        $data['duration_days'] = (int) $start->diffInDays($end) + 1;

        return $data;
    }
}
