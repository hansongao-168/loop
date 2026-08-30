<?php

namespace App\Services;

use App\Models\GraphEntity;
use App\Models\GraphRelationship;
use App\Models\KnowledgeBase;

class LocalGraphSearchService
{
    /**
     * @return array{
     *   entities:list<array{id:int,name:string,type:string}>,
     *   relationships:list<array<string,mixed>>,
     *   chunk_ids:list<int>
     * }
     */
    public function search(KnowledgeBase $knowledgeBase, string $question, int $maxHops = 2): array
    {
        $maxHops = min(max($maxHops, 1), 2);
        $maxNodes = max(5, (int) config('services.graph_rag.max_nodes', 50));
        $normalizedQuestion = $this->normalize($question);

        $seedIds = GraphEntity::query()
            ->where('knowledge_base_id', $knowledgeBase->id)
            ->get(['id', 'canonical_name', 'aliases'])
            ->filter(function (GraphEntity $entity) use ($normalizedQuestion) {
                $names = array_merge([$entity->canonical_name], $entity->aliases ?? []);

                return collect($names)->contains(function ($name) use ($normalizedQuestion) {
                    $normalizedName = $this->normalize((string) $name);

                    return mb_strlen($normalizedName) >= 2 && str_contains($normalizedQuestion, $normalizedName);
                });
            })
            ->pluck('id')
            ->take($maxNodes)
            ->values()
            ->all();

        if ($seedIds === []) {
            return ['entities' => [], 'relationships' => [], 'chunk_ids' => []];
        }

        $visitedEntityIds = array_fill_keys($seedIds, true);
        $frontier = $seedIds;
        $relationships = collect();

        for ($hop = 0; $hop < $maxHops && $frontier !== [] && count($visitedEntityIds) < $maxNodes; $hop++) {
            $hopRelationships = GraphRelationship::query()
                ->where('knowledge_base_id', $knowledgeBase->id)
                ->where(function ($query) use ($frontier) {
                    $query->whereIn('source_entity_id', $frontier)
                        ->orWhereIn('target_entity_id', $frontier);
                })
                ->with([
                    'sourceEntity:id,canonical_name,type',
                    'targetEntity:id,canonical_name,type',
                    'evidence:id,relationship_id,document_chunk_id,statement,confidence',
                ])
                ->orderByDesc('confidence')
                ->limit($maxNodes * 2)
                ->get();

            $nextFrontier = [];
            foreach ($hopRelationships as $relationship) {
                $relationships->put($relationship->id, $relationship);
                foreach ([$relationship->source_entity_id, $relationship->target_entity_id] as $entityId) {
                    if (! isset($visitedEntityIds[$entityId]) && count($visitedEntityIds) < $maxNodes) {
                        $visitedEntityIds[$entityId] = true;
                        $nextFrontier[] = $entityId;
                    }
                }
            }
            $frontier = array_values(array_unique($nextFrontier));
        }

        $entities = GraphEntity::query()
            ->where('knowledge_base_id', $knowledgeBase->id)
            ->whereIn('id', array_keys($visitedEntityIds))
            ->get(['id', 'canonical_name', 'type'])
            ->map(fn (GraphEntity $entity) => [
                'id' => $entity->id,
                'name' => $entity->canonical_name,
                'type' => $entity->type,
            ])->values();

        $serializedRelationships = $relationships->values()->map(fn (GraphRelationship $relationship) => [
            'id' => $relationship->id,
            'type' => $relationship->type,
            'source' => $relationship->sourceEntity->canonical_name,
            'target' => $relationship->targetEntity->canonical_name,
            'confidence' => (float) $relationship->confidence,
            'evidence' => $relationship->evidence->map(fn ($evidence) => [
                'chunk_id' => $evidence->document_chunk_id,
                'statement' => $evidence->statement,
                'confidence' => (float) $evidence->confidence,
            ])->values()->all(),
        ]);

        return [
            'entities' => $entities->all(),
            'relationships' => $serializedRelationships->all(),
            'chunk_ids' => $serializedRelationships->pluck('evidence')->flatten(1)->pluck('chunk_id')->unique()->values()->all(),
        ];
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));

        return preg_replace('/[\s\p{P}\p{S}]+/u', '', $value) ?? $value;
    }
}
