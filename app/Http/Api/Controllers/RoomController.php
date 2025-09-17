<?php

namespace App\Http\Api\Controllers;

use App\Http\Controllers\Controller;
use App\Services\RoomService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class RoomController extends Controller
{
    private $roomService;

    public function __construct(RoomService $roomService)
    {
        $this->roomService = $roomService;
    }

    /**
     * ルームに参加する
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     * 
     * @response 201 {
     *   "message": "ルームに参加しました",
     *   "data": {
     *     "room_id": "uuid-string",
     *     "side": "positive|negative",
     *     "matched": true|false,
     *     "channel": "presence-room.uuid-string",
     *     "auth": {
     *       "auth": "pusher_auth_string",
     *       "channel_data": "user_info_json"
     *     }
     *   }
     * }
     * 
     * @response 400 {
     *   "message": "既に参加済みです"
     * }
     * 
     * @response 400 {
     *   "message": "希望するサイドが利用できません"
     * }
     * 
     * @response 422 {
     *   "message": "バリデーションエラー",
     *   "errors": {
     *     "topicId": ["The topicId field is required."],
     *     "themeName": ["The themeName field is required."]
     *   }
     * }
     * 
     * @response 500 {
     *   "message": "Internal server error"
     * }
     */
    public function join(Request $request)
    {
        $user = Auth::user();
        
        // バリデーション
        $validator = Validator::make($request->all(), [
            'topicId' => 'required|integer|min:1',
            'themeName' => 'required|string|max:255',
            'preferredSide' => 'nullable|in:positive,negative'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'バリデーションエラー',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $result = $this->roomService->joinRoom(
                $user,
                $request->topicId,
                $request->themeName,
                $request->preferredSide
            );


            return response()->json([
                'message' => 'ルームに参加しました',
                'data' => $result
            ], 201);

        } catch (\Exception $e) {
            Log::error('ルーム参加エラー', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'request' => $request->all()
            ]);

            return response()->json([
                'message' => $e->getMessage()
            ], $e->getCode() ?: 500);
        }
    }


    /**
     * ルームから退出する
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     * 
     * @response 200 {
     *   "message": "ルームから退出しました"
     * }
     * 
     * @response 400 {
     *   "message": "参加中のルームがありません"
     * }
     * 
     * @response 401 {
     *   "message": "Unauthenticated."
     * }
     * 
     * @response 500 {
     *   "message": "Internal server error"
     * }
     */
    public function leave(Request $request)
    {
        $user = Auth::user();
        
        try {
            $result = $this->roomService->leaveRoom($user);
            
            return response()->json($result, 200);

        } catch (\Exception $e) {
            Log::error('ルーム退出エラー', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => $e->getMessage()
            ], $e->getCode() ?: 500);
        }
    }
}