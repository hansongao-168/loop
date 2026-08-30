<?php

namespace App\Services;

use App\Models\Document;
use App\Services\Ai\LoopRouter;

/**
 * Owns the document indexing pipeline: chunking, embedding and graph
 * extraction. Single-purpose service consumed by the index job and by
 * DocumentIngestor, keeping the query pipeline separate.
 */
class DocumentIndexer
{
    public function __construct(
        private LoopRouter $loop,
        private TextChunker $chunker,
        private GraphExtractionService $graphExtractor,
        private GraphRepository $graphRepository,
    ) {}

    public function index(Document $document): void
    {
        if (! is_string($document->source_content) || trim($document->source_content) === '') {
            throw new \RuntimeException('Document has no source content to index.');
        }

        $document->update(['status' => 'chunking', 'failure_reason' => null]);
        $document->chunks()->delete();
        $this->graphRepository->removeOrphans($document->knowledge_base_id);

        foreach ($this->chunker->split($document->source_content) as $position => $content) {
            $document->update(['status' => 'embedding']);
            $chunk = $document->chunks()->create([
                'position' => $position,
                'content' => $content,
                'embedding' => $this->loop->embed($content, null, [
                    'task' => 'embed',
                    'document_id' => $document->id,
                    'knowledge_base_id' => $document->knowledge_base_id,
                ])->vector,
            ]);

            if (config('services.graph_rag.enabled')) {
                $document->update(['status' => 'extracting']);
                $this->graphRepository->storeChunkGraph($chunk, $this->graphExtractor->extract($content));
            }
        }

        // This document's graph facts are final now: drop any communities
        // built against a mid-index graph_version so stale summaries can
        // not survive the indexing run.
        $this->graphRepository->invalidateCommunities($document->knowledge_base_id);

        $document->update(['status' => 'ready', 'indexed_at' => now(), 'failure_reason' => null]);
    }
}
