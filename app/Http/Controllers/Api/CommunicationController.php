<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class CommunicationController extends Controller
{
    public function heartbeat(Request $request)
    {
        Cache::put('travelmate:online:'.$request->user()->id, true, now()->addSeconds(45));

        return response()->json(['online' => true]);
    }

    public function notifications(Request $request)
    {
        $notifications = $request->user()->notifications()
            ->latest()
            ->limit(30)
            ->get(['id', 'type', 'data', 'read_at', 'created_at']);

        return response()->json([
            'notifications' => $notifications,
            'unread_count' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    public function markNotificationRead(Request $request, string $id)
    {
        $notification = $request->user()->notifications()->whereKey($id)->firstOrFail();
        $notification->markAsRead();

        return response()->json([
            'unread_count' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    public function users(Request $request)
    {
        $search = trim((string) $request->query('search', ''));

        $users = User::query()
            ->select(['id', 'name'])
            ->whereKeyNot($request->user()->id)
            ->when($search !== '', fn ($query) => $query->where('name', 'like', '%'.$search.'%'))
            ->orderBy('name')
            ->limit(20)
            ->get()
            ->each(fn (User $user) => $user->setAttribute(
                'is_online',
                Cache::has('travelmate:online:'.$user->id),
            ));

        return response()->json($users);
    }
}