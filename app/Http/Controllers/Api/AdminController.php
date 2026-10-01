<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Report;
use App\Notifications\TravelActivityNotification;
use App\Models\TravelRequest;
use App\Models\Trip;
use App\Models\User;
use App\Models\VerificationRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminController extends Controller
{
    public function overview()
    {
        $months = collect(range(5, 0))->map(function ($offset) {
            $start = now()->startOfMonth()->subMonths($offset);
            $end = $start->copy()->addMonth();

            return ['month' => $start->format('M Y'),
                'users' => User::where('role', 'user')->where('created_at', '>=', $start)->where('created_at', '<', $end)->count(),
                'trips' => Trip::where('created_at', '>=', $start)->where('created_at', '<', $end)->count()];
        });

        return response()->json(['data' => [
            'users' => User::where('role', 'user')->count(),
            'blocked_users' => User::where('is_blocked', true)->count(),
            'verified_users' => User::where('verification_status', 'verified')->count(),
            'trips' => Trip::count(), 'open_trips' => Trip::where('status', 'open')->count(),
            'pending_reports' => Report::where('status', 'pending')->count(),
            'pending_verifications' => VerificationRequest::where('status', 'pending')->count(),
            'travel_requests' => TravelRequest::count(),
            'accepted_requests' => TravelRequest::where('status', 'accepted')->count(),
            'months' => $months,
            'trip_statuses' => Trip::select('status')->selectRaw('COUNT(*) as total')->groupBy('status')->get(),
            'destinations' => Trip::select('destination')->selectRaw('COUNT(*) as total')->groupBy('destination')->orderByDesc('total')->limit(6)->get(),
            'recent_users' => User::where('role', 'user')->latest()->limit(5)->get(['id', 'name', 'created_at', 'verification_status']),
        ]]);
    }

    public function users(Request $request)
    {
        $data = $this->filters($request, ['active', 'blocked']);
        $query = User::query()->select(['id', 'name', 'email', 'role', 'is_blocked', 'verification_status', 'created_at']);
        if (! empty($data['search'])) {
            $query->where(fn ($q) => $q->where('name', 'like', '%'.$data['search'].'%')->orWhere('email', 'like', '%'.$data['search'].'%'));
        }
        if (! empty($data['status'])) {
            $query->where('is_blocked', $data['status'] === 'blocked');
        }

        return $query->latest()->paginate(15);
    }

    public function updateUser(Request $request, User $user)
    {
        $data = $request->validate(['is_blocked' => 'required|boolean']);
        abort_if($user->role === 'admin', 422, 'Admin accounts cannot be blocked here.');
        DB::transaction(function () use ($user, $data) {
            $user->update($data);
            if ($data['is_blocked']) {
                $user->tokens()->delete();
            }
        });

        return response()->json(['data' => $user->fresh()]);
    }

    public function trips(Request $request)
    {
        $data = $this->filters($request, ['draft', 'open', 'completed', 'cancelled', 'hidden']);
        $query = Trip::with('user:id,name,email')->withCount('travelRequests');
        if (! empty($data['search'])) {
            $query->where(fn ($q) => $q->where('title', 'like', '%'.$data['search'].'%')->orWhere('destination', 'like', '%'.$data['search'].'%'));
        }
        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        return $query->latest()->paginate(15);
    }

    public function updateTrip(Request $request, Trip $trip)
    {
        $data = $request->validate(['status' => ['required', Rule::in(['draft', 'open', 'completed', 'cancelled', 'hidden'])]]);
        DB::transaction(fn () => Trip::whereKey($trip->id)->lockForUpdate()->firstOrFail()->update($data));

        return response()->json(['data' => $trip->fresh()]);
    }

    public function deleteTrip(Trip $trip)
    {
        DB::transaction(fn () => Trip::whereKey($trip->id)->lockForUpdate()->firstOrFail()->delete());

        return response()->noContent();
    }

    public function reports(Request $request)
    {
        $data = $this->filters($request, ['pending', 'resolved', 'dismissed']);
        $type = $request->validate(['target_type' => ['nullable', 'in:trip,user']]);
        $query = Report::with('reporter:id,name,email');
        if (! empty($type['target_type'])) {
            $query->where('target_type', $type['target_type']);
        }
        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }
        if (! empty($data['search'])) {
            $query->where('reason', 'like', '%'.$data['search'].'%');
        }

        return $query->latest()->paginate(15);
    }

    public function updateReport(Request $request, Report $report)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['resolved', 'dismissed'])],
        ]);
        DB::transaction(function () use ($report, $data, $request) {
            $report = Report::whereKey($report->id)->lockForUpdate()->firstOrFail();
            abort_unless($report->status === 'pending', 409, 'This report has already been handled.');
            $previousState = null;
            if ($data['status'] === 'resolved' && $report->target_type === 'user') {
                $user = User::whereKey($report->target_id)->lockForUpdate()->firstOrFail();
                abort_if($user->role === 'admin', 422, 'Admin accounts cannot be banned from reports.');
                $previousState = ['is_blocked' => $user->is_blocked];
                $user->update(['is_blocked' => true]);
                $user->tokens()->delete();
            } elseif ($data['status'] === 'resolved' && $report->target_type === 'trip') {
                $trip = Trip::whereKey($report->target_id)->lockForUpdate()->firstOrFail();
                $previousState = ['status' => $trip->status];
                $trip->update(['status' => 'hidden']);
            }
            $report->update($data + [
                'moderation_previous_state' => $previousState,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);
            if ($data['status'] === 'resolved') {
                $recipient = $report->target_type === 'user' ? $user : $trip->user;
                $recipient?->notify(new TravelActivityNotification(
                    'report_accepted', 'Report accepted by admin',
                    $report->target_type === 'user'
                        ? 'An admin accepted a report about your account. Your account has been blocked.'
                        : 'An admin accepted a report about your trip "'.$trip->title.'". The trip has been hidden.',
                    ['report_id' => $report->id, 'target_type' => $report->target_type, 'target_id' => $report->target_id, 'sender' => 'Admin'],
                ));
            }
        });

        return response()->json(['data' => $report->fresh()]);
    }

    public function deleteReport(Report $report)
    {
        DB::transaction(function () use ($report) {
            $report = Report::whereKey($report->id)->lockForUpdate()->firstOrFail();
            abort_if($report->status === 'pending', 409, 'Handle this report before deleting it.');

            if ($report->status === 'resolved') {
                $remainingQuery = Report::where('target_type', $report->target_type)
                    ->where('target_id', $report->target_id)
                    ->where('status', 'resolved')
                    ->where('id', '!=', $report->id);
                $remaining = (clone $remainingQuery)->orderBy('reviewed_at')->orderBy('id')->lockForUpdate()->get();
                $hasEarlierAction = (clone $remainingQuery)
                    ->where(fn ($query) => $query->where('reviewed_at', '<', $report->reviewed_at)
                        ->orWhere(fn ($query) => $query->where('reviewed_at', $report->reviewed_at)->where('id', '<', $report->id)))
                    ->exists();

                if ($remaining->isNotEmpty()) {
                    if (! $hasEarlierAction && $report->moderation_previous_state) {
                        $remaining->first()->update(['moderation_previous_state' => $report->moderation_previous_state]);
                    }
                } elseif ($report->target_type === 'user') {
                    $user = User::whereKey($report->target_id)->lockForUpdate()->first();
                    if ($user && $user->is_blocked) {
                        $user->update(['is_blocked' => $report->moderation_previous_state['is_blocked'] ?? false]);
                    }
                } elseif ($report->target_type === 'trip') {
                    $trip = Trip::whereKey($report->target_id)->lockForUpdate()->first();
                    if ($trip && $trip->status === 'hidden') {
                        $trip->update(['status' => $report->moderation_previous_state['status'] ?? 'open']);
                    }
                }
            }

            $report->delete();
        });

        return response()->noContent();
    }

    public function verifications(Request $request)
    {
        $data = $this->filters($request, ['pending', 'approved', 'rejected']);
        $query = VerificationRequest::with('user:id,name,email,bio,verification_status,is_blocked');
        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }
        if (! empty($data['search'])) {
            $query->whereHas('user', fn ($q) => $q->where('name', 'like', '%'.$data['search'].'%')->orWhere('email', 'like', '%'.$data['search'].'%'));
        }

        return $query->latest('updated_at')->paginate(15);
    }

    public function updateVerification(Request $request, VerificationRequest $verification)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['approved', 'rejected'])],
            'admin_notes' => 'required|string|min:3|max:2000',
        ]);
        DB::transaction(function () use ($verification, $request, $data) {
            $user = User::whereKey($verification->user_id)->lockForUpdate()->firstOrFail();
            $verification = VerificationRequest::whereKey($verification->id)->lockForUpdate()->firstOrFail();
            abort_unless($verification->status === 'pending', 409, 'This request has already been reviewed.');
            abort_if($user->is_blocked && $data['status'] === 'approved', 422, 'Unblock this user before approving verification.');
            $verification->update($data + ['reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);
            $user->update(['verification_status' => $data['status'] === 'approved' ? 'verified' : 'unverified']);
            $user->notify(new TravelActivityNotification(
                'verification_'.$data['status'], 'Profile verification '.$data['status'],
                'Your verification request was '.$data['status'].'. '.$data['admin_notes'],
                ['verification_id' => $verification->id, 'sender' => 'Admin'],
            ));
        });

        return response()->json(['data' => $verification->fresh()]);
    }

    public function deleteVerification(VerificationRequest $verification)
    {
        DB::transaction(function () use ($verification) {
            $verification = VerificationRequest::whereKey($verification->id)->lockForUpdate()->firstOrFail();
            abort_if($verification->status === 'pending', 409, 'Handle this verification request before deleting it.');
            $user = User::whereKey($verification->user_id)->lockForUpdate()->firstOrFail();
            if ($verification->status === 'approved' && $user->verification_status === 'verified') {
                $user->update(['verification_status' => 'unverified']);
            }
            $user->notify(new TravelActivityNotification(
                'verification_removed', 'Verification request removed',
                $verification->status === 'approved'
                    ? 'An admin removed your profile verification. You can submit a new verification request.'
                    : 'An admin removed your verification request. You can submit a new request.',
                ['verification_id' => $verification->id, 'sender' => 'Admin'],
            ));
            $verification->delete();
        });

        return response()->noContent();
    }

    private function filters(Request $request, array $statuses): array
    {
        return $request->validate(['search' => 'nullable|string|max:100', 'status' => ['nullable', Rule::in($statuses)], 'page' => 'sometimes|integer|min:1']);
    }
}
