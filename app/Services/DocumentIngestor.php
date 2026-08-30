<?php

namespace App\Services;

use App\Jobs\ProcessDocumentIndex;
use App\Models\Document;
use App\Models\KnowledgeBase;
use Illuminate\Support\Facades\DB;

/**
 * Owns the document ingestion workflows: synchronous and queued
 * ingestion plus failed-document retry. The indexing pipeline itself
 * lives in DocumentIndexer; this class coordinates document lifecycle
 * state and queue dispatch.
 */
class DocumentIngestor
{
    public function __construct(private DocumentIndexer $indexer) {}

    public function ingest(KnowledgeBase $knowledgeBase, array $input): Document
    {
        return DB::transaction(function () use ($knowledgeBase, $input) {
            $document = $this->createDocument($knowledgeBase, $input);
            $this->indexer->index($document);

            return $document->refresh()->loadCount('chunks');
        });
    }

    public function ingestAsync(KnowledgeBase $knowledgeBase, array $input): Document
    {
        $document = $this->createDocument($knowledgeBase, $input);
        ProcessDocumentIndex::dispatch($document->id, $document->index_version);

        return $document;
    }

    public function retryIndex(Document $document): Document
    {
        $document->update([
            'status' => 'pending',
            'index_version' => $document->index_version + 1,
            'indexed_at' => null,
            'failure_reason' => null,
        ]);
        ProcessDocumentIndex::dispatch($document->id, $document->index_version);

        return $document->refresh();
    }

    private function createDocument(KnowledgeBase $knowledgeBase, array $input): Document
    {
        return $knowledgeBase->documents()->create([
            'title' => $input['title'],
            'source' => $input['source'] ?? null,
            'metadata' => $input['metadata'] ?? null,
            'source_content' => $input['content'],
            'status' => 'pending',
            'index_version' => 1,
        ]);
    }
}
