<?php

namespace App\Services;

use App\Models\GraphRelationship;
use App\Models\KnowledgeBase;
use App\Services\Ai\LoopRouter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CommunityBuildService
{
    public function __construct(
        private LoopRouter $loop,
        private CommunityDetectionService $detection,
    ) {}

    /** @return array{build_version:string,communities:int,levels:int} */
    public function rebuild(KnowledgeBase $knowledgeBase, ?int $expectedGraphVersion = null): array
    {
        $graphVersion = (int) $knowledgeBase->fresh()->graph_version;
        if ($expectedGraphVersion !== null && $graphVersion !== $expectedGraphVersion) {
            throw new \RuntimeException('Knowledge graph changed before community build started.');
        }
        $entities = $knowledgeBase->graphEntities()->get();
        $relationships = $knowledgeBase->graphRelationships()
            ->with('evidence:id,relationship_id,document_chunk_id,statement')
            ->get();

        // Louvain hierarchy: level 0 is the finest modularity partition,
        // each further level condenses the previous one. Partitions stop
        // as soon as condensation stops producing new groupings.
        $maxLevels = min(max((int) config('services.graph_rag.community_levels', 2), 1), 5);
        $partitions = $this->detection->hierarchicalPartitions(
            $entities->pluck('id')->all(),
            $relationships->map(fn (GraphRelationship $relationship) => [
                'source' => (int) $relationship->source_entity_id,
                'target' => (int) $relationship->target_entity_id,
                'weight' => max((float) $relationship->weight, 0.001),
            ])->all(),
            $maxLevels,
        );

        $buildVersion = (string) Str::uuid();
        $prepared = [];

        foreach ($partitions as $level => $partition) {
            $parentPartition = $level > 0 ? $partitions[$level - 1] : null;
            $groups = [];
            foreach ($partition as $entityId => $label) {
                $groups[$label][] = $entityId;
            }
            ksort($groups);

            foreach ($groups as $entityIds) {
                sort($entityIds);
                $componentEntities = $entities->whereIn('id', $entityIds)->values();
                $componentRelationships = $relationships->filter(fn (GraphRelationship $relationship) => in_array($relationship->source_entity_id, $entityIds, true)
                    && in_array($relationship->target_entity_id, $entityIds, true)
                )->values();
                $facts = $componentRelationships->flatMap(fn (GraphRelationship $relationship) => $relationship->evidence->pluck('statement')
                )->unique()->take(30)->values()->all();
                $entityNames = $componentEntities->pluck('canonical_name')->all();
                $summary = $this->summarize($entityNames, $facts);

                $prepared[] = [
                    'level' => $level,
                    'entity_ids' => $entityIds,
                    'membership' => $this->membershipScores($entityIds, $componentRelationships, $relationships),
                    'title' => $summary['title'],
                    'summary' => $summary['summary'],
                    'rank' => count($entityIds) + $componentRelationships->count(),
                    'embedding' => $this->loop->embed($summary['summary'], null, [
                        'task' => 'embed',
                        'knowledge_base_id' => $knowledgeBase->id,
                    ])->vector,
                    'metadata' => [
                        'entities_count' => count($entityIds),
                        'relationships_count' => $componentRelationships->count(),
                        'evidence_count' => count($facts),
                        // Traceability: which communities of the level
                        // below were condensed into this one.
                        'parent_communities' => $parentPartition === null
                            ? []
                            : array_values(array_unique(array_map(
                                fn (int $entityId) => $parentPartition[$entityId],
                                $entityIds,
                            ))),
                    ],
                ];
            }
        }

        DB::transaction(function () use ($knowledgeBase, $prepared, $buildVersion, $graphVersion) {
            $currentVersion = (int) KnowledgeBase::query()->whereKey($knowledgeBase->id)->value('graph_version');
            if ($currentVersion !== $graphVersion) {
                throw new \RuntimeException('Knowledge graph changed during community build.');
            }
            $knowledgeBase->graphCommunities()->delete();
            foreach ($prepared as $item) {
                $community = $knowledgeBase->graphCommunities()->create([
                    'level' => $item['level'],
                    'title' => $item['title'],
                    'summary' => $item['summary'],
                    'rank' => $item['rank'],
                    'embedding' => $item['embedding'],
                    'build_version' => $buildVersion,
                    'metadata' => $item['metadata'] + ['graph_version' => $graphVersion],
                ]);
                $members = [];
                foreach ($item['membership'] as $entityId => $score) {
                    $members[$entityId] = ['membership_score' => $score];
                }
                $community->entities()->attach($members);
            }
        });

        return [
            'build_version' => $buildVersion,
            'communities' => count($prepared),
            'levels' => count($partitions),
        ];
    }

    /**
     * Membership strength of each entity inside its community: the share
     * of the entity's total relationship weight that stays within the
     * community (1.0 = all edges internal, <1.0 marks bridge entities).
     * Entities without any relationships score 1.
     *
     * @param  list<int>  $entityIds
     * @param  iterable<GraphRelationship>  $internalRelationships
     * @param  iterable<GraphRelationship>  $allRelationships
     * @return array<int, float>
     */
    private function membershipScores(
        array $entityIds,
        iterable $internalRelationships,
        iterable $allRelationships,
    ): array {
        $internal = array_fill_keys($entityIds, 0.0);
        foreach ($internalRelationships as $relationship) {
            $weight = max((float) $relationship->weight, 0.001);
            if (array_key_exists($relationship->source_entity_id, $internal)) {
                $internal[$relationship->source_entity_id] += $weight;
            }
            if (array_key_exists($relationship->target_entity_id, $internal)) {
                $internal[$relationship->target_entity_id] += $weight;
            }
        }

        $total = [];
        foreach ($allRelationships as $relationship) {
            $weight = max((float) $relationship->weight, 0.001);
            foreach ([$relationship->source_entity_id, $relationship->target_entity_id] as $entityId) {
                if (array_key_exists($entityId, $internal)) {
                    $total[$entityId] = ($total[$entityId] ?? 0.0) + $weight;
                }
            }
        }

        $scores = [];
        foreach ($entityIds as $entityId) {
            $totalDegree = $total[$entityId] ?? 0.0;
            $score = $totalDegree > 0 ? $internal[$entityId] / $totalDegree : 1.0;
            $scores[$entityId] = round(min(max($score, 0.0), 9.9999), 4);
        }

        return $scores;
    }

    /** @return array{title:string,summary:string} */
    private function summarize(array $entityNames, array $facts): array
    {
        $result = $this->loop->chatStructured([
            [
                'role' => 'system',
                'content' => 'Summarize the supplied knowledge-graph community using only its entities and evidence. Return JSON: {"title":"short title","summary":"evidence-grounded summary"}. Do not add unsupported facts.',
            ],
            ['role' => 'user', 'content' => json_encode(['entities' => $entityNames, 'evidence' => $facts], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)],
        ], null, ['task' => 'summary']);

        $title = trim((string) ($result['title'] ?? ''));
        $summary = trim((string) ($result['summary'] ?? ''));
        if ($title === '' || $summary === '') {
            throw new \RuntimeException('Community summary response is incomplete.');
        }

        return ['title' => mb_substr($title, 0, 255), 'summary' => $summary];
    }
}
