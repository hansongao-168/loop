<?php

namespace App\Services;

use App\Models\KnowledgeBase;
use App\Support\AnswerInsights;

class RagEvaluationService
{
    public function __construct(private RagQueryService $queries) {}

    /** @param array{name?:string,cases:list<array<string,mixed>>} $dataset */
    public function evaluate(KnowledgeBase $knowledgeBase, array $dataset, ?int $maxCases = null): array
    {
        $cases = $dataset['cases'] ?? null;
        if (! is_array($cases) || $cases === []) {
            throw new \InvalidArgumentException('Evaluation dataset must contain a non-empty cases array.');
        }
        if ($maxCases !== null) {
            $cases = array_slice($cases, 0, max(1, $maxCases));
        }

        $results = [];
        foreach ($cases as $index => $case) {
            if (! is_array($case) || ! is_string($case['question'] ?? null) || trim($case['question']) === '') {
                throw new \InvalidArgumentException('Every evaluation case must contain a non-empty question.');
            }
            $expectedSources = is_array($case['expected_sources'] ?? null) ? $case['expected_sources'] : [];
            $options = array_filter([
                'mode' => $case['mode'] ?? 'auto',
                'top_k' => $case['top_k'] ?? null,
                'max_hops' => $case['max_hops'] ?? null,
                'community_top_k' => $case['community_top_k'] ?? null,
            ], fn ($value) => $value !== null);
            $startedAt = microtime(true);
            $response = $this->queries->ask($knowledgeBase, $case['question'], $options);
            $sources = $response['sources'] ?? [];
            $answer = is_string($response['answer'] ?? null) ? trim($response['answer']) : '';
            $ranks = $this->relevantRanks($sources, $expectedSources);
            $expectedCount = count($expectedSources);
            $citations = AnswerInsights::citations($answer);
            $isAbstention = AnswerInsights::isAbstention($answer);
            $expectedAnswerable = array_key_exists('answerable', $case) ? (bool) $case['answerable'] : null;
            $expectedTerms = is_array($case['expected_answer_contains'] ?? null) ? $case['expected_answer_contains'] : [];

            $results[] = [
                'id' => (string) ($case['id'] ?? $index + 1),
                'question' => $case['question'],
                'answer_excerpt' => mb_substr((string) ($response['answer'] ?? ''), 0, 160),
                'expected_mode' => $case['expected_mode'] ?? null,
                'actual_mode' => $response['mode'] ?? null,
                'mode_correct' => isset($case['expected_mode']) ? $response['mode'] === $case['expected_mode'] : null,
                'recall_at_k' => $expectedCount === 0 ? null : count($ranks) / $expectedCount,
                // Cases without expected sources judge answer content
                // only — they carry no ranking expectation and must not
                // drag MRR down with a synthetic zero.
                'reciprocal_rank' => $expectedCount === 0 ? null : ($ranks === [] ? 0.0 : 1 / min($ranks)),
                'answer_present' => $answer !== '',
                'source_present' => $sources !== [],
                'citations' => $citations,
                'citation_valid' => $this->citationsAreValid($citations, count($sources), $expectedAnswerable),
                'expected_answerable' => $expectedAnswerable,
                'is_abstention' => $isAbstention,
                'abstention_correct' => $expectedAnswerable === null ? null : ($expectedAnswerable ? ! $isAbstention : $isAbstention),
                'answer_term_coverage' => $this->answerTermCoverage($answer, $expectedTerms),
                'expected_sources' => $expectedSources,
                'actual_sources' => array_map(fn ($source) => [
                    'document_id' => $source['document_id'] ?? null,
                    'title' => $source['title'] ?? null,
                    'source' => $source['source'] ?? null,
                    'channels' => $source['channels'] ?? [],
                ], $sources),
                'latency_ms' => round((microtime(true) - $startedAt) * 1000, 2),
            ];
        }

        return [
            'dataset' => $dataset['name'] ?? 'unnamed',
            'knowledge_base_id' => $knowledgeBase->id,
            'generated_at' => now()->toIso8601String(),
            'summary' => $this->summarize($results),
            'cases' => $results,
        ];
    }

    private function relevantRanks(array $sources, array $expectedSources): array
    {
        $matched = [];
        foreach ($sources as $rank => $source) {
            foreach ($expectedSources as $expectedIndex => $expected) {
                if (isset($matched[$expectedIndex])) {
                    continue;
                }
                $matches = is_numeric($expected)
                    ? (int) $expected === (int) ($source['document_id'] ?? 0)
                    : (is_string($expected) && ($expected === ($source['title'] ?? null) || $expected === ($source['source'] ?? null)));
                if ($matches) {
                    $matched[$expectedIndex] = $rank + 1;
                    break;
                }
            }
        }

        return array_values($matched);
    }

    private function citationsAreValid(array $citations, int $sourceCount, ?bool $expectedAnswerable): ?bool
    {
        if ($expectedAnswerable === false) {
            return null;
        }
        if ($sourceCount === 0) {
            return $citations === [];
        }

        return $citations !== [] && collect($citations)->every(fn ($citation) => $citation >= 1 && $citation <= $sourceCount);
    }

    private function answerTermCoverage(string $answer, array $expectedTerms): ?float
    {
        $terms = array_values(array_filter(array_map(fn ($term) => trim((string) $term), $expectedTerms)));
        if ($terms === []) {
            return null;
        }
        $normalized = mb_strtolower($answer);
        $matched = count(array_filter($terms, fn ($term) => str_contains($normalized, mb_strtolower($term))));

        return $matched / count($terms);
    }

    private function summarize(array $results): array
    {
        $recalls = array_values(array_filter(array_column($results, 'recall_at_k'), fn ($value) => $value !== null));
        $ranks = array_values(array_filter(array_column($results, 'reciprocal_rank'), fn ($value) => $value !== null));
        $modeCases = array_values(array_filter($results, fn ($result) => $result['mode_correct'] !== null));
        $citationCases = array_values(array_filter($results, fn ($result) => $result['citation_valid'] !== null));
        $abstentionCases = array_values(array_filter($results, fn ($result) => $result['abstention_correct'] !== null));
        $answerTermCases = array_values(array_filter(array_column($results, 'answer_term_coverage'), fn ($value) => $value !== null));

        return [
            'cases' => count($results),
            'recall_at_k' => $recalls === [] ? null : round(array_sum($recalls) / count($recalls), 5),
            'mrr' => $ranks === [] ? null : round(array_sum($ranks) / count($ranks), 5),
            'mode_accuracy' => $modeCases === [] ? null : round(count(array_filter($modeCases, fn ($result) => $result['mode_correct'])) / count($modeCases), 5),
            'answer_coverage' => round(count(array_filter($results, fn ($result) => $result['answer_present'])) / count($results), 5),
            'source_coverage' => round(count(array_filter($results, fn ($result) => $result['source_present'])) / count($results), 5),
            'citation_validity' => $citationCases === [] ? null : round(count(array_filter($citationCases, fn ($result) => $result['citation_valid'])) / count($citationCases), 5),
            'abstention_accuracy' => $abstentionCases === [] ? null : round(count(array_filter($abstentionCases, fn ($result) => $result['abstention_correct'])) / count($abstentionCases), 5),
            'answer_term_coverage' => $answerTermCases === [] ? null : round(array_sum($answerTermCases) / count($answerTermCases), 5),
            'average_latency_ms' => round(array_sum(array_column($results, 'latency_ms')) / count($results), 2),
        ];
    }
}
