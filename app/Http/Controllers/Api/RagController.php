<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\KnowledgeBase;
use App\Services\DocumentIngestor;
use App\Services\RagQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RagController extends Controller
{
    public function ingest(Request $request, KnowledgeBase $knowledgeBase, DocumentIngestor $ingestor): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'], 'content' => ['required', 'string'],
            'source' => ['nullable', 'string', 'max:2048'], 'metadata' => ['nullable', 'array'],
            'async' => ['sometimes', 'boolean'],
        ]);

        if ($data['async'] ?? false) {
            return response()->json($ingestor->ingestAsync($knowledgeBase, $data), 202);
        }

        return response()->json($ingestor->ingest($knowledgeBase, $data), 201);
    }

    public function query(Request $request, KnowledgeBase $knowledgeBase, RagQueryService $queries): JsonResponse
    {
        $data = $request->validate([
            'question' => ['required', 'string', 'max:10000'], 'top_k' => ['sometimes', 'integer', 'between:1,20'],
            'model' => ['sometimes', 'string', 'max:255'], 'temperature' => ['sometimes', 'numeric', 'between:0,2'],
            'mode' => ['sometimes', 'string', 'in:auto,local,global,vector'],
            'max_hops' => ['sometimes', 'integer', 'between:1,2'],
            'community_top_k' => ['sometimes', 'integer', 'between:1,10'],
            'include_graph' => ['sometimes', 'boolean'],
        ]);

        return response()->json($queries->ask($knowledgeBase, $data['question'], $data));
    }

    public function status(KnowledgeBase $knowledgeBase, Document $document): JsonResponse
    {
        $this->ensureDocumentBelongsToKnowledgeBase($document, $knowledgeBase);

        return response()->json($document->only([
            'id', 'knowledge_base_id', 'status', 'index_version', 'indexed_at', 'failure_reason',
        ]) + ['chunks_count' => $document->chunks()->count()]);
    }

    public function retry(KnowledgeBase $knowledgeBase, Document $document, DocumentIngestor $ingestor): JsonResponse
    {
        $this->ensureDocumentBelongsToKnowledgeBase($document, $knowledgeBase);
        abort_unless($document->status === 'failed', 409, 'Only failed documents can be retried.');

        return response()->json($ingestor->retryIndex($document), 202);
    }

    private function ensureDocumentBelongsToKnowledgeBase(Document $document, KnowledgeBase $knowledgeBase): void
    {
        abort_unless($document->knowledge_base_id === $knowledgeBase->id, 404);
    }
}
