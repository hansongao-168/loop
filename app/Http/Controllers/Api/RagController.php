<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KnowledgeBase;
use App\Services\RagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RagController extends Controller
{
    public function ingest(Request $request, KnowledgeBase $knowledgeBase, RagService $rag): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'], 'content' => ['required', 'string'],
            'source' => ['nullable', 'string', 'max:2048'], 'metadata' => ['nullable', 'array'],
        ]);
        return response()->json($rag->ingest($knowledgeBase, $data), 201);
    }

    public function query(Request $request, KnowledgeBase $knowledgeBase, RagService $rag): JsonResponse
    {
        $data = $request->validate([
            'question' => ['required', 'string', 'max:10000'], 'top_k' => ['sometimes', 'integer', 'between:1,20'],
            'model' => ['sometimes', 'string', 'max:255'], 'temperature' => ['sometimes', 'numeric', 'between:0,2'],
        ]);
        return response()->json($rag->ask($knowledgeBase, $data['question'], $data));
    }
}
