# ルーム退出機能 (Room Leaving System)

## 概要

ユーザーが参加中のルームから退出する機能。ユーザーが参加しているすべてのルームから一括で退出し、ルームの状態を適切に管理する。

## 退出仕様

### 基本フロー

1. **ユーザーが退出リクエスト** → 参加中のルーム一覧を取得
2. **各ルームからユーザーを削除** → サイド情報をクリア
3. **ルーム状態を更新** → 空の場合は削除、そうでなければ待機状態に戻す
4. **ユーザーの参加ルーム一覧を更新** → 退出したルームを削除

### 退出条件

- **認証済みユーザー**: Laravel Sanctumトークン必須
- **参加中のルーム**: 少なくとも1つのルームに参加している必要がある
- **一括退出**: ユーザーが参加しているすべてのルームから同時に退出

## 処理フロー詳細

### 1. 正常な退出パターン

**シナリオ**: 参加中のユーザーが退出

```
リクエスト: POST /api/rooms/leave
Headers: Authorization: Bearer {sanctum_token}

処理:
1. ユーザーの参加ルーム一覧を取得
2. 各ルームからユーザーを削除
3. ルーム状態を更新（空なら削除、そうでなければwaiting）
4. ユーザーの参加ルーム一覧を更新

レスポンス:
{
  "message": "ルームから退出しました"
}

Redisデータ変更:
- user_rooms:{user_id} から room_id を削除
- room:{room_id} の positive_user_id または negative_user_id をクリア
- ルームが空の場合: room:{room_id} を削除
- ルームに残りがいる場合: status を 'waiting' に変更
```

### 2. ルーム状態の変化

#### **ルームが空になる場合**
```
退出前:
room:uuid-123 = {
  positive_user_id: "1",
  negative_user_id: "",
  status: "waiting"
}

退出後:
room:uuid-123 = 削除される
```

#### **ルームに他のユーザーが残る場合**
```
退出前:
room:uuid-123 = {
  positive_user_id: "1",
  negative_user_id: "2", 
  status: "matched"
}

退出後:
room:uuid-123 = {
  positive_user_id: "",
  negative_user_id: "2",
  status: "waiting"  ← 待機状態に戻る
}
```

### 3. エラーパターン

**シナリオ**: 参加中のルームがない

```
リクエスト: POST /api/rooms/leave

レスポンス:
{
  "message": "参加中のルームがありません"
}

ステータス: 400 Bad Request
```

## API仕様

### エンドポイント
```
POST /api/rooms/leave
```

### 認証
- Laravel Sanctum トークン必須
- ミドルウェア: `auth:sanctum`

### リクエスト

#### ヘッダー
```http
Authorization: Bearer {sanctum_token}
Content-Type: application/json
```

#### ボディ
```json
{}
```
（ボディは空でOK）

### レスポンス

#### 成功 (200)
```json
{
  "message": "ルームから退出しました"
}
```

#### エラー

##### 参加中のルームなし (400)
```json
{
  "message": "参加中のルームがありません"
}
```

##### 認証エラー (401)
```json
{
  "message": "Unauthenticated."
}
```

##### サーバーエラー (500)
```json
{
  "message": "Internal server error"
}
```

## データ構造

### Redisデータ変更

#### ユーザー参加ルーム (Set)
```
Key: user_rooms:{user_id}
Type: Set

変更前: [room_id1, room_id2, ...]
変更後: [] (空になる)
```

#### ルーム情報 (Hash)
```
Key: room:{room_id}
Type: Hash

変更内容:
- positive_user_id: ユーザーID → "" (空文字列)
- negative_user_id: ユーザーID → "" (空文字列)  
- status: matched → waiting (他のユーザーがいる場合)
- ルームが空の場合: キー自体を削除
```

### ログ出力

#### 成功ログ
```php
Log::info('ユーザーがルームから退出', [
    'user_id' => $user->id,
    'rooms_left' => ['room_id1', 'room_id2', ...]
]);
```

#### エラーログ
```php
Log::error('ルーム退出エラー', [
    'user_id' => $user->id,
    'error' => $e->getMessage()
]);
```

## 実装詳細

### 退出処理のロジック

1. **参加ルーム取得**: `user_rooms:{user_id}`から参加中のルーム一覧を取得
2. **ルーム状態確認**: 各ルームの現在の状態を確認
3. **ユーザー削除**: 該当するサイド（positive/negative）のユーザーIDをクリア
4. **ルーム状態更新**:
   - 両方のサイドが空 → ルームを削除
   - 片方のサイドが残る → ステータスを`waiting`に変更
5. **参加ルーム一覧更新**: `user_rooms:{user_id}`から退出したルームを削除

### エラーハンドリング

- **参加ルームなし**: 400エラーで適切なメッセージを返却
- **Redis接続エラー**: ログ出力して500エラーを返却
- **予期しないエラー**: ログ出力して500エラーを返却

## テスト仕様

### テストケース

1. **正常な退出テスト**: 参加中のルームから正常に退出
2. **参加ルームなしエラーテスト**: 参加中のルームがない場合のエラー
3. **複数ルーム退出テスト**: 複数のルームに参加している場合の一括退出
4. **ルーム状態更新テスト**: 退出後のルーム状態が正しく更新される
5. **Redisデータ更新テスト**: 退出後のRedisデータが正しく更新される

### テスト実行
```bash
# 全テスト実行
docker-compose exec php php artisan test tests/Feature/Api/RoomControllerTest.php

# 個別テスト実行
docker-compose exec php php artisan test tests/Feature/Api/RoomControllerTest.php::test_leave_room_success
```

## 実装ファイル

### 主要ファイル
- `app/Http/Api/Controllers/RoomController.php` - API エンドポイント
- `app/Services/RoomService.php` - 退出ロジック
- `routes/api.php` - ルート定義
- `tests/Feature/Api/RoomControllerTest.php` - テスト

### 設定ファイル
- `config/database.php` - Redis設定
- `.env` - 環境変数

## 注意事項

### セキュリティ
- 認証済みユーザーのみが退出可能
- ユーザーは自分の参加ルームのみから退出可能

### パフォーマンス
- 一括退出により効率的な処理
- Redis操作の最小化

### データ整合性
- ルーム状態の適切な管理
- ユーザー参加ルーム一覧の同期
