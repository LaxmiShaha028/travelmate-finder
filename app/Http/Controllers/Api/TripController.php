<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DiscoveryRequest;
use App\Http\Resources\TripResource;
use App\Models\Trip;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TripController extends Controller
{
    public function index(DiscoveryRequest $request)
    {
        $f = $request->validated();
        $q = Trip::visible()->with('user');
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

        return new TripResource($trip->load('user'));
    }

    public function mine(Request $request)
    {
        $request->validate(['page' => 'sometimes|integer|min:1']);

        return TripResource::collection($request->user()->trips()->with('user')->byStartDate()->paginate(12));
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
        $trip->update($this->validateTrip($request, $trip));

        return new TripResource($trip->fresh()->load('user'));
    }

    private function validateTrip(Request $request, ?Trip $trip = null): array
    {
        $required = $trip ? 'sometimes' : 'required';
        $data = $request->validate([
            'title' => "$required|string|max:255",
            'destination' => "$required|string|max:255",
            'description' => 'nullable|string|max:5000',
            'start_date' => "$required|date_format:Y-m-d",
            'end_date' => "$required|date_format:Y-m-d",
            'budget' => "$required|numeric|min:0|max:99999999",
            'travel_style' => 'nullable|string|max:255',
            'max_travelers' => 'sometimes|integer|min:1|max:100',
            'status' => ['sometimes', Rule::in(['draft', 'open', 'completed', 'cancelled'])],
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
