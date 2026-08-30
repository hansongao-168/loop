<?php

namespace App\Support;

/**
 * Cosine similarity for dense float vectors. Shared by the retrieval,
 * entity resolution and community search paths so the scoring rule is
 * defined exactly once.
 */
final class CosineSimilarity
{
    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    public static function score(array $a, array $b): float
    {
        if (count($a) !== count($b) || $a === []) {
            return -1.0;
        }

        $dot = $aa = $bb = 0.0;
        foreach ($a as $i => $value) {
            $dot += $value * $b[$i];
            $aa += $value ** 2;
            $bb += $b[$i] ** 2;
        }

        return ($aa > 0 && $bb > 0) ? $dot / (sqrt($aa) * sqrt($bb)) : -1.0;
    }
}
