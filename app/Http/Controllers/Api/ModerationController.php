<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Report;
use App\Models\Trip;
use App\Models\User;
use App\Models\VerificationRequest;
use App\Notifications\TravelActivityNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ModerationController extends Controller
{
    public function report(Request $request)
    {
        $data = $request->validate([
            'target_type' => ['required', Rule::in(['user', 'trip'])],
            'target_id' => 'required|integer|min:1',
            'reason' => 'required|string|min:10|max:2000',
        ]);
        $target = $data['target_type'] === 'trip'
            ? Trip::visible()->findOrFail($data['target_id'])
            : User::discoverable()->findOrFail($data['target_id']);
        abort_if(($data['target_type'] === 'trip' ? $target->user_id : $target->id) === $request->user()->id, 422, 'You cannot report yourself or your own trip.');
        $report = Report::firstOrCreate([
            'reporter_id' => $request->user()->id, 'target_type' => $data['target_type'],
            'target_id' => $target->id, 'status' => 'pending',
        ], ['reason' => $data['reason']]);

        if ($report->wasRecentlyCreated) {
            User::where('role', 'admin')->where('is_blocked', false)->each(function ($admin) use ($report, $request) {
                $admin->notify(new TravelActivityNotification(
                    'report_received', 'New report',
                    $request->user()->name.' submitted a report about '.($report->target_type === 'trip' ? 'a trip' : 'a traveler').'.',
                    ['report_id' => $report->id, 'target_type' => $report->target_type, 'target_id' => $report->target_id, 'sender' => $request->user()->name],
                ));
            });
        }

        return response()->json(['message' => 'Report submitted for admin review.', 'data' => $report], 201);
    }

    public function verification(Request $request)
    {
        return response()->json(['data' => VerificationRequest::where('user_id', $request->user()->id)->first()]);
    }

    public function requestVerification(Request $request)
    {
        abort_unless($request->user()->role === 'user', 403);
        $data = $request->validate(['details' => 'required|string|min:20|max:2000']);

        return DB::transaction(function () use ($request, $data) {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            abort_if($user->verification_status === 'verified', 422, 'Your profile is already verified.');
            $application = VerificationRequest::where('user_id', $user->id)->first();
            abort_if($application?->status === 'pending', 409, 'Your request is already awaiting review.');
            $application = VerificationRequest::updateOrCreate(['user_id' => $user->id], [
                'details' => $data['details'], 'status' => 'pending', 'admin_notes' => null,
                'reviewed_by' => null, 'reviewed_at' => null,
            ]);
            $user->update(['verification_status' => 'pending']);
            User::where('role', 'admin')->where('is_blocked', false)->each(function ($admin) use ($application, $user) {
                $admin->notify(new TravelActivityNotification(
                    'verification_requested', 'New verification request',
                    $user->name.' requested profile verification.',
                    ['verification_id' => $application->id, 'sender' => $user->name],
                ));
            });

            return response()->json(['data' => $application], 201);
        });
    }
}
