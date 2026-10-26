<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\GraphEntity;
use App\Models\GraphRelationship;
use App\Models\KnowledgeBase;
use App\Services\CommunityDetectionService;
use App\Services\KeywordRetrievalService;
use App\Services\RetrievalFusionService;
use App\Support\CosineSimilarity;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Measures the retrieval hot paths against synthetic graph/text data so
 * the pgvector / graph-database migration threshold is a measured
 * number instead of a guess.
 *
 * The benchmark does not call any model: it times the CPU-bound work
 * that grows with corpus size (full chunk scan + cosine scoring,
 * keyword ranking, RRF fusion, community detection, seed matching).
 * Synthetic rows are written into a dedicated knowledge base and
 * removed afterwards unless --keep is passed.
 */
class BenchmarkCapacity extends Command
{
    protected $signature = 'rag:benchmark
        {--chunks=20000 : Synthetic chunk count}
        {--entities=2000 : Synthetic graph entity count}
        {--relationships=6000 : Synthetic relationship count}
        {--dimensions=768 : Embedding dimensions}
        {--iterations=3 : Timed repetitions per measurement}
        {--keep : Keep the synthetic knowledge base for inspection}';

    protected $description = 'Benchmark retrieval hot paths against synthetic data to find migration thresholds';

    public function handle(): int
    {
        $chunks = max(10, (int) $this->option('chunks'));
        $entities = max(4, (int) $this->option('entities'));
        $relationships = max(1, (int) $this->option('relationships'));
        $dimensions = max(4, (int) $this->option('dimensions'));
        $iterations = max(1, (int) $this->option('iterations'));

        $this->info(sprintf(
            'Synthetic corpus: %d chunks × %d dims, %d entities, %d relationships',
            $chunks, $dimensions, $entities, $relationships,
        ));

        $knowledgeBase = $this->seed($chunks, $entities, $relationships, $dimensions);

        try {
            $queryVector = $this->randomVector($dimensions);
            $rows = [];

            $rows[] = $this->measure('chunk scan + cosine sort', $iterations, function () use ($knowledgeBase, $queryVector) {
                return DocumentChunk::query()
                    ->with('document:id,title,source')
                    ->whereHas('document', fn ($q) => $q->where('knowledge_base_id', $knowledgeBase->id))
                    ->get()
                    ->map(fn (DocumentChunk $chunk) => [
                        'chunk' => $chunk,
                        'score' => CosineSimilarity::score($queryVector, $chunk->embedding),
                    ])->sortByDesc('score')->values()->all();
            });

            $vectorResults = DocumentChunk::query()
                ->with('document:id,title,source')
                ->whereHas('document', fn ($q) => $q->where('knowledge_base_id', $knowledgeBase->id))
                ->limit(200)
                ->get()
                ->map(fn (DocumentChunk $chunk) => [
                    'chunk' => $chunk,
                    'score' => CosineSimilarity::score($queryVector, $chunk->embedding),
                ])->all();

            $keyword = app(KeywordRetrievalService::class);

            $rows[] = $this->measure('keyword ranking (200 candidates)', $iterations, function () use ($keyword, $vectorResults) {
                return $keyword->rank(array_map(fn ($item) => $item['chunk'], $vectorResults), 'alpha beta gamma');
            });

            $embedding = app(RetrievalFusionService::class);

            $rows[] = $this->measure('RRF fusion (rrf over 3 channels)', $iterations, function () use ($embedding, $vectorResults) {
                return $embedding->fuse($vectorResults, [
                    'keyword' => array_map(fn ($item) => $item['chunk']->id, array_slice($vectorResults, 0, 100)),
                    'graph' => array_map(fn ($item) => $item['chunk']->id, array_slice($vectorResults, 50, 100)),
                ], 20);
            });

            $local = app(\App\Services\LocalGraphSearchService::class);

            $rows[] = $this->measure('entity seed match + 2-hop expansion', $iterations, function () use ($local, $knowledgeBase) {
                return $local->search($knowledgeBase, 'find Alpha-17 and Beta-42 relationships', 2);
            });

            $detection = app(CommunityDetectionService::class);
            $nodeIds = GraphEntity::query()->where('knowledge_base_id', $knowledgeBase->id)->pluck('id')->all();
            $edges = GraphRelationship::query()
                ->where('knowledge_base_id', $knowledgeBase->id)
                ->get(['source_entity_id', 'target_entity_id', 'weight'])
                ->map(fn ($r) => [
                    'source' => (int) $r->source_entity_id,
                    'target' => (int) $r->target_entity_id,
                    'weight' => (float) $r->weight,
                ])->all();

            $rows[] = $this->measure('Leiden detection (2 levels)', max(1, (int) floor($iterations / 2)), function () use ($detection, $nodeIds, $edges) {
                return $detection->hierarchicalPartitions($nodeIds, $edges, 2);
            });

            $this->table(['Measurement', 'Mean', 'Min', 'Max'], $rows);
            $this->newLine();
            $this->comment('Numbers are CPU-only (no model calls). P95 query latency = these paths + one embed + one answer call.');

            return self::SUCCESS;
        } finally {
            if (! $this->option('keep')) {
                $this->teardown($knowledgeBase);
            } else {
                $this->warn("Kept knowledge base #{$knowledgeBase->id} (--keep).");
            }
        }
    }

