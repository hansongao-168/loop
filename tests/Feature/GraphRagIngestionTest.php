<?php

namespace Tests\Feature;

use App\Models\DocumentChunk;
use App\Models\GraphEntity;
use App\Models\GraphRelationship;
use App\Models\KnowledgeBase;
use App\Services\GraphRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GraphRagIngestionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.ai.api_key' => 'test-key',
            'services.graph_rag.enabled' => true,
            'services.graph_rag.extraction_model' => 'test-model',
        ]);
    }

    public function test_document_ingestion_builds_an_evidence_backed_graph(): void
    {
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/embeddings')) {
                return Http::response(['data' => [['embedding' => [1, 0, 0]]]]);
            }

            return Http::response([
                'model' => 'test-model',
                'choices' => [['message' => ['content' => json_encode([
                    'entities' => [
                        ['key' => 'e1', 'name' => 'Alice', 'type' => 'Person', 'description' => 'Engineer', 'aliases' => []],
                        ['key' => 'e2', 'name' => 'Acme', 'type' => 'Organization', 'description' => 'Company', 'aliases' => []],
                    ],
                    'relationships' => [[
                        'source_key' => 'e1', 'target_key' => 'e2', 'type' => 'works for',
                        'description' => 'Alice works for Acme.', 'statement' => 'Alice works for Acme.', 'confidence' => 0.98,
                    ]],
                ], JSON_THROW_ON_ERROR)]]],
            ]);
        });

        $knowledgeBase = KnowledgeBase::create(['name' => 'Test']);
        $response = $this->withToken('test-key')->postJson("/api/knowledge-bases/{$knowledgeBase->id}/documents", [
            'title' => 'People',
            'content' => 'Alice works for Acme.',
        ]);

        $response->assertCreated()->assertJsonPath('status', 'ready');
        $this->assertDatabaseCount('graph_entities', 2);
        $this->assertDatabaseHas('graph_relationships', ['type' => 'WORKS_FOR', 'weight' => 1]);
        $this->assertDatabaseHas('graph_relationship_evidence', ['statement' => 'Alice works for Acme.']);

        $relationship = GraphRelationship::with('evidence.chunk.document')->firstOrFail();
        $this->assertSame('People', $relationship->evidence->first()->chunk->document->title);
    }

    public function test_storing_the_same_chunk_graph_twice_is_idempotent(): void
    {
        $knowledgeBase = KnowledgeBase::create(['name' => 'Test']);
        $document = $knowledgeBase->documents()->create(['title' => 'People', 'status' => 'ready']);
        $chunk = $document->chunks()->create(['position' => 0, 'content' => 'Alice knows Bob.', 'embedding' => [1, 0]]);
        $graph = [
            'entities' => [
                ['key' => 'a', 'name' => 'Alice', 'type' => 'Person', 'description' => null, 'aliases' => []],
                ['key' => 'b', 'name' => 'Bob', 'type' => 'Person', 'description' => null, 'aliases' => []],
            ],
            'relationships' => [[
                'source_key' => 'a', 'target_key' => 'b', 'type' => 'KNOWS', 'description' => null,
                'statement' => 'Alice knows Bob.', 'confidence' => 0.9,
            ]],
        ];

        $repository = app(GraphRepository::class);
        $repository->storeChunkGraph($chunk, $graph);
        $repository->storeChunkGraph($chunk, $graph);

        $this->assertDatabaseCount('graph_entities', 2);
        $this->assertDatabaseCount('graph_mentions', 2);
        $this->assertDatabaseCount('graph_relationships', 1);
        $this->assertDatabaseCount('graph_relationship_evidence', 1);
        $this->assertSame(1, GraphRelationship::firstOrFail()->weight);
    }

    public function test_deleting_a_document_removes_graph_facts_with_no_remaining_evidence(): void
    {
        $knowledgeBase = KnowledgeBase::create(['name' => 'Test']);
        $document = $knowledgeBase->documents()->create(['title' => 'People', 'status' => 'ready']);
        $chunk = $document->chunks()->create(['position' => 0, 'content' => 'Alice knows Bob.', 'embedding' => [1, 0]]);
        app(GraphRepository::class)->storeChunkGraph($chunk, [
            'entities' => [
                ['key' => 'a', 'name' => 'Alice', 'type' => 'Person', 'description' => null, 'aliases' => []],
                ['key' => 'b', 'name' => 'Bob', 'type' => 'Person', 'description' => null, 'aliases' => []],
            ],
            'relationships' => [[
                'source_key' => 'a', 'target_key' => 'b', 'type' => 'KNOWS', 'description' => null,
                'statement' => 'Alice knows Bob.', 'confidence' => 0.9,
            ]],
        ]);

        $document->delete();

        $this->assertDatabaseCount('graph_relationship_evidence', 0);
        $this->assertDatabaseCount('graph_relationships', 0);
        $this->assertDatabaseCount('graph_mentions', 0);
        $this->assertDatabaseCount('graph_entities', 0);
    }

    public function test_a_unique_alias_resolves_to_an_existing_entity_of_the_same_type(): void
    {
        $knowledgeBase = KnowledgeBase::create(['name' => 'Test']);
        $firstDocument = $knowledgeBase->documents()->create(['title' => 'First', 'status' => 'ready']);
        $firstChunk = $firstDocument->chunks()->create(['position' => 0, 'content' => 'International Business Machines', 'embedding' => [1]]);
        $secondDocument = $knowledgeBase->documents()->create(['title' => 'Second', 'status' => 'ready']);
        $secondChunk = $secondDocument->chunks()->create(['position' => 0, 'content' => 'IBM', 'embedding' => [1]]);
        $repository = app(GraphRepository::class);

        $repository->storeChunkGraph($firstChunk, [
            'entities' => [[
                'key' => 'ibm', 'name' => 'International Business Machines', 'type' => 'Organization',
                'description' => null, 'aliases' => ['IBM'],
            ]],
            'relationships' => [],
        ]);
        $repository->storeChunkGraph($secondChunk, [
            'entities' => [[
                'key' => 'ibm', 'name' => 'IBM', 'type' => 'Organization', 'description' => null, 'aliases' => [],
            ]],
            'relationships' => [],
        ]);

        $this->assertDatabaseCount('graph_entities', 1);
        $this->assertDatabaseCount('graph_mentions', 2);
        $entity = GraphEntity::firstOrFail();
        $this->assertSame('International Business Machines', $entity->canonical_name);
        $this->assertContains('IBM', $entity->aliases);
    }

    public function test_alias_resolution_never_merges_entities_of_different_types(): void
    {
        $knowledgeBase = KnowledgeBase::create(['name' => 'Test']);
        $document = $knowledgeBase->documents()->create(['title' => 'Mixed', 'status' => 'ready']);
        $firstChunk = $document->chunks()->create(['position' => 0, 'content' => 'Mercury company', 'embedding' => [1]]);
        $secondChunk = $document->chunks()->create(['position' => 1, 'content' => 'Mercury product', 'embedding' => [1]]);
        $repository = app(GraphRepository::class);

        $repository->storeChunkGraph($firstChunk, [
            'entities' => [['key' => 'm', 'name' => 'Mercury Inc', 'type' => 'Organization', 'description' => null, 'aliases' => ['Mercury']]],
            'relationships' => [],
        ]);
        $repository->storeChunkGraph($secondChunk, [
            'entities' => [['key' => 'm', 'name' => 'Mercury', 'type' => 'Product', 'description' => null, 'aliases' => []]],
            'relationships' => [],
        ]);

        $this->assertDatabaseCount('graph_entities', 2);
    }

    public function test_semantic_resolution_merges_a_clear_same_type_candidate(): void
    {
        config([
            'services.graph_rag.semantic_entity_resolution' => true,
            'services.graph_rag.entity_similarity_threshold' => 0.9,
            'services.graph_rag.entity_similarity_margin' => 0.05,
        ]);
        Http::swap(new Factory);
        Http::fake(fn ($request) => Http::response(['data' => [['embedding' => str_contains((string) $request['input'], 'OpenAI Inc') ? [0.99, 0.01] : [1, 0],
        ]]]));
        $knowledgeBase = KnowledgeBase::create(['name' => 'Test']);
        $document = $knowledgeBase->documents()->create(['title' => 'Names', 'status' => 'ready']);
        $first = $document->chunks()->create(['position' => 0, 'content' => 'OpenAI Incorporated', 'embedding' => [1]]);
        $second = $document->chunks()->create(['position' => 1, 'content' => 'OpenAI Inc', 'embedding' => [1]]);
        $repository = app(GraphRepository::class);

        $repository->storeChunkGraph($first, $this->singleEntityGraph('OpenAI Incorporated', 'AI company'));
        $repository->storeChunkGraph($second, $this->singleEntityGraph('OpenAI Inc', 'AI company'));

        $this->assertDatabaseCount('graph_entities', 1);
        $this->assertDatabaseCount('graph_mentions', 2);
        $this->assertContains('OpenAI Inc', GraphEntity::firstOrFail()->aliases);
        Http::assertSentCount(2);
    }

    public function test_semantic_resolution_keeps_a_low_similarity_entity_separate(): void
    {
        config([
            'services.graph_rag.semantic_entity_resolution' => true,
            'services.graph_rag.entity_similarity_threshold' => 0.9,
            'services.graph_rag.entity_similarity_margin' => 0.05,
        ]);
        Http::swap(new Factory);
        Http::fake(fn ($request) => Http::response(['data' => [['embedding' => str_contains((string) $request['input'], 'Beta') ? [0, 1] : [1, 0],
        ]]]));
        $knowledgeBase = KnowledgeBase::create(['name' => 'Test']);
        $document = $knowledgeBase->documents()->create(['title' => 'Names', 'status' => 'ready']);
        $first = $document->chunks()->create(['position' => 0, 'content' => 'Alpha', 'embedding' => [1]]);
        $second = $document->chunks()->create(['position' => 1, 'content' => 'Beta', 'embedding' => [1]]);
        $repository = app(GraphRepository::class);

        $repository->storeChunkGraph($first, $this->singleEntityGraph('Alpha', 'First concept'));
        $repository->storeChunkGraph($second, $this->singleEntityGraph('Beta', 'Second concept'));

        $this->assertDatabaseCount('graph_entities', 2);
    }

    public function test_disabled_semantic_resolution_never_adds_embedding_calls(): void
    {
        config(['services.graph_rag.semantic_entity_resolution' => false]);
        Http::swap(new Factory);
        Http::fake();
        $knowledgeBase = KnowledgeBase::create(['name' => 'Test']);
        $document = $knowledgeBase->documents()->create(['title' => 'Names', 'status' => 'ready']);
        $chunk = $document->chunks()->create(['position' => 0, 'content' => 'Alpha', 'embedding' => [1]]);

        app(GraphRepository::class)->storeChunkGraph($chunk, $this->singleEntityGraph('Alpha', 'Concept'));

        Http::assertNothingSent();
        $this->assertNull(GraphEntity::firstOrFail()->embedding);
    }

    public function test_semantic_resolution_rejects_candidates_without_a_clear_margin(): void
    {
        config([
            'services.graph_rag.semantic_entity_resolution' => true,
            'services.graph_rag.entity_similarity_threshold' => 0.7,
            'services.graph_rag.entity_similarity_margin' => 0.1,
        ]);
        Http::swap(new Factory);
        Http::fake(function ($request) {
            $input = (string) $request['input'];
            $embedding = match (true) {
                str_contains($input, 'Alpha') => [1, 0],
                str_contains($input, 'Beta') => [0, 1],
                default => [0.707, 0.707],
            };

            return Http::response(['data' => [['embedding' => $embedding]]]);
        });
        $knowledgeBase = KnowledgeBase::create(['name' => 'Test']);
        $document = $knowledgeBase->documents()->create(['title' => 'Names', 'status' => 'ready']);
        $repository = app(GraphRepository::class);
        foreach (['Alpha', 'Beta', 'Gamma'] as $position => $name) {
            $chunk = $document->chunks()->create(['position' => $position, 'content' => $name, 'embedding' => [1]]);
            $repository->storeChunkGraph($chunk, $this->singleEntityGraph($name, 'Concept'));
        }

        $this->assertDatabaseCount('graph_entities', 3);
    }

    public function test_graph_endpoints_are_scoped_to_the_requested_knowledge_base(): void
    {
        $first = KnowledgeBase::create(['name' => 'First']);
        $second = KnowledgeBase::create(['name' => 'Second']);
        $visible = GraphEntity::create([
            'knowledge_base_id' => $first->id, 'canonical_name' => 'Visible', 'normalized_name' => 'visible', 'type' => 'Concept',
        ]);
        $hidden = GraphEntity::create([
            'knowledge_base_id' => $second->id, 'canonical_name' => 'Hidden', 'normalized_name' => 'hidden', 'type' => 'Concept',
        ]);

        $this->withToken('test-key')->getJson("/api/knowledge-bases/{$first->id}/graph")
            ->assertOk()
            ->assertJsonPath('stats.entities', 1)
            ->assertJsonFragment(['canonical_name' => 'Visible'])
            ->assertJsonMissing(['canonical_name' => 'Hidden']);

        $this->withToken('test-key')->getJson("/api/knowledge-bases/{$first->id}/graph/entities/{$visible->id}")->assertOk();
        $this->withToken('test-key')->getJson("/api/knowledge-bases/{$first->id}/graph/entities/{$hidden->id}")->assertNotFound();
    }

    private function singleEntityGraph(string $name, string $description): array
    {
        return [
            'entities' => [[
                'key' => 'entity', 'name' => $name, 'type' => 'Organization',
                'description' => $description, 'aliases' => [],
            ]],
            'relationships' => [],
        ];
    }

    public function test_graph_building_can_be_disabled_for_backward_compatible_ingestion(): void
    {
        config(['services.graph_rag.enabled' => false]);
        Http::fake(['*/embeddings' => Http::response(['data' => [['embedding' => [1, 0, 0]]]])]);
        $knowledgeBase = KnowledgeBase::create(['name' => 'Test']);

        $this->withToken('test-key')->postJson("/api/knowledge-bases/{$knowledgeBase->id}/documents", [
            'title' => 'Plain RAG', 'content' => 'No graph extraction call is made.',
        ])->assertCreated();

        $this->assertDatabaseCount('graph_entities', 0);
        Http::assertSentCount(1);
        $this->assertDatabaseCount((new DocumentChunk)->getTable(), 1);
    }
}
