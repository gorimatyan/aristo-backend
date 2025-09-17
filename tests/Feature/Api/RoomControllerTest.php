<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class RoomControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // テスト前にRedisをクリア
        Redis::flushall();
    }

    protected function tearDown(): void
    {
        // テスト後にRedisをクリア
        Redis::flushall();
        
        parent::tearDown();
    }

    /**
     * 正常なマッチングテスト（異なるサイド同士）
     */
    public function test_異なるサイド同士のマッチングテスト()
    {
        // ユーザー作成
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        // 1人目がnegativeサイドで参加
        $response1 = $this->actingAs($user1)->postJson('/api/rooms/join', [
            'topicId' => 1,
            'themeName' => 'education',
            'preferredSide' => 'negative'
        ]);

        $response1->assertStatus(201);
        $data1 = $response1->json('data');

        // レスポンスログ出力
        Log::info('【テスト1】1人目参加レスポンス', $data1);

        // 1人目のRedisデータ確認
        $redis = Redis::connection();
        $roomData1 = $redis->hgetall("room:{$data1['room_id']}");
        $userRooms1 = $redis->smembers("user_rooms:{$user1->id}");

        Log::info('【テスト1】1人目参加後のRedisデータ', [
            'room_data' => $roomData1,
            'user_rooms' => $userRooms1
        ]);

        // 1人目のアサーション
        $this->assertEquals('negative', $data1['side']);
        $this->assertFalse($data1['matched']);
        $this->assertEquals($data1['room_id'], $roomData1['id']);
        $this->assertEquals('1', $roomData1['topic_id']);
        $this->assertEquals('education', $roomData1['theme_name']);
        $this->assertEquals($user1->id, $roomData1['negative_user_id']);
        $this->assertEquals('', $roomData1['positive_user_id']);
        $this->assertEquals('waiting', $roomData1['status']);
        $this->assertContains($data1['room_id'], $userRooms1);

        // 2人目がpositiveサイドで参加（マッチング成立）
        $response2 = $this->actingAs($user2)->postJson('/api/rooms/join', [
            'topicId' => 1,
            'themeName' => 'education',
            'preferredSide' => 'positive'
        ]);

        $response2->assertStatus(201);
        $data2 = $response2->json('data');

        // レスポンスログ出力
        Log::info('【テスト1】2人目参加レスポンス', $data2);

        // 2人目のRedisデータ確認
        $roomData2 = $redis->hgetall("room:{$data2['room_id']}");
        $userRooms2 = $redis->smembers("user_rooms:{$user2->id}");

        Log::info('【テスト1】2人目参加後のRedisデータ', [
            'room_data' => $roomData2,
            'user_rooms' => $userRooms2
        ]);

        // 2人目のアサーション（マッチング成立）
        $this->assertEquals($data1['room_id'], $data2['room_id']); // 同じルームに参加
        $this->assertEquals('positive', $data2['side']);
        $this->assertTrue($data2['matched']);
        $this->assertEquals($user1->id, $roomData2['negative_user_id']);
        $this->assertEquals($user2->id, $roomData2['positive_user_id']);
        $this->assertEquals('matched', $roomData2['status']);
        $this->assertContains($data2['room_id'], $userRooms2);
    }

    /**
     * 同じサイド同士はマッチングしないテスト
     */
    public function test_同じサイド同士はマッチングしないテスト()
    {
        // ユーザー作成
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        // 1人目がpositiveサイドで参加
        $response1 = $this->actingAs($user1)->postJson('/api/rooms/join', [
            'topicId' => 1,
            'themeName' => 'education',
            'preferredSide' => 'positive'
        ]);

        $response1->assertStatus(201);
        $data1 = $response1->json('data');

        // レスポンスログ出力
        Log::info('【テスト2】1人目参加レスポンス', $data1);

        // 1人目のRedisデータ確認
        $redis = Redis::connection();
        $roomData1 = $redis->hgetall("room:{$data1['room_id']}");

        Log::info('【テスト2】1人目参加後のRedisデータ', [
            'room_data' => $roomData1
        ]);

        // 2人目も同じpositiveサイドで参加（新しいルーム作成）
        $response2 = $this->actingAs($user2)->postJson('/api/rooms/join', [
            'topicId' => 1,
            'themeName' => 'education',
            'preferredSide' => 'positive'
        ]);

        $response2->assertStatus(201);
        $data2 = $response2->json('data');

        // レスポンスログ出力
        Log::info('【テスト2】2人目参加レスポンス', $data2);

        // 2人目のRedisデータ確認
        $roomData2 = $redis->hgetall("room:{$data2['room_id']}");

        Log::info('【テスト2】2人目参加後のRedisデータ', [
            'room_data' => $roomData2
        ]);

        // アサーション（別々のルームに参加）
        $this->assertNotEquals($data1['room_id'], $data2['room_id']); // 異なるルーム
        $this->assertEquals('positive', $data1['side']);
        $this->assertEquals('positive', $data2['side']);
        $this->assertFalse($data1['matched']); // 両方ともマッチングなし
        $this->assertFalse($data2['matched']);

        // Redis確認（両方とも待機状態）
        $this->assertEquals('waiting', $roomData1['status']);
        $this->assertEquals('waiting', $roomData2['status']);
        $this->assertEquals($user1->id, $roomData1['positive_user_id']);
        $this->assertEquals('', $roomData1['negative_user_id']);
        $this->assertEquals($user2->id, $roomData2['positive_user_id']);
        $this->assertEquals('', $roomData2['negative_user_id']);
    }

    /**
     * preferredSideなしでのマッチングテスト
     */
    public function test_preferredSideなしでのマッチングテスト()
    {
        // ユーザー作成
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        // 1人目がpreferredSideなしで参加（デフォルトでpositiveになる）
        $response1 = $this->actingAs($user1)->postJson('/api/rooms/join', [
            'topicId' => 1,
            'themeName' => 'education'
        ]);

        $response1->assertStatus(201);
        $data1 = $response1->json('data');

        // レスポンスログ出力
        Log::info('【テスト3】1人目参加レスポンス（preferredSideなし）', $data1);

        // 2人目がnegativeサイドで参加
        $response2 = $this->actingAs($user2)->postJson('/api/rooms/join', [
            'topicId' => 1,
            'themeName' => 'education',
            'preferredSide' => 'negative'
        ]);

        $response2->assertStatus(201);
        $data2 = $response2->json('data');

        // レスポンスログ出力
        Log::info('【テスト3】2人目参加レスポンス', $data2);

        // Redisデータ確認
        $redis = Redis::connection();
        $roomData = $redis->hgetall("room:{$data2['room_id']}");

        Log::info('【テスト3】マッチング後のRedisデータ', [
            'room_data' => $roomData
        ]);

        // アサーション
        $this->assertEquals($data1['room_id'], $data2['room_id']); // 同じルーム
        $this->assertEquals('positive', $data1['side']); // デフォルトでpositive
        $this->assertEquals('negative', $data2['side']);
        $this->assertFalse($data1['matched']); // 1人目は待機中
        $this->assertTrue($data2['matched']); // 2人目でマッチング成立
        $this->assertEquals('matched', $roomData['status']);
    }

    /**
     * バリデーションエラーテスト
     */
    public function test_バリデーションエラーテスト()
    {
        $user = User::factory()->create();

        // topicIdなし
        $response = $this->actingAs($user)->postJson('/api/rooms/join', [
            'themeName' => 'education'
        ]);

        $response->assertStatus(422)
                ->assertJsonStructure([
                    'message',
                    'errors' => ['topicId']
                ]);

        Log::info('【テスト4】バリデーションエラーレスポンス', $response->json());

        // themeNameなし
        $response = $this->actingAs($user)->postJson('/api/rooms/join', [
            'topicId' => 1
        ]);

        $response->assertStatus(422)
                ->assertJsonStructure([
                    'message',
                    'errors' => ['themeName']
                ]);
    }

    /**
     * 既に参加済みエラーテスト
     */
    public function test_既に参加済みエラーテスト()
    {
        $user = User::factory()->create();

        // 1回目の参加
        $response1 = $this->actingAs($user)->postJson('/api/rooms/join', [
            'topicId' => 1,
            'themeName' => 'education'
        ]);

        $response1->assertStatus(201);

        Log::info('【テスト5】1回目参加レスポンス', $response1->json());

        // 2回目の参加（エラーになるはず）
        $response2 = $this->actingAs($user)->postJson('/api/rooms/join', [
            'topicId' => 1,
            'themeName' => 'education'
        ]);

        $response2->assertStatus(400)
                ->assertJson([
                    'message' => '既に参加済みです'
                ]);

        Log::info('【テスト5】2回目参加エラーレスポンス', $response2->json());
    }

    /**
     * 異なるトピック・テーマではマッチングしないテスト
     */
    public function test_異なるトピック・テーマではマッチングしないテスト()
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        // 1人目が参加
        $response1 = $this->actingAs($user1)->postJson('/api/rooms/join', [
            'topicId' => 1,
            'themeName' => 'education',
            'preferredSide' => 'positive'
        ]);

        $response1->assertStatus(201);
        $data1 = $response1->json('data');

        // 2人目が異なるトピックで参加
        $response2 = $this->actingAs($user2)->postJson('/api/rooms/join', [
            'topicId' => 2, // 異なるトピック
            'themeName' => 'education',
            'preferredSide' => 'negative'
        ]);

        $response2->assertStatus(201);
        $data2 = $response2->json('data');

        Log::info('【テスト6】異なるトピックテスト', [
            'user1_response' => $data1,
            'user2_response' => $data2
        ]);

        // 別々のルームに参加するはず
        $this->assertNotEquals($data1['room_id'], $data2['room_id']);
        $this->assertFalse($data1['matched']);
        $this->assertFalse($data2['matched']);
    }

    /**
     * Pusher通知データの詳細確認テスト
     */
    public function test_Pusher通知データの詳細確認テスト()
    {
        // ユーザー作成
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        Log::info('【Pusherテスト】開始', [
            'user1_id' => $user1->id,
            'user2_id' => $user2->id
        ]);

        // 1人目がnegativeサイドで参加
        $response1 = $this->actingAs($user1)->postJson('/api/rooms/join', [
            'topicId' => 1,
            'themeName' => 'education',
            'preferredSide' => 'negative'
        ]);

        $response1->assertStatus(201);
        $data1 = $response1->json('data');

        Log::info('【Pusherテスト】1人目参加完了', [
            'room_id' => $data1['room_id'],
            'side' => $data1['side'],
            'matched' => $data1['matched']
        ]);

        // 2人目がpositiveサイドで参加（マッチング成立）
        $response2 = $this->actingAs($user2)->postJson('/api/rooms/join', [
            'topicId' => 1,
            'themeName' => 'education',
            'preferredSide' => 'positive'
        ]);

        $response2->assertStatus(201);
        $data2 = $response2->json('data');

        Log::info('【Pusherテスト】2人目参加完了', [
            'room_id' => $data2['room_id'],
            'side' => $data2['side'],
            'matched' => $data2['matched']
        ]);

        // 同じルームに参加していることを確認
        $this->assertEquals($data1['room_id'], $data2['room_id']);
        $this->assertTrue($data2['matched']);

        // Redisデータを詳細確認
        $redis = Redis::connection();
        $roomData = $redis->hgetall("room:{$data2['room_id']}");

        Log::info('【Pusherテスト】最終Redisデータ', [
            'room_id' => $roomData['id'],
            'positive_user_id' => $roomData['positive_user_id'],
            'negative_user_id' => $roomData['negative_user_id'],
            'status' => $roomData['status'],
            'topic_id' => $roomData['topic_id'],
            'theme_name' => $roomData['theme_name']
        ]);

        // 期待されるPusher通知データ
        $expectedPusherData = [
            'event' => 'MatchingSuccess',
            'room_id' => $data2['room_id'],
            'positive_user' => [
                'id' => $user2->id,
                'name' => 'User ' . $user2->id
            ],
            'negative_user' => [
                'id' => $user1->id,
                'name' => 'User ' . $user1->id
            ],
            'topic_id' => '1',
            'theme_name' => 'education'
        ];

        Log::info('【Pusherテスト】期待される通知データ', $expectedPusherData);
        Log::info('【Pusherテスト】チャンネル名', [
            'channel' => "presence-room.{$data2['room_id']}"
        ]);

        // 両ユーザーが同じチャンネルに参加していることを確認
        $this->assertEquals($data1['channel'], $data2['channel']);
    }

    /**
     * ルーム退出テスト
     */
    public function test_leave_room_success()
    {
        $user = User::factory()->create();

        // まずルームに参加
        $joinResponse = $this->actingAs($user)->postJson('/api/rooms/join', [
            'topicId' => 1,
            'themeName' => 'education'
        ]);

        $joinResponse->assertStatus(201);
        $joinData = $joinResponse->json('data');

        Log::info('【退出テスト】参加レスポンス', $joinData);

        // 参加ルームを確認
        $redis = Redis::connection();
        $userRooms = $redis->smembers("user_rooms:{$user->id}");
        $roomData = $redis->hgetall("room:{$joinData['room_id']}");

        Log::info('【退出テスト】参加後のRedisデータ', [
            'user_rooms' => $userRooms,
            'room_data' => $roomData
        ]);

        $this->assertContains($joinData['room_id'], $userRooms);
        $this->assertEquals($user->id, $roomData['positive_user_id']);

        // ルームから退出
        $leaveResponse = $this->actingAs($user)->postJson('/api/rooms/leave');

        $leaveResponse->assertStatus(200);
        $leaveData = $leaveResponse->json();

        Log::info('【退出テスト】退出レスポンス', $leaveData);

        // 退出後のRedisデータを確認
        $userRoomsAfter = $redis->smembers("user_rooms:{$user->id}");
        $roomDataAfter = $redis->hgetall("room:{$joinData['room_id']}");

        Log::info('【退出テスト】退出後のRedisデータ', [
            'user_rooms' => $userRoomsAfter,
            'room_data' => $roomDataAfter
        ]);

        // アサーション
        $this->assertEquals('ルームから退出しました', $leaveData['message']);
        $this->assertEmpty($userRoomsAfter); // 参加ルームが空になる
        $this->assertEmpty($roomDataAfter); // ルームが削除される
    }

    /**
     * 参加中のルームがない場合のエラーテスト
     */
    public function test_leave_room_no_rooms_error()
    {
        $user = User::factory()->create();

        // ルームに参加せずに退出を試行
        $response = $this->actingAs($user)->postJson('/api/rooms/leave');

        $response->assertStatus(400)
                ->assertJson([
                    'message' => '参加中のルームがありません'
                ]);

        Log::info('【退出テスト】エラーレスポンス', $response->json());
    }

    /**
     * 認証なしでの退出エラーテスト
     */
    public function test_leave_room_unauthenticated_error()
    {
        // 認証なしで退出を試行
        $response = $this->postJson('/api/rooms/leave');

        $response->assertStatus(401);

        Log::info('【退出テスト】認証エラーレスポンス', $response->json());
    }
}
