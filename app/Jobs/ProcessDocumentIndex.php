<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\DocumentIndexer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class ProcessDocumentIndex implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    public function __construct(public int $documentId, public int $indexVersion) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping("document-index:{$this->documentId}"))->expireAfter($this->timeout + 60)];
    }

    public function handle(DocumentIndexer $indexer): void
    {
        $document = Document::query()->find($this->documentId);
        if (! $document || $document->index_version !== $this->indexVersion || $document->status === 'ready') {
            return;
        }

        try {
            $indexer->index($document);
        } catch (Throwable $exception) {
            $document->update([
                'status' => 'failed',
                'failure_reason' => mb_substr($exception->getMessage(), 0, 2000),
            ]);
            throw $exception;
        }
    }
}
