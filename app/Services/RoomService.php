<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

/**
 * ルーム管理サービス
 * 
 * このサービスは以下の機能を提供します：
 * - ルームの作成・参加・退出
 * - Redisを使用した高速なルーム検索と管理
 * - Pusherを使用したマッチング成立通知
 * 
 * Redisデータ構造：
 * - room:{room_id} (Hash): ルーム情報
 *   - id: ルームID (UUID)
 *   - topic_id: トピックID
 *   - theme_name: テーマ名
 *   - positive_user_id: ポジティブサイドのユーザーID
 *   - negative_user_id: ネガティブサイドのユーザーID
 *   - status: ルーム状態 (waiting/matched/completed)
 *   - created_at: 作成日時
 *   - updated_at: 更新日時
 * 
 * - user_rooms:{user_id} (Set): ユーザーが参加中のルームID一覧
 *   - メンバー: room_id1, room_id2, ...
 * 
 * Pusher通知：
 * - チャンネル: presence-room-{room_id}
 * - イベント: matching-success
 * - データ: ルーム情報、参加ユーザー情報、トピック情報
 */
class RoomService
{
    /**
     * ルームに参加する
     * 
     * 既存のルームを検索し、条件に合うルームがあれば参加する。
     * なければ新しいルームを作成する。
     * 
     * @param User $user 参加するユーザー
     * @param int $topicId トピックID
     * @param string $themeName テーマ名
     * @param string|null $preferredSide 希望するサイド (positive/negative)
     * @return array 参加結果
     *   - room_id: ルームID
     *   - side: 割り当てられたサイド (positive/negative)
     *   - matched: マッチング成立フラグ
     *   - channel: Pusherチャンネル名
     * @throws \Exception 既に参加済み、ルームが満員、希望サイドが利用不可の場合
     */
    public function joinRoom(User $user, int $topicId, string $themeName, ?string $preferredSide = null)
    {
        // 既に参加済みかチェック
        if ($this->isUserAlreadyInRoom($user->id)) {
            throw new \Exception('既に参加済みです', 400);
        }

        // 既存のルームを検索
        $existingRoom = $this->findAvailableRoom($topicId, $themeName, $preferredSide);
        
        if ($existingRoom) {
            // 既存のルームに参加
            return $this->joinExistingRoom($existingRoom, $user, $preferredSide);
        } else {
            // 新しいルームを作成
            return $this->createNewRoom($user, $topicId, $themeName, $preferredSide);
        }
    }

