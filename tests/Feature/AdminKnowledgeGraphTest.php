<?php

namespace Tests\Feature;

use App\Jobs\BuildGraphCommunities;
use App\Jobs\ProcessDocumentIndex;
use App\Models\GraphEntity;
use App\Models\KnowledgeBase;
use App\Services\GraphRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdminKnowledgeGraphTest extends TestCase
{
    use RefreshDatabase;

    public function test_knowledge_page_displays_graph_index_and_community_status(): void
    {
        $knowledgeBase = $this->createGraphFixture();
        $failed = $knowledgeBase->documents()->create([
            'title' => 'Failed document', 'source_content' => 'Retry', 'status' => 'failed',
            'index_version' => 2, 'failure_reason' => 'Embedding unavailable',
        ]);
        $knowledgeBase->graphCommunityBuilds()->create([
            'graph_version' => $knowledgeBase->fresh()->graph_version,
            'status' => 'failed',
            'failure_reason' => 'Graph changed',
        ]);

        $this->admin()->get(route('admin.bases.show', $knowledgeBase))
            ->assertOk()
            ->assertSee('知识图谱实体')
            ->assertSee('Alice');

        $this->admin()->get(route('admin.bases.show', $knowledgeBase))
            ->assertSee('Failed document')
            ->assertSee('Embedding unavailable')
            ->assertSee('社区构建')
            ->assertSee('Graph changed')
            ->assertSee(route('admin.documents.retry', $failed), false);
    }

    public function test_entity_page_displays_relationship_and_original_evidence(): void
    {
        $knowledgeBase = $this->createGraphFixture();
        $entity = $knowledgeBase->graphEntities()->where('canonical_name', 'Alice')->firstOrFail();

        $this->admin()->get(route('admin.entities.show', [$knowledgeBase, $entity]))
            ->assertOk()
            ->assertSee('Alice')
            ->assertSee('WORKS_FOR')
            ->assertSee('Acme')
            ->assertSee('Alice works for Acme.')
            ->assertSee('Evidence document');
    }

    public function test_entity_page_rejects_an_entity_from_another_knowledge_base(): void
    {
        $first = KnowledgeBase::create(['name' => 'First']);
        $second = KnowledgeBase::create(['name' => 'Second']);
        $entity = GraphEntity::create([
            'knowledge_base_id' => $second->id,
            'canonical_name' => 'Hidden',
            'normalized_name' => 'hidden',
            'type' => 'Concept',
        ]);

        $this->admin()->get(route('admin.entities.show', [$first, $entity]))->assertNotFound();
    }

    public function test_admin_can_queue_document_ingestion_retry_and_community_rebuild(): void
    {
        Queue::fake();
        $knowledgeBase = $this->createGraphFixture();

        $this->admin()->post(route('admin.documents.store', $knowledgeBase), [
            'title' => 'Queued document', 'content' => 'Queued content', 'async' => '1',
        ])->assertRedirect()->assertSessionHas('success');
        $queuedDocument = $knowledgeBase->documents()->where('title', 'Queued document')->firstOrFail();
        Queue::assertPushed(ProcessDocumentIndex::class, fn ($job) => $job->documentId === $queuedDocument->id);

        $queuedDocument->update(['status' => 'failed', 'failure_reason' => 'Temporary']);
        $this->admin()->post(route('admin.documents.retry', $queuedDocument))
            ->assertRedirect()->assertSessionHas('success');
        Queue::assertPushed(ProcessDocumentIndex::class, fn ($job) => $job->documentId === $queuedDocument->id && $job->indexVersion === 2);

        $this->admin()->post(route('admin.communities.rebuild', $knowledgeBase))
            ->assertRedirect()->assertSessionHas('success');
        Queue::assertPushed(BuildGraphCommunities::class);
        $this->assertDatabaseHas('graph_community_builds', ['knowledge_base_id' => $knowledgeBase->id, 'status' => 'pending']);
    }

    public function test_admin_pages_still_require_authentication(): void
    {
        $knowledgeBase = KnowledgeBase::create(['name' => 'Private']);

        $this->get(route('admin.bases.show', $knowledgeBase))->assertRedirect(route('admin.login'));
    }

    private function admin(): static
    {
        return $this->withSession(['admin_authenticated' => true]);
    }

    private function createGraphFixture(): KnowledgeBase
    {
        $knowledgeBase = KnowledgeBase::create(['name' => 'Graph workspace']);
        $document = $knowledgeBase->documents()->create([
            'title' => 'Evidence document', 'source_content' => 'Alice works for Acme.', 'status' => 'ready', 'index_version' => 1,
        ]);
        $chunk = $document->chunks()->create([
            'position' => 0, 'content' => 'Alice works for Acme.', 'embedding' => [1, 0],
        ]);
        app(GraphRepository::class)->storeChunkGraph($chunk, [
            'entities' => [
                ['key' => 'a', 'name' => 'Alice', 'type' => 'Person', 'description' => 'Engineer', 'aliases' => []],
                ['key' => 'b', 'name' => 'Acme', 'type' => 'Organization', 'description' => 'Company', 'aliases' => []],
            ],
            'relationships' => [[
                'source_key' => 'a', 'target_key' => 'b', 'type' => 'WORKS_FOR', 'description' => 'Employment',
                'statement' => 'Alice works for Acme.', 'confidence' => 0.99,
            ]],
        ]);

        return $knowledgeBase->fresh();
    }
}
