<?php

namespace App\Http\Api\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class RoomController extends Controller
{
    public function join(Request $request)
    {
        $user = Auth::user();
        
        return response()->json(['message' => 'Joined room successfully'], 200);
    }

    public function leave(Request $request)
    {
        $user = Auth::user();
        
        return response()->json(['message' => 'Left room successfully'], 200);
    }
}