<?php

namespace App\Support;

/**
 * Text-level heuristics about model answers. Shared by the query
 * pipeline (citation retry gate) and the evaluation service so both
 * judge answers with exactly the same rules.
 */
final class AnswerInsights
{
    private const ABSTENTION_PHRASES = [
        'insufficient context', 'not enough information', 'cannot answer', 'unable to answer',
        'does not mention', 'no information', 'not mentioned', 'does not contain', 'not contain',
        '资料不足', '信息不足', '无法回答', '不能回答', '没有足够', '未找到相关',
        '没有提到', '未提及', '没有相关', '未提供', '不包含', '未包含', '不含', '没有相关的', '没有提及',
    ];

    /**
     * Distinct [n] citation numbers referenced by the answer.
     *
     * @return list<int>
     */
    public static function citations(string $answer): array
    {
        preg_match_all('/\[(\d+)]/', $answer, $matches);

        return array_values(array_unique(array_map('intval', $matches[1] ?? [])));
    }

    public static function hasCitations(string $answer): bool
    {
        return self::citations($answer) !== [];
    }

    /**
     * True when the answer reads as "the knowledge base does not contain
     * this" rather than a substantive answer.
     */
    public static function isAbstention(string $answer): bool
    {
        if ($answer === '') {
            return true;
        }

        $normalized = mb_strtolower($answer);

        return collect(self::ABSTENTION_PHRASES)->contains(fn (string $phrase) => str_contains($normalized, $phrase));
    }
}
