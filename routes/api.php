<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DiscoveryController;
use App\Http\Controllers\Api\ProfilePhotoController;
use App\Http\Controllers\Api\TravelerController;
use App\Http\Controllers\Api\TripController;
use Illuminate\Support\Facades\Route;

Route::get('/travelers', [TravelerController::class, 'index']);
Route::get('/travelers/{user}/photo', [ProfilePhotoController::class, 'show']);
Route::get('/travelers/{user}', [TravelerController::class, 'show']);
Route::get('/trips', [TripController::class, 'index']);
Route::get('/trips/{trip}', [TripController::class, 'show']);
Route::get('/stats', [DiscoveryController::class, 'stats']);
Route::get('/filter-options', [DiscoveryController::class, 'filters']);

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {

    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/user/photo', [ProfilePhotoController::class, 'store']);
    Route::delete('/user/photo', [ProfilePhotoController::class, 'destroy']);
    Route::get('/user/trips', [TripController::class, 'mine']);
    Route::post('/trips', [TripController::class, 'store']);
    Route::patch('/trips/{trip}', [TripController::class, 'update']);
    Route::patch('/user', [AuthController::class, 'updateProfile']);
    Route::put('/travel-preferences', [AuthController::class, 'savePreferences']);

    Route::post('/logout', [AuthController::class, 'logout']);
});
