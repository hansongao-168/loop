<?php

namespace App\Services;

use App\Models\GraphEntity;
use App\Models\KnowledgeBase;
use App\Services\Ai\LoopRouter;
use App\Support\CosineSimilarity;

class EntityResolutionService
{
    public function __construct(private LoopRouter $loop) {}

    /** @param array{name:string,type:string,description:?string,aliases:list<string>} $item */
    public function resolve(KnowledgeBase $knowledgeBase, array $item): GraphEntity
    {
        $normalizedName = $this->normalize($item['name']);
        $embedding = null;
        $entity = GraphEntity::query()
            ->where('knowledge_base_id', $knowledgeBase->id)
            ->where('type', $item['type'])
            ->where('normalized_name', $normalizedName)
            ->first();

        if (! $entity) {
            $matches = GraphEntity::query()
                ->where('knowledge_base_id', $knowledgeBase->id)
                ->where('type', $item['type'])
                ->get()
                ->filter(fn (GraphEntity $candidate) => collect($candidate->aliases ?? [])
                    ->contains(fn ($alias) => $this->normalize((string) $alias) === $normalizedName));

            // Ambiguous aliases are not merged automatically.
            $entity = $matches->count() === 1 ? $matches->first() : null;
        }

        if (! $entity && config('services.graph_rag.semantic_entity_resolution')) {
            $embedding = $this->loop->embed($this->embeddingText($item), null, [
                'task' => 'embed',
                'knowledge_base_id' => $knowledgeBase->id,
            ])->vector;
            $entity = $this->semanticMatch($knowledgeBase, $item['type'], $embedding);
        }

        if (! $entity) {
            if (config('services.graph_rag.semantic_entity_resolution') && $embedding === null) {
                $embedding = $this->loop->embed($this->embeddingText($item), null, [
                    'task' => 'embed',
                    'knowledge_base_id' => $knowledgeBase->id,
                ])->vector;
            }

            return GraphEntity::query()->firstOrCreate(
                [
                    'knowledge_base_id' => $knowledgeBase->id,
                    'type' => $item['type'],
                    'normalized_name' => $normalizedName,
                ],
                [
                    'canonical_name' => $item['name'],
                    'description' => $item['description'],
                    'aliases' => $item['aliases'],
                    'embedding' => $embedding,
                ],
            );
        }

        if (config('services.graph_rag.semantic_entity_resolution') && $entity->embedding === null) {
            $embedding ??= $this->loop->embed($this->embeddingText($item), null, [
                'task' => 'embed',
                'knowledge_base_id' => $knowledgeBase->id,
            ])->vector;
        }

        $aliases = array_merge($entity->aliases ?? [], $item['aliases']);
        if ($this->normalize($entity->canonical_name) !== $normalizedName) {
            $aliases[] = $item['name'];
        }
        $aliases = array_values(array_unique(array_filter(array_map('trim', $aliases))));

        $entity->update([
            'aliases' => $aliases,
            'description' => $entity->description ?: $item['description'],
            'embedding' => $entity->embedding ?? $embedding,
        ]);

        return $entity;
    }

    public function normalize(string $name): string
    {
        $name = mb_strtolower(trim($name));

        return preg_replace('/[\s\p{P}\p{S}]+/u', '', $name) ?? $name;
    }

    private function semanticMatch(KnowledgeBase $knowledgeBase, string $type, array $embedding): ?GraphEntity
    {
        $ranked = GraphEntity::query()
            ->where('knowledge_base_id', $knowledgeBase->id)
            ->where('type', $type)
            ->whereNotNull('embedding')
            ->get()
            ->map(fn (GraphEntity $candidate) => [
                'entity' => $candidate,
                'score' => CosineSimilarity::score($embedding, $candidate->embedding),
            ])
            ->sortByDesc('score')
            ->values();

        if ($ranked->isEmpty()) {
            return null;
        }

        $best = $ranked[0];
        $secondScore = $ranked[1]['score'] ?? -1.0;
        $threshold = min(max((float) config('services.graph_rag.entity_similarity_threshold', 0.92), -1), 1);
        $margin = max((float) config('services.graph_rag.entity_similarity_margin', 0.05), 0);

        return $best['score'] >= $threshold && ($best['score'] - $secondScore) >= $margin
            ? $best['entity']
            : null;
    }

    private function embeddingText(array $item): string
    {
        return implode("\n", array_filter([$item['name'], $item['type'], $item['description']]));
    }
}
