<?php

namespace Tests\Feature;

use App\Models\KnowledgeBase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CandidateRerankerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.ai.api_key' => 'test-key',
            'services.graph_rag.enabled' => false,
            'services.graph_rag.rerank_enabled' => true,
            'services.graph_rag.rerank_model' => 'rerank-model',
            'services.graph_rag.rerank_candidates' => 10,
        ]);
    }

    public function test_valid_model_permutation_reranks_the_rrf_candidate_pool(): void
    {
        [$knowledgeBase, $wrongChunk, $correctChunk] = $this->createFixture();
        $this->fakeAi([$correctChunk->id, $wrongChunk->id]);

        $this->withToken('test-key')->postJson("/api/knowledge-bases/{$knowledgeBase->id}/query", [
            'question' => 'Choose the relevant evidence', 'mode' => 'vector', 'top_k' => 1,
        ])->assertOk()
            ->assertJsonPath('sources.0.document_id', $correctChunk->document_id)
            ->assertJsonPath('retrieval.candidate_hits', 2)
            ->assertJsonPath('retrieval.reranked', true);
    }

    public function test_invalid_reranker_output_falls_back_to_rrf_order(): void
    {
        [$knowledgeBase, $wrongChunk] = $this->createFixture();
        $this->fakeAi([999]);

        $this->withToken('test-key')->postJson("/api/knowledge-bases/{$knowledgeBase->id}/query", [
            'question' => 'Choose the relevant evidence', 'mode' => 'vector', 'top_k' => 1,
        ])->assertOk()
            ->assertJsonPath('sources.0.document_id', $wrongChunk->document_id)
            ->assertJsonPath('retrieval.reranked', false);
    }

    public function test_disabled_reranking_does_not_add_a_model_request(): void
    {
        config(['services.graph_rag.rerank_enabled' => false]);
        [$knowledgeBase, $wrongChunk] = $this->createFixture();
        $this->fakeAi([]);

        $this->withToken('test-key')->postJson("/api/knowledge-bases/{$knowledgeBase->id}/query", [
            'question' => 'Choose the relevant evidence', 'mode' => 'vector', 'top_k' => 1,
        ])->assertOk()
            ->assertJsonPath('sources.0.document_id', $wrongChunk->document_id)
            ->assertJsonPath('retrieval.reranked', false);
        Http::assertSentCount(2);
    }

    private function createFixture(): array
    {
        $knowledgeBase = KnowledgeBase::create(['name' => 'Reranking']);
        $wrongDocument = $knowledgeBase->documents()->create(['title' => 'Vector winner', 'status' => 'ready']);
        $wrongChunk = $wrongDocument->chunks()->create([
            'position' => 0, 'content' => 'Semantically close but incorrect evidence.', 'embedding' => [1, 0],
        ]);
        $correctDocument = $knowledgeBase->documents()->create(['title' => 'Correct evidence', 'status' => 'ready']);
        $correctChunk = $correctDocument->chunks()->create([
            'position' => 0, 'content' => 'The directly relevant and correct evidence.', 'embedding' => [0, 1],
        ]);

        return [$knowledgeBase, $wrongChunk, $correctChunk];
    }

    private function fakeAi(array $rankedChunkIds): void
    {
        Http::swap(new Factory);
        Http::fake(function ($request) use ($rankedChunkIds) {
            if (str_ends_with($request->url(), '/embeddings')) {
                return Http::response(['data' => [['embedding' => [1, 0]]]]);
            }
            $system = (string) data_get($request->data(), 'messages.0.content');
            if (str_contains($system, 'Rank every candidate chunk')) {
                return Http::response([
                    'model' => 'rerank-model',
                    'choices' => [['message' => ['content' => json_encode([
                        'ranked_chunk_ids' => $rankedChunkIds,
                    ], JSON_THROW_ON_ERROR)]]],
                ]);
            }

            return Http::response([
                'model' => 'test-model',
                'choices' => [['message' => ['content' => 'Answer [1].']]],
            ]);
        });
    }
}
