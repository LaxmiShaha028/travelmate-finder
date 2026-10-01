<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|min:8|confirmed',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => bcrypt($request->password),
            'role' => 'user',
            'is_blocked' => false,
            'verification_status' => 'unverified',
        ]);

        $token = $user->createToken('travelmate')->plainTextToken;

        return response()->json([
            'message' => 'Registration successful',
            'user' => $user,
            'token' => $token,
        ], 201);
    }

    public function login(Request $request)
    {
        return $this->authenticate($request);
    }

    public function adminLogin(Request $request)
    {
        return $this->authenticate($request, true);
    }

    private function authenticate(Request $request, bool $adminOnly = false)
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $credentials['email'])->first();
        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            return response()->json([
                'message' => 'Invalid email or password',
            ], 401);
        }

        abort_if($user->is_blocked, 403, 'Your account is blocked.');
        abort_if($adminOnly && $user->role !== 'admin', 403, 'This account does not have admin access.');
        $user->load('travelPreference');

        $token = $user->createToken('travelmate')->plainTextToken;

        return response()->json([
            'message' => 'Login successful',
            'user' => array_merge($user->toArray(), [
                'has_preferences' => $user->travelPreference !== null,
            ]),
            'token' => $token,
        ]);
    }

    public function user(Request $request)
    {
        return response()->json([
            'user' => $request->user()->load('travelPreference'),
        ]);
    }

    public function updateProfile(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'bio' => 'nullable|string|max:2000',
            'date_of_birth' => 'nullable|date|before_or_equal:today',
            'gender' => 'nullable|string|max:50',
        ]);
        $request->user()->update($data);

        return response()->json(['user' => $request->user()->fresh()->load('travelPreference')]);
    }

    public function savePreferences(Request $request)
    {
        $rules = [];
        foreach (['destination', 'date', 'budget', 'style', 'companions', 'interests'] as $key) {
            $rules[$key] = ['bail', 'required', 'string', 'max:255', new \App\Rules\PreferenceAnswer];
        }
        $data = $request->validate($rules + [
            'travel_start' => 'nullable|date_format:Y-m-d|required_with:travel_end',
            'travel_end' => 'nullable|date_format:Y-m-d|required_with:travel_start|after_or_equal:travel_start',
            'duration_days' => 'nullable|integer|min:1|max:365',
            'min_budget' => 'nullable|numeric|min:0|max:99999999',
            'max_budget' => ['nullable', 'numeric', 'min:0', 'max:99999999', ...($request->filled('min_budget') ? ['gte:min_budget'] : [])],
        ]);
        $answers = array_intersect_key($data, $rules);
        $structuredKeys = ['travel_start', 'travel_end', 'duration_days', 'min_budget', 'max_budget'];
        $structured = array_intersect_key($data, array_flip($structuredKeys)) + array_fill_keys($structuredKeys, null);
        $request->user()->travelPreference()->updateOrCreate([], $structured + [
            'answers' => $answers,
            'travel_style' => $answers['style'],
            'interests' => [$answers['interests']],
            'preferred_destinations' => [$answers['destination']],
        ]);

        return response()->json(['message' => 'Preferences saved']);
    }

    public function logout(Request $request)
    {
        $accessToken = $request->user()->currentAccessToken();
        if ($accessToken && method_exists($accessToken, 'delete')) {
            $accessToken->delete();
        }

        if (Auth::guard('web')->check()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json([
            'message' => 'Logout successful',
        ]);
    }
}
