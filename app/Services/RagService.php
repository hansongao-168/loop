<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\KnowledgeBase;
use Illuminate\Support\Facades\DB;

class RagService
{
    public function __construct(private AiClient $ai, private TextChunker $chunker) {}

    public function ingest(KnowledgeBase $knowledgeBase, array $input): Document
    {
        return DB::transaction(function () use ($knowledgeBase, $input) {
            $document = $knowledgeBase->documents()->create([
                'title' => $input['title'],
                'source' => $input['source'] ?? null,
                'metadata' => $input['metadata'] ?? null,
                'status' => 'processing',
            ]);

            foreach ($this->chunker->split($input['content']) as $position => $content) {
                $document->chunks()->create([
                    'position' => $position,
                    'content' => $content,
                    'embedding' => $this->ai->embed($content),
                ]);
            }

            $document->update(['status' => 'ready']);
            return $document->loadCount('chunks');
        });
    }

    public function ask(KnowledgeBase $knowledgeBase, string $question, array $options = []): array
    {
        $queryVector = $this->ai->embed($question);
        $topK = min(max((int) ($options['top_k'] ?? config('services.ai.top_k')), 1), 20);
        $chunks = DocumentChunk::query()
            ->with('document:id,title,source')
            ->whereHas('document', fn ($q) => $q->where('knowledge_base_id', $knowledgeBase->id))
            ->get()
            ->map(fn (DocumentChunk $chunk) => [
                'chunk' => $chunk,
                'score' => $this->cosine($queryVector, $chunk->embedding),
            ])->sortByDesc('score')->take($topK)->values();

        $context = $chunks->map(fn ($item, $i) => sprintf(
            "[%d] %s\n%s", $i + 1, $item['chunk']->document->title, $item['chunk']->content
        ))->implode("\n\n");

        $result = $this->ai->chat([
            ['role' => 'system', 'content' => 'Answer only from the supplied context. Cite sources as [1], [2]. If context is insufficient, say so clearly.'],
            ['role' => 'user', 'content' => "Context:\n{$context}\n\nQuestion: {$question}"],
        ], $options['model'] ?? null, (float) ($options['temperature'] ?? 0.2));

        return [
            'answer' => data_get($result, 'choices.0.message.content'),
            'model' => $result['model'] ?? ($options['model'] ?? config('services.ai.chat_model')),
            'sources' => $chunks->map(fn ($item, $i) => [
                'index' => $i + 1,
                'document_id' => $item['chunk']->document_id,
                'title' => $item['chunk']->document->title,
                'source' => $item['chunk']->document->source,
                'score' => round($item['score'], 5),
                'excerpt' => mb_substr($item['chunk']->content, 0, 240),
            ])->all(),
            'usage' => $result['usage'] ?? null,
        ];
    }

    private function cosine(array $a, array $b): float
    {
        if (count($a) !== count($b) || $a === []) return -1.0;
        $dot = $aa = $bb = 0.0;
        foreach ($a as $i => $value) {
            $dot += $value * $b[$i]; $aa += $value ** 2; $bb += $b[$i] ** 2;
        }
        return ($aa > 0 && $bb > 0) ? $dot / (sqrt($aa) * sqrt($bb)) : -1.0;
    }
}
