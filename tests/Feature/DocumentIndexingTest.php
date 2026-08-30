<?php

namespace Tests\Feature;

use App\Jobs\ProcessDocumentIndex;
use App\Models\KnowledgeBase;
use App\Services\Ai\Exceptions\ProviderUnavailableException;
use App\Services\DocumentIndexer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DocumentIndexingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.ai.api_key' => 'test-key',
            'services.graph_rag.enabled' => false,
        ]);
    }

    public function test_async_ingestion_creates_a_pending_document_and_dispatches_a_versioned_job(): void
    {
        Queue::fake();
        $knowledgeBase = KnowledgeBase::create(['name' => 'Test']);

        $response = $this->withToken('test-key')->postJson("/api/knowledge-bases/{$knowledgeBase->id}/documents", [
            'title' => 'Queued', 'content' => 'Content to index.', 'async' => true,
        ]);

        $response->assertAccepted()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('index_version', 1)
            ->assertJsonMissingPath('source_content');
        $documentId = $response->json('id');
        $this->assertDatabaseHas('documents', [
            'id' => $documentId, 'status' => 'pending', 'source_content' => 'Content to index.',
        ]);
        Queue::assertPushed(ProcessDocumentIndex::class, fn ($job) => $job->documentId === $documentId && $job->indexVersion === 1);
    }

    public function test_status_endpoint_is_scoped_to_its_knowledge_base(): void
    {
        $first = KnowledgeBase::create(['name' => 'First']);
        $second = KnowledgeBase::create(['name' => 'Second']);
        $document = $second->documents()->create([
            'title' => 'Hidden', 'source_content' => 'Secret', 'status' => 'pending', 'index_version' => 1,
        ]);

        $this->withToken('test-key')->getJson("/api/knowledge-bases/{$first->id}/documents/{$document->id}/index-status")
            ->assertNotFound();
        $this->withToken('test-key')->getJson("/api/knowledge-bases/{$second->id}/documents/{$document->id}/index-status")
            ->assertOk()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('chunks_count', 0)
            ->assertJsonMissingPath('source_content');
    }

    public function test_only_failed_documents_can_be_retried_and_retry_increments_the_version(): void
    {
        Queue::fake();
        $knowledgeBase = KnowledgeBase::create(['name' => 'Test']);
        $document = $knowledgeBase->documents()->create([
            'title' => 'Failed', 'source_content' => 'Retry me', 'status' => 'failed',
            'index_version' => 2, 'failure_reason' => 'Temporary failure',
        ]);

        $this->withToken('test-key')->postJson("/api/knowledge-bases/{$knowledgeBase->id}/documents/{$document->id}/retry-index")
            ->assertAccepted()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('index_version', 3)
            ->assertJsonPath('failure_reason', null);

        Queue::assertPushed(ProcessDocumentIndex::class, fn ($job) => $job->indexVersion === 3);
        $document->refresh()->update(['status' => 'ready']);
        $this->withToken('test-key')->postJson("/api/knowledge-bases/{$knowledgeBase->id}/documents/{$document->id}/retry-index")
            ->assertConflict();
    }

    public function test_job_records_a_safe_failure_reason_when_indexing_fails(): void
    {
        Http::fake(['*/embeddings' => Http::response(['message' => 'upstream unavailable'], 503)]);
        $knowledgeBase = KnowledgeBase::create(['name' => 'Test']);
        $document = $knowledgeBase->documents()->create([
            'title' => 'Failure', 'source_content' => 'Will fail', 'status' => 'pending', 'index_version' => 1,
        ]);

        try {
            (new ProcessDocumentIndex($document->id, 1))->handle(app(DocumentIndexer::class));
            $this->fail('Expected the upstream request to fail.');
        } catch (ProviderUnavailableException) {
            // The queue worker will retry the job; the state remains inspectable meanwhile.
        }

        $document->refresh();
        $this->assertSame('failed', $document->status);
        $this->assertStringContainsString('503', $document->failure_reason);
    }

    public function test_stale_version_jobs_do_not_modify_the_document(): void
    {
        Http::fake();
        $knowledgeBase = KnowledgeBase::create(['name' => 'Test']);
        $document = $knowledgeBase->documents()->create([
            'title' => 'Current', 'source_content' => 'Current content', 'status' => 'pending', 'index_version' => 2,
        ]);

        (new ProcessDocumentIndex($document->id, 1))->handle(app(DocumentIndexer::class));

        $this->assertSame('pending', $document->refresh()->status);
        Http::assertNothingSent();
    }
}
