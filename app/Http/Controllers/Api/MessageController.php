<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Notifications\ChatMessageNotification;
use App\Services\TripConversationAccess;
use App\Events\MessageSent;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;

class MessageController extends Controller
{
    /**
     * Find existing private conversation
     * or create a new one.
     */
    private function findOrCreateConversation(
        int $currentUserId,
        int $otherUserId
    ): Conversation {

        // Find existing private conversation
        $conversation = Conversation::where('type', 'private')
            ->whereHas('users', function ($query) use ($currentUserId) {
                $query->where('users.id', $currentUserId);
            })
            ->whereHas('users', function ($query) use ($otherUserId) {
                $query->where('users.id', $otherUserId);
            })
            ->first();

        // If conversation already exists
        if ($conversation) {
            return $conversation;
        }

        // Create new conversation
        $conversation = Conversation::create([
            'type' => 'private',
        ]);

        // Add both users to conversation_user table
        $conversation->users()->attach([
            $currentUserId,
            $otherUserId,
        ]);

        return $conversation;
    }

    /**
     * Send a message.
     */
    public function store(Request $request, Conversation $conversation, TripConversationAccess $access)
    {
        // Check whether current user is a member
        $isMember = $conversation->users()
            ->where('users.id', $request->user()->id)
            ->exists();

        if (!$isMember) {
            return response()->json([
                'success' => false,
                'message' => 'You are not a member of this conversation.',
            ], 403);
        }

        abort_unless($access->forConversation($conversation, $request->user()->id), 403, 'An accepted message request is required between all participants.');

        // Validate message
        $validated = $request->validate([
            'message' => [
                'required',
                'string',
                'max:5000',
            ],
        ]);

        // Save message
        $message = Message::createForSender(
            $conversation,
            $request->user(),
            $validated['message']
        );

        // Load sender information
        $message->load('sender:id,name');

        // Send notification to other participants
        try {
            $conversation->users()
                ->whereKeyNot($request->user()->id)
                ->get()
                ->each(function ($recipient) use ($message) {
                    $recipient->notify(
                        new ChatMessageNotification($message)
                    );
                });
        } catch (QueryException $exception) {
            report($exception);
        }

        // Update conversation timestamp
        $conversation->touch();

        // Broadcast real-time message
        broadcast(
            new MessageSent(
                $message->message,
                $conversation->id
            )
        )->toOthers();

        // Return response
        return response()->json([
            'success' => true,
            'message' => 'Message sent successfully.',
            'data' => $message,
        ], 201);
    }
}