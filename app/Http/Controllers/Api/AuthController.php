<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\User;

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
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        if (!Auth::attempt($credentials)) {
            return response()->json([
                'message' => 'Invalid email or password'
            ], 401);
        }

        $user = Auth::user();

        $token = $user->createToken('travelmate')->plainTextToken;

        return response()->json([
            'message' => 'Login successful',
            'user' => $user,
            'token' => $token,
        ]);
    }

    public function user(Request $request)
    {
        return response()->json([
            'user' => $request->user()->load('travelPreference')
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
            $rules[$key] = 'required|string|max:255';
        }
        $answers = $request->validate($rules);
        $request->user()->travelPreference()->updateOrCreate([], [
            'answers' => $answers,
            'travel_style' => $answers['style'],
            'interests' => [$answers['interests']],
            'preferred_destinations' => [$answers['destination']],
        ]);
        return response()->json(['message' => 'Preferences saved']);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logout successful'
        ]);
    }
}
