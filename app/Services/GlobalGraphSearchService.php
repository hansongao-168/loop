<?php

namespace App\Services;

use App\Models\GraphCommunity;
use App\Models\GraphRelationship;
use App\Models\KnowledgeBase;
use App\Support\CosineSimilarity;

class GlobalGraphSearchService
{
    /** @return array{communities:list<array<string,mixed>>,chunk_ids:list<int>} */
    public function search(KnowledgeBase $knowledgeBase, array $queryVector, int $limit = 5): array
    {
        $communities = $knowledgeBase->graphCommunities()
            ->with('entities:id,canonical_name,type')
            ->get()
            ->map(fn (GraphCommunity $community) => [
                'community' => $community,
                'score' => CosineSimilarity::score($queryVector, $community->embedding ?? []),
            ])
            // Relevance first; ties resolve to the finer level (lower
            // level = more specific summary), then to the stable id.
            ->sort(function (array $a, array $b) {
                return $b['score'] <=> $a['score']
                    ?: $a['community']->level <=> $b['community']->level
                    ?: $a['community']->id <=> $b['community']->id;
            })
            ->take(min(max($limit, 1), 10))
            ->values();

        $entityIds = $communities->flatMap(fn ($item) => $item['community']->entities->pluck('id'))->unique()->all();
        $relationships = GraphRelationship::query()
            ->where('knowledge_base_id', $knowledgeBase->id)
            ->whereIn('source_entity_id', $entityIds)
            ->whereIn('target_entity_id', $entityIds)
            ->with('evidence:id,relationship_id,document_chunk_id')
            ->orderByDesc('confidence')
            ->get();

        return [
            'communities' => $communities->map(fn ($item) => [
                'id' => $item['community']->id,
                'level' => (int) $item['community']->level,
                'title' => $item['community']->title,
                'summary' => $item['community']->summary,
                'score' => round($item['score'], 5),
                'rank' => (float) $item['community']->rank,
                'entities' => $item['community']->entities->pluck('canonical_name')->all(),
            ])->all(),
            'chunk_ids' => $relationships->flatMap(fn ($relationship) => $relationship->evidence->pluck('document_chunk_id'))
                ->unique()->values()->all(),
        ];
    }
}