    /**
     * @return array{0:string,1:string,2:string,3:string}
     */
    private function measure(string $label, int $iterations, callable $work): array
    {
        $samples = [];
        for ($i = 0; $i < $iterations; $i++) {
            $startedAt = microtime(true);
            $work();
            $samples[] = (microtime(true) - $startedAt) * 1000;
        }

        return [
            $label,
            sprintf('%.1f ms', array_sum($samples) / count($samples)),
            sprintf('%.1f ms', min($samples)),
            sprintf('%.1f ms', max($samples)),
        ];
    }

    private function seed(int $chunks, int $entities, int $relationships, int $dimensions): KnowledgeBase
    {
        $knowledgeBase = KnowledgeBase::create(['name' => 'benchmark '.Str::random(6)]);
        $document = $knowledgeBase->documents()->create([
            'title' => 'Synthetic corpus',
            'source_content' => 'benchmark',
            'status' => 'ready',
            'index_version' => 1,
        ]);

        $chunkRows = [];
        $now = now();
        for ($i = 0; $i < $chunks; $i++) {
            $chunkRows[] = [
                'document_id' => $document->id,
                'position' => $i,
                'content' => 'alpha beta gamma synthetic slice '.$i.' with filler text for keyword ranking',
                'embedding' => json_encode($this->randomVector($dimensions)),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($chunkRows) >= 500) {
                DocumentChunk::query()->insert($chunkRows);
                $chunkRows = [];
            }
        }
        if ($chunkRows !== []) {
            DocumentChunk::query()->insert($chunkRows);
        }

        $entityIds = [];
        $entityRows = [];
        for ($i = 0; $i < $entities; $i++) {
            $entityRows[] = [
                'knowledge_base_id' => $knowledgeBase->id,
                'canonical_name' => $i % 2 === 0 ? 'Alpha-'.$i : 'Beta-'.$i,
                'normalized_name' => $i % 2 === 0 ? 'alpha-'.$i : 'beta-'.$i,
                'type' => $i % 2 === 0 ? 'Person' : 'Organization',
                'description' => null,
                'aliases' => json_encode([]),
                'metadata' => json_encode([]),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($entityRows, 500) as $batch) {
            GraphEntity::query()->insert($batch);
        }
        $entityIds = GraphEntity::query()->where('knowledge_base_id', $knowledgeBase->id)->pluck('id')->all();

        $relationshipRows = [];
        $entityCount = count($entityIds);
        for ($i = 0; $i < $relationships; $i++) {
            $source = $entityIds[$i % $entityCount];
            // Neighbour-biased wiring produces realistic clustered graphs.
            $target = $entityIds[($i + 1 + ($i % 7)) % $entityCount];
            $relationshipRows[] = [
                'knowledge_base_id' => $knowledgeBase->id,
                'source_entity_id' => $source,
                'target_entity_id' => $target,
                'type' => 'RELATES',
                'description' => null,
                'weight' => 1 + ($i % 5),
                'confidence' => 0.9,
                'metadata' => json_encode([]),
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if (count($relationshipRows) >= 500) {
                GraphRelationship::query()->insert($relationshipRows);
                $relationshipRows = [];
            }
        }
        if ($relationshipRows !== []) {
            GraphRelationship::query()->insert($relationshipRows);
        }

        return $knowledgeBase;
    }

    /** @return list<float> */
    private function randomVector(int $dimensions): array
    {
        $vector = [];
        for ($i = 0; $i < $dimensions; $i++) {
            $vector[] = mt_rand() / mt_getrandmax();
        }

        return $vector;
    }

    private function teardown(KnowledgeBase $knowledgeBase): void
    {
        $documentIds = Document::query()->where('knowledge_base_id', $knowledgeBase->id)->pluck('id');
        DocumentChunk::query()->whereIn('document_id', $documentIds)->delete();
        Document::query()->whereIn('id', $documentIds)->delete();
        GraphRelationship::query()->where('knowledge_base_id', $knowledgeBase->id)->delete();
        GraphEntity::query()->where('knowledge_base_id', $knowledgeBase->id)->delete();
        $knowledgeBase->delete();
        $this->line('Synthetic data removed.');
    }
}