    /**
     * ユーザーが既にルームに参加しているかチェック
     * 
     * Redisのuser_rooms:{user_id}セットを確認する。
     * Redis接続エラーの場合はfalseを返す（参加可能として扱う）。
     * 
     * @param int $userId ユーザーID
     * @return bool 参加済みの場合true
     */
    private function isUserAlreadyInRoom(int $userId)
    {
        try {
            $redis = Redis::connection();
            $userRooms = $redis->smembers("user_rooms:{$userId}");
            return !empty($userRooms);
        } catch (\Exception $e) {
            Log::warning('Redis接続エラー: ユーザーの参加状況をチェックできません', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * 参加可能な既存ルームを検索
     * 
     * Redisのroom:*キーを検索し、以下の条件に合うルームを探す：
     * - topic_idが一致
     * - theme_nameが一致
     * - statusが'waiting'
     * - 希望サイドが利用可能（指定されている場合）
     * 
     * @param int $topicId トピックID
     * @param string $themeName テーマ名
     * @param string|null $preferredSide 希望するサイド
     * @return array|null 見つかったルームデータ、なければnull
     */
    private function findAvailableRoom(int $topicId, string $themeName, ?string $preferredSide = null)
    {
        try {
            $redis = Redis::connection();
            $roomKeys = $redis->keys("room:*");
            
            foreach ($roomKeys as $key) {
                $roomData = $redis->hgetall($key);
                
                if (empty($roomData)) {
                    continue;
                }
                
                if ($roomData['topic_id'] == $topicId && 
                    $roomData['theme_name'] === $themeName && 
                    $roomData['status'] === 'waiting') {
                    
                    // 利用可能なサイドを確認
                    $availableSide = null;
                    if (empty($roomData['positive_user_id'])) {
                        $availableSide = 'positive';
                    } elseif (empty($roomData['negative_user_id'])) {
                        $availableSide = 'negative';
                    }
                    
                    // 希望するサイドが利用可能かチェック
                    if ($preferredSide && $availableSide !== $preferredSide) {
                        continue;
                    }
                    
                    return $roomData;
                }
            }
        } catch (\Exception $e) {
            Log::warning('Redis接続エラー: 既存ルームの検索ができません', [
                'topic_id' => $topicId,
                'theme_name' => $themeName,
                'error' => $e->getMessage()
            ]);
        }
        
        return null;
    }

    /**
     * 既存ルームに参加
     * 
     * ユーザーを既存のルームに追加し、マッチング成立の場合は通知を送信する。
     * Redisのみでルーム管理を行う。
     * 
     * @param array $roomData 参加するルームのデータ
     * @param User $user 参加するユーザー
     * @param string|null $preferredSide 希望するサイド
     * @return array 参加結果
     *   - room_id: ルームID
     *   - side: 割り当てられたサイド
     *   - matched: true（マッチング成立）
     *   - channel: Pusherチャンネル名
     * @throws \Exception ルームが満員、希望サイドが利用不可の場合
     */
    private function joinExistingRoom(array $roomData, User $user, ?string $preferredSide = null)
    {
        $roomId = $roomData['id'];
        $availableSide = null;
        
        // 利用可能なサイドを確認
        if (empty($roomData['positive_user_id'])) {
            $availableSide = 'positive';
        } elseif (empty($roomData['negative_user_id'])) {
            $availableSide = 'negative';
        }
        
        if (!$availableSide) {
            throw new \Exception('ルームが満員です', 400);
        }

        // 希望するサイドと利用可能なサイドが一致しない場合
        if ($preferredSide && $availableSide !== $preferredSide) {
            throw new \Exception('希望するサイドが利用できません', 400);
        }

        // Redisでルームを更新
        $redis = Redis::connection();
        $sideField = $availableSide === 'positive' ? 'positive_user_id' : 'negative_user_id';
        
        $redis->hset("room:{$roomId}", $sideField, $user->id);
        $redis->hset("room:{$roomId}", 'status', 'matched');
        $redis->sadd("user_rooms:{$user->id}", $roomId);

        // 更新されたルームデータを取得
        $updatedRoomData = $redis->hgetall("room:{$roomId}");

        // マッチング成立の通知を送信
        $this->broadcastMatchingSuccess($updatedRoomData, $user);

        Log::info('ユーザーが既存ルームに参加', [
            'user_id' => $user->id,
            'room_id' => $roomId,
            'side' => $availableSide
        ]);

        return [
            'room_id' => $roomId,
            'side' => $availableSide,
            'matched' => true,
            'channel' => "presence-room-{$roomId}"
        ];
    }

    /**
     * 新しいルームを作成
     * 
     * 新しいルームを作成し、ユーザーを参加させる。
     * Redisのみでルーム管理を行う。
     * 
     * @param User $user 参加するユーザー
     * @param int $topicId トピックID
     * @param string $themeName テーマ名
     * @param string|null $preferredSide 希望するサイド（未指定の場合はpositive）
     * @return array 参加結果
     *   - room_id: ルームID
     *   - side: 割り当てられたサイド
     *   - matched: false（待機中）
     *   - channel: Pusherチャンネル名
     */
    private function createNewRoom(User $user, int $topicId, string $themeName, ?string $preferredSide = null)
    {
        // 新しいルームIDを生成
        $roomId = (string) \Illuminate\Support\Str::uuid();
        
        // 希望するサイドまたはデフォルトでpositiveに設定
        $side = $preferredSide ?: 'positive';
        $sideField = $side === 'positive' ? 'positive_user_id' : 'negative_user_id';
        
        // Redisにルームデータを保存
        $redis = Redis::connection();
        $roomData = [
            'id' => $roomId,
            'topic_id' => $topicId,
            'theme_name' => $themeName,
            'positive_user_id' => $side === 'positive' ? $user->id : '',
            'negative_user_id' => $side === 'negative' ? $user->id : '',
            'status' => 'waiting',
            'created_at' => now()->toISOString(),
            'updated_at' => now()->toISOString()
        ];
        
        $redis->hmset("room:{$roomId}", $roomData);
        $redis->sadd("user_rooms:{$user->id}", $roomId);

        Log::info('新しいルームを作成', [
            'user_id' => $user->id,
            'room_id' => $roomId,
            'side' => $side
        ]);

        return [
            'room_id' => $roomId,
            'side' => $side,
            'matched' => false,
            'channel' => "presence-room-{$roomId}"
        ];
    }

    /**
     * マッチング成立通知を送信
     * 
     * Pusherを使用してpresence-room-{room_id}チャンネルに
     * 'matching-success'イベントを送信する。
     * 
     * 送信データ：
     * - event: 'matching-success'
     * - room_id: ルームID
     * - positive_user: ポジティブサイドユーザー情報
     * - negative_user: ネガティブサイドユーザー情報
     * - topic_id: トピックID
     * - theme_name: テーマ名
     * 
     * @param array $roomData ルームデータ
     * @param User $currentUser 現在参加したユーザー
     */
    private function broadcastMatchingSuccess(array $roomData, User $currentUser)
    {
        // Pusherの設定を確認
        if (!config('broadcasting.connections.pusher.key')) {
            Log::warning('Pusherの設定がありません。マッチング通知をスキップします。');
            return;
        }

        try {
            $pusher = new \Pusher\Pusher(
                config('broadcasting.connections.pusher.key'),
                config('broadcasting.connections.pusher.secret'),
                config('broadcasting.connections.pusher.app_id'),
                [
                    'cluster' => config('broadcasting.connections.pusher.options.cluster'),
                    'useTLS' => true
                ]
            );
            
            // ユーザー情報を取得（簡略化のため、IDのみ送信）
            $positiveUser = null;
            $negativeUser = null;
            
            if (!empty($roomData['positive_user_id'])) {
                $positiveUser = [
                    'id' => $roomData['positive_user_id'],
                    'name' => 'User ' . $roomData['positive_user_id'] // 簡略化
                ];
            }
            
            if (!empty($roomData['negative_user_id'])) {
                $negativeUser = [
                    'id' => $roomData['negative_user_id'],
                    'name' => 'User ' . $roomData['negative_user_id'] // 簡略化
                ];
            }
            
            $data = [
                'event' => 'matching-success',
                'room_id' => $roomData['id'],
                'positive_user' => $positiveUser,
                'negative_user' => $negativeUser,
                'topic_id' => $roomData['topic_id'],
                'theme_name' => $roomData['theme_name']
            ];

            $channel = "presence-room-{$roomData['id']}";

            $pusher->trigger($channel, 'matching-success', $data);
            
            Log::info('マッチング成立通知を送信', [
                'room_id' => $roomData['id'],
                'channel' => $channel
            ]);
        } catch (\Exception $e) {
            Log::error('Pusher通知送信エラー', [
                'room_id' => $roomData['id'],
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * ルームから退出
     * 
     * ユーザーが参加中のすべてのルームから退出する。
     * ルームが空になった場合は削除し、そうでなければ待機状態に戻す。
     * Redisのみでルーム管理を行う。
     * 
     * @param User $user 退出するユーザー
     * @return array 退出結果
     *   - message: 退出メッセージ
     * @throws \Exception 参加中のルームがない場合
     */
    public function leaveRoom(User $user)
    {
        $redis = Redis::connection();
        $userRooms = $redis->smembers("user_rooms:{$user->id}");
        
        if (empty($userRooms)) {
            throw new \Exception('参加中のルームがありません', 400);
        }

        foreach ($userRooms as $roomId) {
            $roomData = $redis->hgetall("room:{$roomId}");
            
            if (!empty($roomData)) {
                // ユーザーをルームから削除
                if ($roomData['positive_user_id'] == $user->id) {
                    $redis->hset("room:{$roomId}", 'positive_user_id', '');
                } elseif ($roomData['negative_user_id'] == $user->id) {
                    $redis->hset("room:{$roomId}", 'negative_user_id', '');
                }
                
                // 更新されたルームデータを取得
                $updatedRoomData = $redis->hgetall("room:{$roomId}");
                
                // ルームが空になった場合は削除、そうでなければ待機状態に戻す
                if (empty($updatedRoomData['positive_user_id']) && empty($updatedRoomData['negative_user_id'])) {
                    $redis->del("room:{$roomId}");
                } else {
                    $redis->hset("room:{$roomId}", 'status', 'waiting');
                }
                
                // ユーザーの参加ルーム一覧から削除
                $redis->srem("user_rooms:{$user->id}", $roomId);
            }
        }

        Log::info('ユーザーがルームから退出', [
            'user_id' => $user->id,
            'rooms_left' => $userRooms
        ]);

        return ['message' => 'ルームから退出しました'];
    }
}
