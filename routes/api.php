<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Api\Controllers\RoomController;

Route::middleware('guest')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->name('api.login');
    Route::post('register', [AuthController::class, 'register'])->name('api.register');
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('logout', [AuthController::class, 'logout'])->name('api.logout');
    Route::get('user', [AuthController::class, 'user'])->name('api.user');

    // ルーム参加
    Route::post('rooms/join', [RoomController::class, 'join'])->name('api.rooms.join');
    // ルーム退出
    Route::post('rooms/leave', [RoomController::class, 'leave'])->name('api.rooms.leave');
});

?>