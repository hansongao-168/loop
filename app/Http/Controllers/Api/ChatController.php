<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Ai\LoopRouter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    public function __invoke(Request $request, LoopRouter $loop): JsonResponse
    {
        $data = $request->validate([
            'messages' => ['required', 'array', 'min:1'], 'messages.*.role' => ['required', 'in:system,user,assistant'],
            'messages.*.content' => ['required', 'string'], 'model' => ['sometimes', 'string'],
            'temperature' => ['sometimes', 'numeric', 'between:0,2'],
        ]);

        $result = $loop->chat(
            $data['messages'],
            $data['model'] ?? null,
            (float) ($data['temperature'] ?? 0.2),
            ['task' => 'chat_direct'],
        );

        return response()->json($result->toArray());
    }
}
