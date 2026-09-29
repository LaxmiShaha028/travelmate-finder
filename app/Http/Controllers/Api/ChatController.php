<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Notifications\ChatMessageNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ChatController extends Controller
{
    // সব চ্যাট লিস্ট পাওয়া
    public function index(Request $request)
    {
        $conversations = $request->user()->conversations()
            ->with([
                'latestMessage.sender:id,name',
                'users:id,name',
            ])
            ->orderByDesc('conversations.updated_at')
            ->get();
        $conversations->each(fn (Conversation $conversation) => $this->attachPresence($conversation));

        return response()->json($conversations);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'type' => ['sometimes', Rule::in(['private', 'group'])],
            'name' => ['required_if:type,group', 'nullable', 'string', 'max:255'],
            'user_ids' => [
                'required',
                'array',
                'min:1',
                Rule::when($request->input('type', 'private') === 'private', ['size:1']),
            ],
            'user_ids.*' => [
                'required',
                'integer',
                'distinct',
                'exists:users,id',
                'not_in:'.$request->user()->id,
            ],
        ]);

        $user = $request->user();
        $type = $validated['type'] ?? 'private';
        $participantIds = array_map('intval', $validated['user_ids']);

        if ($type === 'private') {
            $conversation = Conversation::query()
                ->where('type', 'private')
                ->whereHas('users', fn ($query) => $query->whereKey($user->id))
                ->whereHas('users', fn ($query) => $query->whereKey($participantIds[0]))
                ->has('users', '=', 2)
                ->first();

            if ($conversation) {
                return response()->json($this->attachPresence($conversation->load([
                    'latestMessage.sender:id,name',
                    'users:id,name',
                ])));
            }
        }

        $conversation = DB::transaction(function () use ($validated, $type, $user, $participantIds) {
            $conversation = Conversation::create([
                'name' => $validated['name'] ?? null,
                'type' => $type,
            ]);
            $conversation->users()->attach([$user->id, ...$participantIds]);

            return $conversation;
        });

        return response()->json($this->attachPresence($conversation->load([
            'latestMessage.sender:id,name',
            'users:id,name',
        ])), 201);
    }

    // কোনো নির্দিষ্ট চ্যাটের মেসেজসমূহ পাওয়া
    public function getMessages(Request $request, $id)
    {
        $conversation = $request->user()->conversations()->findOrFail($id);
        $messages = $conversation->messages()
            ->with('sender:id,name')
            ->orderBy('id')
            ->get();

        return response()->json($messages);
    }

    // নতুন মেসেজ পাঠানো
    public function sendMessage(Request $request)
    {
        $validated = $request->validate([
            'conversation_id' => 'required|exists:conversations,id',
            'message' => 'required|string|max:10000',
        ]);

        $conversation = $request->user()->conversations()->findOrFail($validated['conversation_id']);
        $message = $conversation->messages()->create([
            'sender_id' => $request->user()->id,
            'message' => $validated['message'],
        ]);
        $conversation->users()
            ->whereKeyNot($request->user()->id)
            ->get()
            ->each(fn ($recipient) => $recipient->notify(new ChatMessageNotification($message)));
        $conversation->touch();

        return response()->json($message->load('sender:id,name'), 201);
    }

    private function attachPresence(Conversation $conversation): Conversation
    {
        $conversation->users->each(fn ($user) => $user->setAttribute(
            'is_online',
            Cache::has('travelmate:online:'.$user->id),
        ));

        return $conversation;
    }
}