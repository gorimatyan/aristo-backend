# 対戦参加機能 (Room Joining System)

## 概要

ユーザーが対戦ルームに参加し、マッチングを成立させる機能。同じトピック・テーマで異なるサイド（positive/negative）のユーザーを自動でマッチングする。

## マッチング仕様

### 基本フロー

1. **ユーザーが参加リクエスト** → 既存ルームを検索
2. **条件に合うルームがあれば参加** → マッチング成立
3. **なければ新規ルーム作成** → 待機状態
4. **マッチング成立時** → Pusherで通知送信

### マッチング条件

- **同一トピック**: `topicId`が同じ
- **同一テーマ**: `themeName`が同じ  
- **異なるサイド**: positiveとnegative
- **待機状態**: 既存ルームが`waiting`状態

### サイド割り当て

- **希望サイド指定あり**: そのサイドに参加（利用可能な場合）
- **希望サイド指定なし**: デフォルトで`positive`サイド
- **既存ルーム参加**: 空いているサイドに自動割り当て

## 処理フロー詳細

### 1. 新規ルーム作成パターン

**シナリオ**: 最初のユーザーが参加

```
リクエスト: { topicId: 1, themeName: "education", preferredSide: "negative" }

処理:
1. 既存ルーム検索 → 見つからない
2. 新規ルーム作成
3. ユーザーをnegativeサイドに配置
4. ルーム状態: waiting

レスポンス:
{
  "message": "ルームに参加しました",
  "data": {
    "room_id": "uuid-123",
    "side": "negative",
    "matched": false,  ← 待機中
    "channel": "presence-room-uuid-123"
  }
}

Redisデータ:
room:uuid-123 = {
  id: "uuid-123",
  topic_id: "1",
  theme_name: "education", 
  positive_user_id: "",
  negative_user_id: "1",
  status: "waiting"
}
```

### 2. マッチング成立パターン

**シナリオ**: 2人目のユーザーが参加

```
リクエスト: { topicId: 1, themeName: "education", preferredSide: "positive" }

処理:
1. 既存ルーム検索 → 見つかる（同じtopicId, themeName, waiting状態）
2. 既存ルームに参加
3. ユーザーをpositiveサイドに配置
4. ルーム状態: matched
5. Pusher通知送信

レスポンス:
{
  "message": "ルームに参加しました", 
  "data": {
    "room_id": "uuid-123",  ← 同じルーム
    "side": "positive",
    "matched": true,  ← マッチング成立
    "channel": "presence-room-uuid-123"
  }
}

Redisデータ:
room:uuid-123 = {
  id: "uuid-123",
  topic_id: "1",
  theme_name: "education",
  positive_user_id: "2",  ← 追加
  negative_user_id: "1",
  status: "matched"  ← 変更
}

Pusher通知:
{
  "event": "matching-success",
  "room_id": "uuid-123",
  "positive_user": { "id": "2", "name": "User 2" },
  "negative_user": { "id": "1", "name": "User 1" },
  "topic_id": "1",
  "theme_name": "education"
}
```

### 3. マッチングしないパターン

**シナリオ**: 同じサイド同士

```
1人目: { topicId: 1, themeName: "education", preferredSide: "positive" }
→ 新規ルーム作成、positiveサイド

2人目: { topicId: 1, themeName: "education", preferredSide: "positive" }  
→ 既存ルームはpositiveが埋まっているため、新規ルーム作成

結果: 2つの独立したルームが作成される
```

## API仕様

### エンドポイント
```
POST /api/rooms/join
```

### リクエスト
```json
{
  "topicId": 1,
  "themeName": "education", 
  "preferredSide": "positive"  // オプション
}
```

### レスポンス

#### 成功 (201)
```json
{
  "message": "ルームに参加しました",
  "data": {
    "room_id": "uuid-string",
    "side": "positive|negative",
    "matched": true|false,
    "channel": "presence-room-uuid-string"
  }
}
```

#### エラー

##### 既に参加済み (400)
```json
{
  "message": "既に参加済みです"
}
```

##### 希望サイドが利用不可 (400)
```json
{
  "message": "希望するサイドが利用できません"
}
```

## データ構造

### Redisデータ

#### ルーム情報
```
Key: room:{room_id}
Type: Hash

Fields:
- id: ルームID (UUID)
- topic_id: トピックID  
- theme_name: テーマ名
- positive_user_id: ポジティブサイドユーザーID
- negative_user_id: ネガティブサイドユーザーID  
- status: waiting|matched|completed
- created_at: 作成日時
- updated_at: 更新日時
```

#### ユーザー参加ルーム
```
Key: user_rooms:{user_id}
Type: Set

Members: [room_id1, room_id2, ...]
```

### Pusher通知

#### チャンネル
```
presence-room-{room_id}
```

#### イベント: matching-success
```json
{
  "event": "matching-success",
  "room_id": "uuid-string",
  "positive_user": { "id": "user_id", "name": "User {user_id}" },
  "negative_user": { "id": "user_id", "name": "User {user_id}" },
  "topic_id": "1",
  "theme_name": "education"
}
```

## 実装ファイル

### 主要ファイル
- `app/Http/Api/Controllers/RoomController.php` - API エンドポイント
- `app/Services/RoomService.php` - マッチングロジック
- `routes/api.php` - ルート定義
- `routes/channels.php` - Pusher認証
- `tests/Feature/Api/RoomControllerTest.php` - テスト

### 設定ファイル
- `config/database.php` - Redis設定
- `config/broadcasting.php` - Pusher設定
- `.env` - 環境変数
