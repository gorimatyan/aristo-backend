<?php

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Presenceチャンネルの認証
Broadcast::channel('room.{roomId}', function ($user, $roomId) {
    // ユーザーがルームに参加しているかチェック
    // $redis = Redis::connection();
    // $userRooms = $redis->smembers("user_rooms:{$user->id}");
    Log::info('user', ['user' => $user]);
    
    return $user;
});

Broadcast::channel('channel', function ($user) {
    return $user;
});