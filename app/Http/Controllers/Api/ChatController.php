<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Notifications\ChatMessageNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ChatController extends Controller
{
    /**
     * সব conversation list
     */
    public function index(Request $request)
    {
        $conversations = $request->user()
            ->conversations()
            ->with('users:id,name')
            ->orderByDesc('conversations.updated_at')
            ->get();

        $conversations->each(function (Conversation $conversation) {

            // JSON messages থেকে latest message বের করা
            $messages = $conversation->messages ?? [];

            $latestMessage = !empty($messages)
                ? end($messages)
                : null;

            $conversation->setAttribute(
                'latest_message',
                $latestMessage
            );

            // Online status
            $this->attachPresence($conversation);
        });

        return response()->json($conversations);
    }


    /**
     * নতুন conversation তৈরি
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'type' => [
                'sometimes',
                Rule::in(['private', 'group']),
            ],

            'name' => [
                'required_if:type,group',
                'nullable',
                'string',
                'max:255',
            ],

            'user_ids' => [
                'required',
                'array',
                'min:1',

                Rule::when(
                    $request->input('type', 'private') === 'private',
                    ['size:1']
                ),
            ],

            'user_ids.*' => [
                'required',
                'integer',
                'distinct',
                'exists:users,id',
                'not_in:' . $request->user()->id,
            ],
        ]);


        $user = $request->user();

        $type = $validated['type'] ?? 'private';

        $participantIds = array_map(
            'intval',
            $validated['user_ids']
        );


        /*
        |--------------------------------------------------------------------------
        | Private conversation already exists কিনা check
        |--------------------------------------------------------------------------
        */

        if ($type === 'private') {

            $conversation = Conversation::query()
                ->where('type', 'private')

                ->whereHas(
                    'users',
                    fn ($query) =>
                    $query->whereKey($user->id)
                )

                ->whereHas(
                    'users',
                    fn ($query) =>
                    $query->whereKey($participantIds[0])
                )

                ->has('users', '=', 2)

                ->first();


            if ($conversation) {

                $conversation->load('users:id,name');

                $this->attachPresence($conversation);

                return response()->json($conversation);
            }
        }


        /*
        |--------------------------------------------------------------------------
        | নতুন conversation create
        |--------------------------------------------------------------------------
        */

        $conversation = DB::transaction(function () use (
            $validated,
            $type,
            $user,
            $participantIds
        ) {

            $conversation = Conversation::create([
                'name' => $validated['name'] ?? null,
                'type' => $type,

                // JSON column শুরুতে empty
                'messages' => [],
            ]);


            /*
            |--------------------------------------------------------------------------
            | Participants attach
            |--------------------------------------------------------------------------
            */

            $conversation->users()->attach([
                $user->id,
                ...$participantIds,
            ]);


            return $conversation;
        });


        $conversation->load('users:id,name');

        $this->attachPresence($conversation);

        return response()->json(
            $conversation,
            201
        );
    }


    /**
     * নির্দিষ্ট conversation-এর সব messages
     */
    public function getMessages(Request $request, $id)
    {
        /*
        |--------------------------------------------------------------------------
        | Current user conversation-এর member কিনা
        |--------------------------------------------------------------------------
        */

        $conversation = $request->user()
            ->conversations()
            ->findOrFail($id);


        /*
        |--------------------------------------------------------------------------
        | JSON column থেকে messages নেওয়া
        |--------------------------------------------------------------------------
        */

        $messages = $conversation->messages ?? [];


        return response()->json($messages);
    }


    /**
     * নতুন message পাঠানো
     */
    public function sendMessage(Request $request)
    {
        /*
        |--------------------------------------------------------------------------
        | Validation
        |--------------------------------------------------------------------------
        */

        $validated = $request->validate([
            'conversation_id' => [
                'required',
                'exists:conversations,id',
            ],

            'message' => [
                'required',
                'string',
                'max:10000',
            ],
        ]);


        /*
        |--------------------------------------------------------------------------
        | Conversation খুঁজে বের করা
        |--------------------------------------------------------------------------
        */

        $conversation = $request->user()
            ->conversations()
            ->findOrFail(
                $validated['conversation_id']
            );


        /*
        |--------------------------------------------------------------------------
        | Unique message ID
        |--------------------------------------------------------------------------
        */

        $messageId = (string) Str::uuid();


        /*
        |--------------------------------------------------------------------------
        | New message object
        |--------------------------------------------------------------------------
        */

        $newMessage = [
            'id' => $messageId,

            'sender_id' => $request->user()->id,

            'sender_name' => $request->user()->name,

            'message' => $validated['message'],

            'created_at' => now()->toDateTimeString(),
        ];


        /*
        |--------------------------------------------------------------------------
        | Existing JSON messages
        |--------------------------------------------------------------------------
        */

        $messages = $conversation->messages ?? [];


        /*
        |--------------------------------------------------------------------------
        | নতুন message append
        |--------------------------------------------------------------------------
        */

        $messages[] = $newMessage;


        /*
        |--------------------------------------------------------------------------
        | JSON column update
        |--------------------------------------------------------------------------
        */

        $conversation->messages = $messages;

        $conversation->save();


        /*
        |--------------------------------------------------------------------------
        | অন্য participants-কে notification
        |--------------------------------------------------------------------------
        */

        $conversation
            ->users()
            ->whereKeyNot($request->user()->id)
            ->get()
            ->each(function ($recipient) use (
                $conversation,
                $request,
                $validated,
                $messageId
            ) {

                try {

                    $recipient->notify(
                        new ChatMessageNotification(
                            conversationId: $conversation->id,
                            senderId: $request->user()->id,
                            senderName: $request->user()->name,
                            message: $validated['message'],
                            messageId: $messageId
                        )
                    );

                } catch (\Throwable $exception) {

                    report($exception);
                }
            });


        /*
        |--------------------------------------------------------------------------
        | Conversation updated time
        |--------------------------------------------------------------------------
        */

        $conversation->touch();


        /*
        |--------------------------------------------------------------------------
        | নতুন message return
        |--------------------------------------------------------------------------
        */

        return response()->json(
            $newMessage,
            201
        );
    }


    /**
     * Online status attach
     */
    private function attachPresence(
        Conversation $conversation
    ): Conversation {

        $conversation->users->each(
            fn ($user) => $user->setAttribute(
                'is_online',
                Cache::has(
                    'travelmate:online:' . $user->id
                )
            )
        );


        return $conversation;
    }
}