<?php

namespace Tests\Feature;

use App\Jobs\BuildGraphCommunities;
use App\Models\KnowledgeBase;
use App\Services\CommunityBuildService;
use App\Services\GraphRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GlobalGraphRagQueryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.ai.api_key' => 'test-key',
            'services.graph_rag.enabled' => true,
            'services.graph_rag.summary_model' => 'summary-model',
        ]);
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/embeddings')) {
                $input = (string) $request['input'];

                return Http::response(['data' => [['embedding' => str_contains($input, 'Alice') ? [1, 0] : [0, 1]]]]);
            }

            $system = (string) data_get($request->data(), 'messages.0.content');
            if (str_contains($system, 'Summarize the supplied knowledge-graph community')) {
                $user = (string) data_get($request->data(), 'messages.1.content');
                $alice = str_contains($user, 'Alice');

                return Http::response([
                    'model' => 'summary-model',
                    'choices' => [['message' => ['content' => json_encode([
                        'title' => $alice ? 'Alice and Acme' : 'Bob and Beta',
                        'summary' => $alice ? 'Alice leads Acme.' : 'Bob founded Beta.',
                    ], JSON_THROW_ON_ERROR)]]],
                ]);
            }

            return Http::response([
                'model' => 'test-model',
                'choices' => [['message' => ['content' => 'The main Alice theme is her leadership of Acme [1].']]],
            ]);
        });
    }

    public function test_community_rebuild_creates_versioned_connected_components(): void
    {
        $knowledgeBase = $this->createTwoCommunityGraph();

        $response = $this->withToken('test-key')
            ->postJson("/api/knowledge-bases/{$knowledgeBase->id}/graph/rebuild-communities");

        $response->assertOk()
            ->assertJsonPath('communities', 2)
            ->assertJsonStructure(['build_version']);
        $this->assertDatabaseCount('graph_communities', 2);
        $this->assertDatabaseCount('graph_community_members', 4);
        $this->assertSame(1, $knowledgeBase->graphCommunities()->distinct('build_version')->count('build_version'));
        $this->assertDatabaseHas('graph_communities', ['title' => 'Alice and Acme']);
    }

    public function test_global_query_selects_relevant_community_and_returns_original_evidence(): void
    {
        $knowledgeBase = $this->createTwoCommunityGraph();
        $this->withToken('test-key')->postJson("/api/knowledge-bases/{$knowledgeBase->id}/graph/rebuild-communities")->assertOk();

        $this->withToken('test-key')->postJson("/api/knowledge-bases/{$knowledgeBase->id}/query", [
            'question' => 'Summarize the Alice theme',
            'mode' => 'global',
            'community_top_k' => 1,
            'top_k' => 1,
            'include_graph' => true,
        ])->assertOk()
            ->assertJsonPath('mode', 'global')
            ->assertJsonPath('retrieval.communities', 1)
            ->assertJsonPath('communities.0.title', 'Alice and Acme')
            ->assertJsonPath('sources.0.title', 'Alice evidence')
            ->assertJsonPath('sources.0.channels.2', 'graph');
    }

    public function test_global_query_falls_back_when_communities_have_not_been_built(): void
    {
        $knowledgeBase = $this->createTwoCommunityGraph();

        $this->withToken('test-key')->postJson("/api/knowledge-bases/{$knowledgeBase->id}/query", [
            'question' => 'Summarize the Alice theme', 'mode' => 'global', 'include_graph' => true,
        ])->assertOk()
            ->assertJsonPath('mode', 'vector')
            ->assertJsonPath('retrieval.communities', 0)
            ->assertJsonCount(0, 'communities');
    }

    public function test_community_invalidation_is_document_level_not_chunk_level(): void
    {
        $knowledgeBase = $this->createTwoCommunityGraph();
        $this->withToken('test-key')->postJson("/api/knowledge-bases/{$knowledgeBase->id}/graph/rebuild-communities")->assertOk();
        $this->assertDatabaseCount('graph_communities', 2);

        $versionBefore = $knowledgeBase->fresh()->graph_version;
        $document = $knowledgeBase->documents()->create(['title' => 'New', 'status' => 'ready']);
        $chunk = $document->chunks()->create(['position' => 0, 'content' => 'Gamma exists.', 'embedding' => [1, 0]]);
        app(GraphRepository::class)->storeChunkGraph($chunk, [
            'entities' => [['key' => 'g', 'name' => 'Gamma', 'type' => 'Organization', 'description' => null, 'aliases' => []]],
            'relationships' => [],
        ]);

        // Chunk writes no longer invalidate communities per chunk; the
        // indexer invalidates once per document after its graph writes.
        $this->assertDatabaseCount('graph_communities', 2);
        $this->assertSame($versionBefore, $knowledgeBase->fresh()->graph_version);

        app(GraphRepository::class)->invalidateCommunities($knowledgeBase->id);

        $this->assertDatabaseCount('graph_communities', 0);
        $this->assertSame($versionBefore + 1, $knowledgeBase->fresh()->graph_version);
    }

    public function test_async_community_build_is_versioned_observable_and_scoped(): void
    {
        Queue::fake();
        $knowledgeBase = $this->createTwoCommunityGraph();
        $other = KnowledgeBase::create(['name' => 'Other']);

        $response = $this->withToken('test-key')->postJson(
            "/api/knowledge-bases/{$knowledgeBase->id}/graph/rebuild-communities",
            ['async' => true],
        );

        $response->assertAccepted()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('graph_version', $knowledgeBase->fresh()->graph_version);
        $buildId = $response->json('id');
        Queue::assertPushed(BuildGraphCommunities::class, fn ($job) => $job->buildId === $buildId);
        $this->withToken('test-key')->getJson("/api/knowledge-bases/{$other->id}/graph/community-builds/{$buildId}")
            ->assertNotFound();

        (new BuildGraphCommunities($buildId))->handle(app(CommunityBuildService::class));

        $this->withToken('test-key')->getJson("/api/knowledge-bases/{$knowledgeBase->id}/graph/community-builds/{$buildId}")
            ->assertOk()
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('communities_count', 2)
            ->assertJsonPath('failure_reason', null);
    }

    public function test_async_build_fails_instead_of_persisting_a_stale_graph_version(): void
    {
        Queue::fake();
        $knowledgeBase = $this->createTwoCommunityGraph();
        $response = $this->withToken('test-key')->postJson(
            "/api/knowledge-bases/{$knowledgeBase->id}/graph/rebuild-communities",
            ['async' => true],
        )->assertAccepted();
        $buildId = $response->json('id');

        // Graph changes after the build record was created bump the
        // version (document-level invalidation), making the build stale.
        app(GraphRepository::class)->invalidateCommunities($knowledgeBase->id);

        try {
            (new BuildGraphCommunities($buildId))->handle(app(CommunityBuildService::class));
            $this->fail('Expected a stale graph version failure.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('changed before', $exception->getMessage());
        }

        $this->assertDatabaseHas('graph_community_builds', ['id' => $buildId, 'status' => 'failed']);
        $this->assertDatabaseCount('graph_communities', 0);
    }

    private function createTwoCommunityGraph(): KnowledgeBase
    {
        $knowledgeBase = KnowledgeBase::create(['name' => 'Global']);
        $repository = app(GraphRepository::class);
        $aliceDocument = $knowledgeBase->documents()->create(['title' => 'Alice evidence', 'status' => 'ready']);
        $aliceChunk = $aliceDocument->chunks()->create(['position' => 0, 'content' => 'Alice leads Acme.', 'embedding' => [0, 1]]);
        $repository->storeChunkGraph($aliceChunk, [
            'entities' => [
                ['key' => 'a', 'name' => 'Alice', 'type' => 'Person', 'description' => null, 'aliases' => []],
                ['key' => 'ac', 'name' => 'Acme', 'type' => 'Organization', 'description' => null, 'aliases' => []],
            ],
            'relationships' => [[
                'source_key' => 'a', 'target_key' => 'ac', 'type' => 'LEADS', 'description' => null,
                'statement' => 'Alice leads Acme.', 'confidence' => 0.99,
            ]],
        ]);
        $bobDocument = $knowledgeBase->documents()->create(['title' => 'Bob evidence', 'status' => 'ready']);
        $bobChunk = $bobDocument->chunks()->create(['position' => 0, 'content' => 'Bob founded Beta.', 'embedding' => [1, 0]]);
        $repository->storeChunkGraph($bobChunk, [
            'entities' => [
                ['key' => 'b', 'name' => 'Bob', 'type' => 'Person', 'description' => null, 'aliases' => []],
                ['key' => 'be', 'name' => 'Beta', 'type' => 'Organization', 'description' => null, 'aliases' => []],
            ],
            'relationships' => [[
                'source_key' => 'b', 'target_key' => 'be', 'type' => 'FOUNDED', 'description' => null,
                'statement' => 'Bob founded Beta.', 'confidence' => 0.99,
            ]],
        ]);

        return $knowledgeBase;
    }

    /**
     * The unit-test chain graph, scaled to evidence-count weights: four
     * tightly-linked pairs (weight 5) chained by strong bridges (weight
     * 5) and split in the middle by one weak edge (weight 2). Louvain
     * finds the four pairs at level 0 and the two halves at level 1.
     */
    private function createChainedPairGraph(): KnowledgeBase
    {
        $knowledgeBase = KnowledgeBase::create(['name' => 'Chained']);
        $repository = app(GraphRepository::class);
        $document = $knowledgeBase->documents()->create(['title' => 'Chain', 'status' => 'ready']);

        $entityKeys = [];
        foreach (range(1, 8) as $i) {
            $entityKeys["n{$i}"] = ['key' => "n{$i}", 'name' => "N{$i}", 'type' => 'Concept', 'description' => null, 'aliases' => []];
        }

        $relationship = function (string $source, string $target, string $statement, float $confidence = 0.9) {
            return [
                'source_key' => $source, 'target_key' => $target, 'type' => 'RELATES', 'description' => null,
                'statement' => $statement, 'confidence' => $confidence,
            ];
        };

        $strongEdges = [
            ['n1', 'n2'], ['n3', 'n4'], ['n5', 'n6'], ['n7', 'n8'], // pairs
            ['n2', 'n3'], ['n6', 'n7'], // strong bridges
        ];
        $chunkIndex = 0;
        foreach ([...$strongEdges, ...$strongEdges, ['n4', 'n5']] as $index => [$source, $target]) {
            $chunk = $document->chunks()->create([
                'position' => $chunkIndex++,
                'content' => "Evidence {$source} {$target}.",
                'embedding' => [1, 0],
            ]);
            $repository->storeChunkGraph($chunk, [
                'entities' => array_values($entityKeys),
                'relationships' => [
                    $relationship($source, $target, "{$source} relates to {$target} (pass {$index})."),
                ],
            ]);
        }

        return $knowledgeBase;
    }

    public function test_community_build_creates_hierarchical_louvain_levels(): void
    {
        config(['services.graph_rag.community_levels' => 2]);
        $knowledgeBase = $this->createChainedPairGraph();

        $response = $this->withToken('test-key')
            ->postJson("/api/knowledge-bases/{$knowledgeBase->id}/graph/rebuild-communities");

        $response->assertOk()
            ->assertJsonPath('communities', 6)
            ->assertJsonPath('levels', 2);

        $this->assertSame(4, $knowledgeBase->graphCommunities()->where('level', 0)->count());
        $this->assertSame(2, $knowledgeBase->graphCommunities()->where('level', 1)->count());
        // Every entity belongs to exactly one community per level.
        $this->assertDatabaseCount('graph_community_members', 16);

        $entityId = fn (string $name) => $knowledgeBase->graphEntities()->where('canonical_name', $name)->value('id');

        // Bridge entity N3: half of its relationship weight (5 of 10)
        // stays inside its level-0 pair {N3, N4}.
        $n3CommunityId = DB::table('graph_community_members')
            ->where('entity_id', $entityId('N3'))
            ->whereIn('community_id', $knowledgeBase->graphCommunities()->where('level', 0)->select('id'))
            ->value('community_id');
        $this->assertNotNull($n3CommunityId);
        $this->assertSame(
            0.5,
            (float) DB::table('graph_community_members')
                ->where('entity_id', $entityId('N3'))
                ->where('community_id', $n3CommunityId)
                ->value('membership_score'),
        );

        // Level-1 communities record which level-0 communities they
        // condensed.
        $upperHalf = $knowledgeBase->graphCommunities()
            ->where('level', 1)
            ->get()
            ->first(fn ($community) => $community->entities->pluck('canonical_name')->contains('N1'));
        $this->assertNotNull($upperHalf);
        $this->assertSame(4, $upperHalf->entities->count());
        $this->assertCount(2, $upperHalf->metadata['parent_communities']);
    }

    public function test_global_query_returns_community_level_information(): void
    {
        $knowledgeBase = $this->createTwoCommunityGraph();
        $this->withToken('test-key')->postJson("/api/knowledge-bases/{$knowledgeBase->id}/graph/rebuild-communities")->assertOk();

        $this->withToken('test-key')->postJson("/api/knowledge-bases/{$knowledgeBase->id}/query", [
            'question' => 'Summarize the Alice theme',
            'mode' => 'global',
            'community_top_k' => 1,
            'include_graph' => true,
        ])->assertOk()
            ->assertJsonPath('communities.0.level', 0);
    }
    public function test_rebuild_reuses_cached_summaries_without_model_calls(): void
    {
        $knowledgeBase = $this->createTwoCommunityGraph();

        app(CommunityBuildService::class)->rebuild($knowledgeBase);
        $chatCalls = $this->chatCallCount();
        $this->assertSame(2, $chatCalls);
        $firstTitles = $knowledgeBase->graphCommunities()->pluck('title')->all();

        // Unchanged graph: both communities hit the content-addressed
        // cache, so the second rebuild performs zero model calls yet
        // still restores identical communities under a new version.
        app(CommunityBuildService::class)->rebuild($knowledgeBase);

        $this->assertSame($chatCalls, $this->chatCallCount(), 'cached communities must not re-summarize');
        $this->assertEqualsCanonicalizing($firstTitles, $knowledgeBase->graphCommunities()->pluck('title')->all());
        $this->assertSame(2, $knowledgeBase->graphCommunities()->count());
    }

    public function test_graph_change_only_regenerates_the_affected_community(): void
    {
        $knowledgeBase = $this->createTwoCommunityGraph();

        app(CommunityBuildService::class)->rebuild($knowledgeBase);
        $this->assertSame(2, $this->chatCallCount());
        $beforeTitles = $knowledgeBase->graphCommunities()->pluck('title')->all();

        // A new disconnected component appears and the graph version
        // moves; its singleton community is new, while the two existing
        // communities keep their members and edges, so only the new
        // community may be summarized again.
        $document = $knowledgeBase->documents()->create(['title' => 'Gamma doc', 'status' => 'ready']);
        $chunk = $document->chunks()->create(['position' => 0, 'content' => 'Gamma exists.', 'embedding' => [1, 0]]);
        app(GraphRepository::class)->storeChunkGraph($chunk, [
            'entities' => [['key' => 'g', 'name' => 'Gamma', 'type' => 'Organization', 'description' => null, 'aliases' => []]],
            'relationships' => [],
        ]);
        app(GraphRepository::class)->invalidateCommunities($knowledgeBase->id);

        app(CommunityBuildService::class)->rebuild($knowledgeBase);

        $this->assertSame(3, $this->chatCallCount(), 'exactly one community should be re-summarized');
        $this->assertSame(3, $knowledgeBase->graphCommunities()->count());
        $titles = $knowledgeBase->graphCommunities()->pluck('title')->all();
        foreach ($beforeTitles as $title) {
            $this->assertContains($title, $titles);
        }
        $gamma = $knowledgeBase->graphEntities()->where('canonical_name', 'Gamma')->first();
        $this->assertNotNull($gamma);
        $gammaCommunityIds = DB::table('graph_community_members')->where('entity_id', $gamma->id)->pluck('community_id');
        $this->assertSame(1, $gammaCommunityIds->count());
    }

    private function chatCallCount(): int
    {
        return collect(Http::recorded())
            ->filter(fn ($pair) => str_ends_with($pair[0]->url(), '/chat/completions'))
            ->count();
    }
}
