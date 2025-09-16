<?php

use Illuminate\Support\Facades\Broadcast;

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
Broadcast::channel('presence-room-{roomId}', function ($user, $roomId) {
    // ユーザーがルームに参加しているかチェック
    $redis = \Illuminate\Support\Facades\Redis::connection();
    $userRooms = $redis->smembers("user_rooms:{$user->id}");
    
    return in_array($roomId, $userRooms);
});
