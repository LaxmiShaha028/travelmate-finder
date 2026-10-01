<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MessageRequest;
use App\Models\User;
use App\Notifications\TravelActivityNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MessageRequestController extends Controller
{
    public function index(Request $request)
    {
        return MessageRequest::with(['sender:id,name', 'recipient:id,name'])
            ->where(fn ($q) => $q->where('sender_id', $request->user()->id)->orWhere('recipient_id', $request->user()->id))
            ->latest('updated_at')->paginate(20);
    }

    public function store(Request $request, User $user)
    {
        abort_if($user->id === $request->user()->id || $user->is_blocked, 422, 'Choose another active user.');

        return DB::transaction(function () use ($request, $user) {
            $ids = collect([$user->id, $request->user()->id])->sort()->values();
            User::whereKey($ids)->orderBy('id')->lockForUpdate()->get();
            $existing = MessageRequest::whereIn('sender_id', $ids)->whereIn('recipient_id', $ids)->first();
            if ($existing) {
                return response()->json(['data' => $existing]);
            }
            $application = MessageRequest::create(['sender_id' => $request->user()->id, 'recipient_id' => $user->id, 'status' => 'pending']);
            $user->notify(new TravelActivityNotification('message_requested', 'New message request', $request->user()->name.' would like to message you.', ['sender' => $request->user()->name]));

            return response()->json(['data' => $application], 201);
        });
    }

    public function update(Request $request, MessageRequest $messageRequest)
    {
        abort_unless($messageRequest->recipient_id === $request->user()->id, 403);
        $data = $request->validate(['status' => 'required|in:accepted,rejected']);

        return DB::transaction(function () use ($messageRequest, $data, $request) {
            $application = MessageRequest::whereKey($messageRequest->id)->lockForUpdate()->firstOrFail();
            abort_unless($application->status === 'pending', 409, 'This request has already been handled.');
            $application->update($data);
            $application->sender->notify(new TravelActivityNotification('message_request_'.$data['status'], 'Message request '.$data['status'], $request->user()->name.' '.$data['status'].' your message request.', ['sender' => $request->user()->name]));

            return response()->json(['data' => $application]);
        });
    }
}
