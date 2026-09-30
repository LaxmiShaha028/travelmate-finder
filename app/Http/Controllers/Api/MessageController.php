<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Notifications\ChatMessageNotification;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    /**
     * Send a message.
     */
    public function store(Request $request, Conversation $conversation)
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

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:5000'],
        ]);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $request->user()->id,
            'message' => $validated['message'],
        ]);

        $message->load('sender:id,name');
        $conversation->users()
            ->whereKeyNot($request->user()->id)
            ->get()
            ->each(fn ($recipient) => $recipient->notify(new ChatMessageNotification($message)));
        $conversation->touch();

        return response()->json([
            'success' => true,
            'message' => 'Message sent successfully.',
            'data' => $message,
        ], 201);
    }
}