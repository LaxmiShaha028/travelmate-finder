<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\CommunicationController;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {

    Route::get('/user', [AuthController::class, 'user']);

    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/notifications', [CommunicationController::class, 'notifications']);
    Route::post('/notifications/{id}/read', [CommunicationController::class, 'markNotificationRead']);
    Route::post('/presence', [CommunicationController::class, 'heartbeat']);
    Route::get('/users', [CommunicationController::class, 'users']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/conversations', [ChatController::class, 'index']);
    Route::post('/conversations', [ChatController::class, 'store']);
    Route::get('/conversations/{id}/messages', [ChatController::class, 'getMessages']);
    Route::post('/messages', [ChatController::class, 'sendMessage']);
});