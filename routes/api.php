<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\CommunicationController;
use App\Http\Controllers\Api\DiscoveryController;
use App\Http\Controllers\Api\ProfilePhotoController;
use App\Http\Controllers\Api\TravelerController;
use App\Http\Controllers\Api\TripController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\MessageController;

// Discovery / Public APIs
Route::get('/travelers', [TravelerController::class, 'index']);
Route::get('/travelers/{user}/photo', [ProfilePhotoController::class, 'show']);
Route::get('/travelers/{user}/reviews', [\App\Http\Controllers\Api\UserReviewController::class, 'index']);
Route::get('/travelers/{user}', [TravelerController::class, 'show']);

Route::get('/trips', [TripController::class, 'index']);
Route::get('/trips/{trip}', [TripController::class, 'show']);

Route::get('/stats', [DiscoveryController::class, 'stats']);
Route::get('/filter-options', [DiscoveryController::class, 'filters']);

// Authentication
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/admin/login', [AuthController::class, 'adminLogin'])->middleware('throttle:5,1');

Route::middleware(['auth:sanctum', \App\Http\Middleware\EnsureActiveUser::class])->group(function () {

    Route::post('/reports', [\App\Http\Controllers\Api\ModerationController::class, 'report'])->middleware('throttle:10,1');
    Route::get('/user/verification', [\App\Http\Controllers\Api\ModerationController::class, 'verification']);
    Route::post('/user/verification', [\App\Http\Controllers\Api\ModerationController::class, 'requestVerification'])->middleware('throttle:5,1');

    Route::prefix('admin')->middleware(\App\Http\Middleware\EnsureAdmin::class)->group(function () {
        Route::get('/overview', [\App\Http\Controllers\Api\AdminController::class, 'overview']);
        Route::get('/users', [\App\Http\Controllers\Api\AdminController::class, 'users']);
        Route::patch('/users/{user}', [\App\Http\Controllers\Api\AdminController::class, 'updateUser']);
        Route::get('/trips', [\App\Http\Controllers\Api\AdminController::class, 'trips']);
        Route::patch('/trips/{trip}', [\App\Http\Controllers\Api\AdminController::class, 'updateTrip']);
        Route::delete('/trips/{trip}', [\App\Http\Controllers\Api\AdminController::class, 'deleteTrip']);
        Route::get('/reports', [\App\Http\Controllers\Api\AdminController::class, 'reports']);
        Route::patch('/reports/{report}', [\App\Http\Controllers\Api\AdminController::class, 'updateReport']);
        Route::delete('/reports/{report}', [\App\Http\Controllers\Api\AdminController::class, 'deleteReport']);
        Route::get('/verifications', [\App\Http\Controllers\Api\AdminController::class, 'verifications']);
        Route::patch('/verifications/{verification}', [\App\Http\Controllers\Api\AdminController::class, 'updateVerification']);
        Route::delete('/verifications/{verification}', [\App\Http\Controllers\Api\AdminController::class, 'deleteVerification']);
    });

    // User / Profile
    Route::get('/user', [AuthController::class, 'user']);
    Route::put('/travelers/{user}/review', [\App\Http\Controllers\Api\UserReviewController::class, 'store']);
    Route::delete('/travelers/{user}/review', [\App\Http\Controllers\Api\UserReviewController::class, 'destroy']);
    Route::get('/user/matches', [\App\Http\Controllers\Api\MatchController::class, 'index']);
    Route::get('/user/trip-matches', [\App\Http\Controllers\Api\TripMatchController::class, 'index']);
    Route::patch('/user', [AuthController::class, 'updateProfile']);

    // Profile Photo
    Route::post('/user/photo', [ProfilePhotoController::class, 'store']);
    Route::delete('/user/photo', [ProfilePhotoController::class, 'destroy']);

    // Trips
    Route::get('/trips/{trip}/requests', [\App\Http\Controllers\Api\TravelRequestController::class, 'index']);
    Route::post('/trips/{trip}/requests', [\App\Http\Controllers\Api\TravelRequestController::class, 'store']);
    Route::delete('/trips/{trip}/requests/mine', [\App\Http\Controllers\Api\TravelRequestController::class, 'destroyMine']);
    Route::delete('/trips/{trip}/requests/{travelRequest}', [\App\Http\Controllers\Api\TravelRequestController::class, 'destroy'])->whereNumber('travelRequest');
    Route::patch('/trips/{trip}/requests/{travelRequest}', [\App\Http\Controllers\Api\TravelRequestController::class, 'update']);
    Route::get('/user/trips', [TripController::class, 'mine']);
    Route::post('/trips', [TripController::class, 'store']);
    Route::patch('/trips/{trip}', [TripController::class, 'update']);

    // Travel Preferences
    Route::put('/travel-preferences', [AuthController::class, 'savePreferences']);

    // Notifications
    Route::get('/notifications', [CommunicationController::class, 'notifications']);
    Route::post('/notifications/{id}/read', [CommunicationController::class, 'markNotificationRead']);

    // Presence
    Route::post('/presence', [CommunicationController::class, 'heartbeat']);

    // Users
    Route::get('/users', [CommunicationController::class, 'users']);

    // Chat / Conversations
    Route::get('/conversations', [ChatController::class, 'index']);
    Route::post('/conversations', [ChatController::class, 'store']);
    Route::get('/conversations/{conversation}', [ConversationController::class, 'show']);
    Route::delete('/conversations/{conversation}', [ConversationController::class, 'destroy']);
    Route::get('/conversations/{id}/messages', [ChatController::class, 'getMessages']);
    Route::post('/conversations/{conversation}/messages', [MessageController::class, 'store']);
    Route::post('/messages', [ChatController::class, 'sendMessage']);

    // Logout
    Route::post('/logout', [AuthController::class, 'logout']);
});
