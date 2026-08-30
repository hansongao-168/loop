<?php

namespace App\Services;

use App\Models\DocumentChunk;
use App\Models\KnowledgeBase;
use App\Services\Ai\LoopChatResult;
use App\Services\Ai\LoopRouter;
use App\Support\AnswerInsights;
use App\Support\CosineSimilarity;

/**
 * Owns the RAG query pipeline: hybrid retrieval (vector, keyword, graph
 * neighborhoods or community summaries), RRF fusion with optional model
 * reranking, and the evidence-grounded answer generation.
 */
class RagQueryService
{
    public function __construct(
        private LoopRouter $loop,
        private LocalGraphSearchService $localGraphSearch,
        private GlobalGraphSearchService $globalGraphSearch,
        private KeywordRetrievalService $keywordRetrieval,
        private RetrievalFusionService $retrievalFusion,
        private CandidateRerankerService $candidateReranker,
    ) {}

    public function ask(KnowledgeBase $knowledgeBase, string $question, array $options = []): array
    {
        $queryVector = $this->loop->embed($question, null, [
            'task' => 'embed',
            'knowledge_base_id' => $knowledgeBase->id,
        ])->vector;
        $topK = min(max((int) ($options['top_k'] ?? config('services.ai.top_k')), 1), 20);
        $mode = $options['mode'] ?? 'auto';
        $graph = ['entities' => [], 'relationships' => [], 'chunk_ids' => []];
        $global = ['communities' => [], 'chunk_ids' => []];
        if (config('services.graph_rag.enabled') && $mode === 'global') {
            $global = $this->globalGraphSearch->search(
                $knowledgeBase,
                $queryVector,
                (int) ($options['community_top_k'] ?? 5),
            );
        } elseif (config('services.graph_rag.enabled') && $mode !== 'vector') {
            $graph = $this->localGraphSearch->search(
                $knowledgeBase,
                $question,
                (int) ($options['max_hops'] ?? 2),
            );
        }

        $vectorResults = DocumentChunk::query()
            ->with('document:id,title,source')
            ->whereHas('document', fn ($q) => $q->where('knowledge_base_id', $knowledgeBase->id))
            ->get()
            ->map(fn (DocumentChunk $chunk) => [
                'chunk' => $chunk,
                'score' => CosineSimilarity::score($queryVector, $chunk->embedding),
            ])->sortByDesc('score')->values()->all();

        $keywordChunkIds = $this->keywordRetrieval->rank(
            array_map(fn ($item) => $item['chunk'], $vectorResults),
            $question,
        );
        $candidateLimit = min(max((int) config('services.graph_rag.rerank_candidates', 20), $topK), 100);
        $fusedCandidates = $this->retrievalFusion->fuse($vectorResults, [
            'keyword' => $keywordChunkIds,
            'graph' => $mode === 'global' ? $global['chunk_ids'] : $graph['chunk_ids'],
        ], $candidateLimit);
        $reranked = $this->candidateReranker->rerank($question, $fusedCandidates, $topK);
        $chunks = collect($reranked['results']);
        $resolvedMode = match (true) {
            $mode === 'global' && $global['communities'] !== [] => 'global',
            $graph['chunk_ids'] !== [] => 'local',
            default => 'vector',
        };

        $context = $chunks->map(fn ($item, $i) => sprintf(
            "[%d] %s\n%s", $i + 1, $item['chunk']->document->title, $item['chunk']->content
        ))->implode("\n\n");
        $communityContext = collect($global['communities'])->map(fn ($community) => sprintf(
            '- %s: %s', $community['title'], $community['summary']
        ))->implode("\n");
        if ($communityContext !== '') {
            $context = "Derived community summaries (verify against numbered evidence):\n{$communityContext}\n\nNumbered evidence:\n{$context}";
        }

        $systemPrompt = 'Answer only from the supplied context. Ground every claim in the numbered evidence and cite sources as [1], [2]. Every sentence must carry at least one citation; never make a statement without one. The derived community summaries at the top are context hints only — before citing a claim, verify it appears in the numbered evidence. If the asked entity, fact or relation is absent from the context — even if you know the answer from general knowledge — reply exactly that the knowledge base does not contain this information, cite nothing, and never guess or fill in outside facts.';

        $result = $this->loop->chat([
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => "Context:\n{$context}\n\nQuestion: {$question}"],
        ], $options['model'] ?? null, (float) ($options['temperature'] ?? 0.2), [
            'task' => 'answer',
            'knowledge_base_id' => $knowledgeBase->id,
        ]);

