<?php

namespace App\Services;

class RetrievalFusionService
{
    /**
     * @param  list<array{chunk:mixed,score:float}>  $vectorResults
     * @param  array<string,list<int>>  $rankedChannels
     * @return list<array{chunk:mixed,score:?float,retrieval_score:float,channels:list<string>}>
     */
    public function fuse(array $vectorResults, array $rankedChannels, int $limit): array
    {
        $rankConstant = 60;
        $results = [];

        foreach ($vectorResults as $rank => $item) {
            $id = $item['chunk']->id;
            $results[$id] = [
                'chunk' => $item['chunk'],
                'score' => $item['score'],
                'retrieval_score' => 1 / ($rankConstant + $rank + 1),
                'channels' => ['vector'],
            ];
        }

        foreach ($rankedChannels as $channel => $chunkIds) {
            foreach ($chunkIds as $rank => $chunkId) {
                if (! isset($results[$chunkId])) {
                    continue;
                }
                $results[$chunkId]['retrieval_score'] += 1 / ($rankConstant + $rank + 1);
                $results[$chunkId]['channels'][] = $channel;
            }
        }

        usort($results, fn ($left, $right) => $right['retrieval_score'] <=> $left['retrieval_score']);

        return array_slice(array_values($results), 0, $limit);
    }
}
