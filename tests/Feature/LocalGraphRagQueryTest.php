<?php

namespace Tests\Feature;

use App\Models\KnowledgeBase;
use App\Services\GraphRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LocalGraphRagQueryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.ai.api_key' => 'test-key',
            'services.graph_rag.enabled' => true,
            'services.graph_rag.max_nodes' => 20,
        ]);
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/embeddings')) {
                return Http::response(['data' => [['embedding' => [1, 0]]]]);
            }

            return Http::response([
                'model' => 'test-model',
                'choices' => [['message' => ['content' => 'Alice works for Acme [1].']]],
                'usage' => ['total_tokens' => 20],
            ]);
        });
    }

    public function test_local_graph_evidence_is_fused_with_vector_results(): void
    {
        [$knowledgeBase, $evidenceChunk] = $this->createGraphFixture();

        $response = $this->withToken('test-key')->postJson("/api/knowledge-bases/{$knowledgeBase->id}/query", [
            'question' => 'Where does Alice work?',
            'mode' => 'local',
            'top_k' => 1,
            'max_hops' => 2,
            'include_graph' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('mode', 'local')
            ->assertJsonPath('sources.0.document_id', $evidenceChunk->document_id)
            ->assertJsonPath('sources.0.channels.0', 'vector')
            ->assertJsonPath('sources.0.channels.1', 'keyword')
            ->assertJsonPath('sources.0.channels.2', 'graph')
            ->assertJsonPath('retrieval.keyword_hits', 1)
            ->assertJsonPath('retrieval.graph_hits', 1)
            ->assertJsonCount(2, 'entities')
            ->assertJsonPath('relationships.0.type', 'WORKS_FOR')
            ->assertJsonPath('relationships.0.evidence.0.statement', 'Alice works for Acme.');
    }

    public function test_auto_mode_falls_back_to_vector_when_no_entity_matches(): void
    {
        [$knowledgeBase] = $this->createGraphFixture();

        $this->withToken('test-key')->postJson("/api/knowledge-bases/{$knowledgeBase->id}/query", [
            'question' => 'What unrelated topic is mentioned?',
            'mode' => 'auto',
            'include_graph' => true,
        ])->assertOk()
            ->assertJsonPath('mode', 'vector')
            ->assertJsonPath('retrieval.graph_hits', 0)
            ->assertJsonCount(0, 'entities')
            ->assertJsonCount(0, 'relationships');
    }

    public function test_vector_mode_never_traverses_the_graph(): void
    {
        [$knowledgeBase] = $this->createGraphFixture();

        $this->withToken('test-key')->postJson("/api/knowledge-bases/{$knowledgeBase->id}/query", [
            'question' => 'Where does Alice work?',
            'mode' => 'vector',
            'include_graph' => true,
        ])->assertOk()
            ->assertJsonPath('mode', 'vector')
            ->assertJsonPath('retrieval.graph_hits', 0)
            ->assertJsonCount(0, 'entities');
    }

    public function test_graph_search_does_not_use_entities_from_another_knowledge_base(): void
    {
        [$first] = $this->createGraphFixture();
        $second = KnowledgeBase::create(['name' => 'Empty']);

        $this->withToken('test-key')->postJson("/api/knowledge-bases/{$second->id}/query", [
            'question' => 'Where does Alice work?',
            'mode' => 'local',
            'include_graph' => true,
        ])->assertOk()
            ->assertJsonPath('mode', 'vector')
            ->assertJsonPath('retrieval.entities', 0)
            ->assertJsonCount(0, 'sources');

        $this->assertNotSame($first->id, $second->id);
    }

    public function test_keyword_results_can_promote_an_exact_term_over_a_vector_only_match(): void
    {
        config(['services.graph_rag.enabled' => false]);
        $knowledgeBase = KnowledgeBase::create(['name' => 'Codes']);
        $exactDocument = $knowledgeBase->documents()->create([
            'title' => 'Exact', 'source_content' => 'Incident ZX-491 is resolved.', 'status' => 'ready', 'index_version' => 1,
        ]);
        $exactChunk = $exactDocument->chunks()->create([
            'position' => 0, 'content' => 'Incident ZX-491 is resolved.', 'embedding' => [0, 1],
        ]);
        $vectorDocument = $knowledgeBase->documents()->create([
            'title' => 'Vector', 'source_content' => 'Generic semantic match.', 'status' => 'ready', 'index_version' => 1,
        ]);
        $vectorDocument->chunks()->create([
            'position' => 0, 'content' => 'Generic semantic match.', 'embedding' => [1, 0],
        ]);

        $this->withToken('test-key')->postJson("/api/knowledge-bases/{$knowledgeBase->id}/query", [
            'question' => 'What happened to ZX-491?', 'top_k' => 1,
        ])->assertOk()
            ->assertJsonPath('sources.0.document_id', $exactChunk->document_id)
            ->assertJsonPath('sources.0.channels.1', 'keyword')
            ->assertJsonPath('retrieval.keyword_hits', 1);
    }

    private function createGraphFixture(): array
    {
        $knowledgeBase = KnowledgeBase::create(['name' => 'Company knowledge']);
        $evidenceDocument = $knowledgeBase->documents()->create([
            'title' => 'Employment', 'source_content' => 'Alice works for Acme.', 'status' => 'ready', 'index_version' => 1,
        ]);
        $evidenceChunk = $evidenceDocument->chunks()->create([
            'position' => 0, 'content' => 'Alice works for Acme.', 'embedding' => [0, 1],
        ]);
        $distractorDocument = $knowledgeBase->documents()->create([
            'title' => 'Distractor', 'source_content' => 'An unrelated vector match.', 'status' => 'ready', 'index_version' => 1,
        ]);
        $distractorDocument->chunks()->create([
            'position' => 0, 'content' => 'An unrelated vector match.', 'embedding' => [1, 0],
        ]);

        app(GraphRepository::class)->storeChunkGraph($evidenceChunk, [
            'entities' => [
                ['key' => 'alice', 'name' => 'Alice', 'type' => 'Person', 'description' => null, 'aliases' => ['A.']],
                ['key' => 'acme', 'name' => 'Acme', 'type' => 'Organization', 'description' => null, 'aliases' => []],
            ],
            'relationships' => [[
                'source_key' => 'alice', 'target_key' => 'acme', 'type' => 'WORKS_FOR',
                'description' => 'Employment', 'statement' => 'Alice works for Acme.', 'confidence' => 0.99,
            ]],
        ]);

        return [$knowledgeBase, $evidenceChunk];
    }
}
