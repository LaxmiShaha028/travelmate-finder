<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Trip;
use App\Services\TripConversationAccess;
use Illuminate\Http\Request;

class TripChatController extends Controller
{
    public function store(Request $request, Trip $trip, TripConversationAccess $access)
    {
        abort_unless($trip->max_travelers > 1 && ! $trip->user->is_blocked && ! in_array($trip->status, ['hidden', 'cancelled'], true), 403, 'Group chat is unavailable for this trip.');
        abort_unless(in_array($request->user()->id, $access->tripMembers($trip), true), 403, 'Only the owner and accepted travelers can join this group.');
        $conversation = $access->syncTrip($trip, true);

        return response()->json($conversation->load('users:id,name'));
    }
}
