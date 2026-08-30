<?php

namespace App\Services;

use App\Models\DocumentChunk;
use App\Models\GraphCommunity;
use App\Models\GraphEntity;
use App\Models\GraphMention;
use App\Models\GraphRelationship;
use App\Models\GraphRelationshipEvidence;
use App\Models\KnowledgeBase;
use Illuminate\Support\Facades\DB;

class GraphRepository
{
    public function __construct(private EntityResolutionService $entityResolution) {}

    /** @param array{entities:list<array<string, mixed>>, relationships:list<array<string, mixed>>} $graph */
    public function storeChunkGraph(DocumentChunk $chunk, array $graph): void
    {
        DB::transaction(function () use ($chunk, $graph) {
            $knowledgeBaseId = $chunk->document->knowledge_base_id;
            $entitiesByKey = [];

            foreach ($graph['entities'] as $item) {
                $normalizedName = $this->entityResolution->normalize($item['name']);
                if ($normalizedName === '') {
                    continue;
                }

                $entity = $this->entityResolution->resolve($chunk->document->knowledgeBase, $item);
                $entitiesByKey[$item['key']] = $entity;

                GraphMention::query()->firstOrCreate([
                    'entity_id' => $entity->id,
                    'document_chunk_id' => $chunk->id,
                    'surface_form' => $item['name'],
                ], ['confidence' => 1]);
            }

            foreach ($graph['relationships'] as $item) {
                $source = $entitiesByKey[$item['source_key']] ?? null;
                $target = $entitiesByKey[$item['target_key']] ?? null;
                if (! $source || ! $target) {
                    continue;
                }

                $relationship = GraphRelationship::query()->firstOrCreate(
                    [
                        'knowledge_base_id' => $knowledgeBaseId,
                        'source_entity_id' => $source->id,
                        'target_entity_id' => $target->id,
                        'type' => $item['type'],
                    ],
                    [
                        'description' => $item['description'],
                        'confidence' => $item['confidence'],
                    ],
                );

                GraphRelationshipEvidence::query()->updateOrCreate(
                    ['relationship_id' => $relationship->id, 'document_chunk_id' => $chunk->id],
                    ['statement' => $item['statement'], 'confidence' => $item['confidence']],
                );

                $relationship->update([
                    'weight' => $relationship->evidence()->count(),
                    'confidence' => max((float) $relationship->confidence, $item['confidence']),
                ]);
            }
        });
    }

    /**
     * Document-level community invalidation: drop every community of the
     * knowledge base and bump graph_version so in-flight community
     * builds fail their optimistic-version check. Called once per
     * indexing run (not per chunk) and after graph-affecting deletions.
     */
    public function invalidateCommunities(int $knowledgeBaseId): void
    {
        GraphCommunity::query()->where('knowledge_base_id', $knowledgeBaseId)->delete();
        KnowledgeBase::query()->whereKey($knowledgeBaseId)->increment('graph_version');
    }

    public function removeOrphans(int $knowledgeBaseId): void
    {
        DB::transaction(function () use ($knowledgeBaseId) {
            GraphCommunity::query()->where('knowledge_base_id', $knowledgeBaseId)->delete();
            KnowledgeBase::query()->whereKey($knowledgeBaseId)->increment('graph_version');
            GraphRelationship::query()
                ->where('knowledge_base_id', $knowledgeBaseId)
                ->whereDoesntHave('evidence')
                ->delete();

            GraphEntity::query()
                ->where('knowledge_base_id', $knowledgeBaseId)
                ->whereDoesntHave('mentions')
                ->whereDoesntHave('incomingRelationships')
                ->whereDoesntHave('outgoingRelationships')
                ->delete();
        });
    }
}
