<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProfilePhotoController extends Controller
{
    public function store(Request $request)
    {
        abort_if($request->user()->is_blocked, 403);
        $request->validate(['photo' => 'required|image|mimes:jpg,jpeg,png,webp|max:5120|dimensions:max_width=6000,max_height=6000']);
        $path = $request->file('photo')->store('profile-photos', 'public');
        abort_unless($path, 500, 'Could not save the image. Please try again.');
        $user = $request->user();
        $old = $user->profile_photo;
        try {
            $user->update(['profile_photo' => $path]);
        } catch (\Throwable $error) {
            Storage::disk('public')->delete($path);
            throw $error;
        }
        $this->deleteStoredPhoto($old);

        return response()->json(['user' => $user->fresh()->load('travelPreference')]);
    }

    public function destroy(Request $request)
    {
        $user = $request->user();
        $old = $user->profile_photo;
        $user->update(['profile_photo' => null]);
        $this->deleteStoredPhoto($old);

        return response()->json(['user' => $user->fresh()->load('travelPreference')]);
    }

    public function show(User $user)
    {
        abort_if(($user->is_blocked && request()->user('sanctum')?->role !== 'admin') || ! $user->profile_photo, 404);
        abort_unless(str_starts_with($user->profile_photo, 'profile-photos/')
            && Storage::disk('public')->exists($user->profile_photo), 404);

        return Storage::disk('public')->response($user->profile_photo, null, [
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function deleteStoredPhoto(?string $path): void
    {
        if ($path && str_starts_with($path, 'profile-photos/')) {
            Storage::disk('public')->delete($path);
        }
    }
}
