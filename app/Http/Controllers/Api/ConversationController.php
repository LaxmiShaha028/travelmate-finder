<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ConversationController extends Controller
{
    /**
     * Get current user's conversations.
     */
    public function index(Request $request)
    {
        $conversations = $request->user()
            ->conversations()
            ->with([
                'users:id,name',
                'latestMessage.sender:id,name',
            ])
            ->orderByDesc('conversations.updated_at')
            ->get()
            ->each(fn (Conversation $conversation) => $this->attachPresence($conversation));

        return response()->json([
            'success' => true,
            'data' => $conversations,
        ]);
    }

    /**
     * Create a private conversation.
     */
    public function store(Request $request)
    {
        $rawParticipantIds = $request->input('user_ids', $request->input('traveler_ids'));
        if (is_string($rawParticipantIds)) {
            $request->merge([
                'user_ids' => array_values(array_filter(
                    array_map('trim', explode(',', $rawParticipantIds)),
                    static fn (string $id): bool => $id !== '',
                )),
            ]);
        } elseif (is_array($rawParticipantIds) && !$request->has('user_ids')) {
            $request->merge(['user_ids' => $rawParticipantIds]);
        }

        $validated = $request->validate([
            'type' => ['sometimes', Rule::in(['private', 'group'])],
            'name' => ['required_if:type,group', 'nullable', 'string', 'max:255'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'user_ids' => ['nullable', 'array', 'min:1', 'max:50'],
            'user_ids.*' => ['required', 'integer', 'distinct', 'exists:users,id'],
        ]);

        $currentUser = $request->user();
        $type = $validated['type'] ?? 'private';
        $participantIds = array_map('intval', $validated['user_ids'] ?? array_filter([
            $validated['user_id'] ?? null,
        ]));

        if (count($participantIds) === 0 || ($type === 'private' && count($participantIds) !== 1)) {
            return response()->json([
                'success' => false,
                'message' => 'Choose one traveler for a private chat or at least one for a group.',
            ], 422);
        }

        if (in_array((int) $currentUser->id, $participantIds, true)) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot start a conversation with yourself.',
            ], 422);
        }

        if ($type === 'private') {
            $otherUserId = $participantIds[0];
            $conversation = Conversation::query()
                ->where('type', 'private')
                ->whereHas('users', fn ($query) => $query->whereKey($currentUser->id))
                ->whereHas('users', fn ($query) => $query->whereKey($otherUserId))
                ->has('users', '=', 2)
                ->first();

            if ($conversation) {
                $conversation->load(['users:id,name', 'latestMessage.sender:id,name']);

                return response()->json([
                    'success' => true,
                    'data' => $this->attachPresence($conversation),
                ]);
            }
        }

        $conversation = DB::transaction(function () use ($validated, $type, $currentUser, $participantIds) {
            $conversation = Conversation::create([
                'type' => $type,
                'name' => $type === 'group' ? $validated['name'] : null,
            ]);
            $conversation->users()->attach([$currentUser->id, ...$participantIds]);

            return $conversation;
        });

        $conversation->load(['users:id,name', 'latestMessage.sender:id,name']);

        return response()->json([
            'success' => true,
            'message' => 'Conversation created successfully.',
            'data' => $this->attachPresence($conversation),
        ], 201);
    }

    /**
     * Show one conversation with messages.
     */
    public function show(Request $request, Conversation $conversation)
    {
        $isMember = $conversation->users()
            ->where('users.id', $request->user()->id)
            ->exists();

        if (!$isMember) {
            return response()->json([
                'success' => false,
                'message' => 'You are not a member of this conversation.',
            ], 403);
        }

        $conversation->load([
            'users:id,name',
            'messages.sender:id,name',
        ]);
        $this->attachPresence($conversation);

        return response()->json([
            'success' => true,
            'data' => $conversation,
        ]);
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