        $result = $this->withCitationRetry($result, $systemPrompt, $context, $question, $chunks->isEmpty(), $options, (int) $knowledgeBase->id);

        return [
            'answer' => $result->content(),
            'model' => $result->payload['model'] ?? ($options['model'] ?? config('services.ai.chat_model')),
            'sources' => $chunks->map(fn ($item, $i) => [
                'index' => $i + 1,
                'document_id' => $item['chunk']->document_id,
                'title' => $item['chunk']->document->title,
                'source' => $item['chunk']->document->source,
                'score' => $item['score'] === null ? null : round($item['score'], 5),
                'retrieval_score' => round($item['retrieval_score'], 5),
                'channels' => $item['channels'],
                'excerpt' => mb_substr($item['chunk']->content, 0, 240),
            ])->all(),
            'mode' => $resolvedMode,
            'retrieval' => [
                'vector_hits' => count($vectorResults),
                'keyword_hits' => count($keywordChunkIds),
                'graph_hits' => count($mode === 'global' ? $global['chunk_ids'] : $graph['chunk_ids']),
                'entities' => count($graph['entities']),
                'relationships' => count($graph['relationships']),
                'communities' => count($global['communities']),
                'candidate_hits' => count($fusedCandidates),
                'reranked' => $reranked['applied'],
            ],
            'entities' => ($options['include_graph'] ?? false) ? $graph['entities'] : null,
            'relationships' => ($options['include_graph'] ?? false) ? $graph['relationships'] : null,
            'communities' => ($options['include_graph'] ?? false) ? $global['communities'] : null,
            'usage' => $result->usage(),
        ];
    }

    /**
     * Citation retry gate (v2/v3 baseline finding): small models
     * sometimes answer from the context without the required [n]
     * citations, run to run. When numbered evidence exists but the
     * answer cites nothing, one follow-up turn asks the model to add
     * them; the retry is kept only if it actually produces citations.
     * Abstentions are exempt — they must stay uncited by design — and
     * evidence-free answers have nothing to cite.
     */
    private function withCitationRetry(
        LoopChatResult $result,
        string $systemPrompt,
        string $context,
        string $question,
        bool $noEvidence,
        array $options,
        int $knowledgeBaseId,
    ): LoopChatResult {
        $answerText = (string) ($result->content() ?? '');

        if ($answerText === '' || $noEvidence
            || AnswerInsights::hasCitations($answerText)
            || AnswerInsights::isAbstention($answerText)) {
            return $result;
        }

        try {
            $retry = $this->loop->chat([
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => "Context:\n{$context}\n\nQuestion: {$question}"],
                ['role' => 'assistant', 'content' => $answerText],
                ['role' => 'user', 'content' => 'Rewrite the answer above so that every sentence carries [n] citations into the numbered evidence. Return only the corrected answer text, in the same language as the original, without commentary.'],
            ], $options['model'] ?? null, (float) ($options['temperature'] ?? 0.2), [
                'task' => 'answer',
                'knowledge_base_id' => $knowledgeBaseId,
                'metadata' => ['citation_retry' => true],
            ]);
        } catch (\Throwable) {
            // The original answer still stands when the retry call fails;
            // the gate is best-effort and must never break answering.
            return $result;
        }

        $retryText = (string) ($retry->content() ?? '');

        return ($retryText !== '' && AnswerInsights::hasCitations($retryText)) ? $retry : $result;
    }
}
