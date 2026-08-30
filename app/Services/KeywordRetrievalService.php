<?php

namespace App\Services;

use App\Models\DocumentChunk;

class KeywordRetrievalService
{
    /**
     * @param  iterable<DocumentChunk>  $chunks
     * @return list<int>
     */
    public function rank(iterable $chunks, string $question): array
    {
        $terms = $this->terms($question);
        if ($terms === []) {
            return [];
        }

        $ranked = [];
        foreach ($chunks as $chunk) {
            $content = mb_strtolower($chunk->content);
            $score = 0.0;
            foreach ($terms as $term) {
                $occurrences = mb_substr_count($content, $term);
                if ($occurrences > 0) {
                    $score += (1 + log($occurrences)) * min(mb_strlen($term), 20);
                }
            }
            if ($score > 0) {
                $ranked[] = ['id' => $chunk->id, 'score' => $score];
            }
        }

        usort($ranked, fn ($left, $right) => $right['score'] <=> $left['score']);

        return array_column($ranked, 'id');
    }

    /** @return list<string> */
    private function terms(string $question): array
    {
        preg_match_all('/[\p{Han}]{2,}|[\p{L}\p{N}][\p{L}\p{N}_-]+/u', mb_strtolower($question), $matches);
        $stopWords = ['what', 'where', 'when', 'which', 'who', 'does', 'the', 'and', 'for', 'with', 'from', 'that', 'this'];

        return array_values(array_unique(array_filter(
            $matches[0] ?? [],
            fn ($term) => ! in_array($term, $stopWords, true),
        )));
    }
}
