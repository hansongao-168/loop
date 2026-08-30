<?php

namespace Tests\Feature;

use App\Models\DocumentChunk;
use App\Models\KnowledgeBase;
use App\Services\RagQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CitationRetryGateTest extends TestCase
{
    use RefreshDatabase;

    private KnowledgeBase $knowledgeBase;

    /** @var list<array<string, mixed>> */
    private array $chatRequests = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.ai.api_key' => 'test-key',
            'services.graph_rag.enabled' => false,
        ]);

        $this->knowledgeBase = KnowledgeBase::create(['name' => 'Gate']);
        $document = $this->knowledgeBase->documents()->create([
            'title' => 'Doc', 'source_content' => 'Alice works at Acme.', 'status' => 'ready',
        ]);
        $document->chunks()->create([
            'position' => 0, 'content' => 'Alice works at Acme.', 'embedding' => [1, 0],
        ]);
    }

    public function test_answer_without_citations_is_retried_once_and_retry_result_is_kept(): void
    {
        $this->fakeModel(['Acme 是一家云计算公司。', 'Acme 是一家云计算公司 [1]。']);

        $result = app(RagQueryService::class)->ask($this->knowledgeBase, 'Acme 是做什么的？');

        $this->assertSame('Acme 是一家云计算公司 [1]。', $result['answer']);
        $this->assertCount(2, $this->chatRequests, 'exactly one retry turn expected');
        // The retry turn carries the draft answer back to the model.
        $this->assertSame('Acme 是一家云计算公司。', $this->chatRequests[1]['messages'][2]['content'] ?? null);
        $this->assertSame(
            'user',
            $this->chatRequests[1]['messages'][3]['role'] ?? null,
        );
    }

    public function test_retry_answer_without_citations_is_discarded(): void
    {
        $this->fakeModel(['Acme 是一家云计算公司。', 'Acme 还是那家公司。']);

        $result = app(RagQueryService::class)->ask($this->knowledgeBase, 'Acme 是做什么的？');

        // The gate keeps the original answer when the retry still lacks
        // citations.
        $this->assertSame('Acme 是一家云计算公司。', $result['answer']);
        $this->assertCount(2, $this->chatRequests);
    }

    public function test_answer_with_citations_skips_the_retry(): void
    {
        $this->fakeModel(['Acme 是一家云计算公司 [1]。']);

        app(RagQueryService::class)->ask($this->knowledgeBase, 'Acme 是做什么的？');

        $this->assertCount(1, $this->chatRequests);
    }

    public function test_abstention_answer_skips_the_retry(): void
    {
        $this->fakeModel(['抱歉，知识库信息不足，无法回答该问题。']);

        app(RagQueryService::class)->ask($this->knowledgeBase, 'Acme 的竞争对手是谁？');

        // Abstentions must stay uncited by design — never force-cited.
        $this->assertCount(1, $this->chatRequests);
    }

    public function test_empty_knowledge_base_skips_the_retry(): void
    {
        DocumentChunk::query()->delete();
        $this->fakeModel(['无法回答。']);

        $result = app(RagQueryService::class)->ask($this->knowledgeBase, '任何问题');

        $this->assertCount(1, $this->chatRequests);
        $this->assertSame('vector', $result['mode']);
    }

    /**
     * @param  list<string>  $answers  chat responses returned in order;
     *                                 the last one repeats when the gate is not exercised.
     */
    private function fakeModel(array $answers): void
    {
        $this->chatRequests = [];
        Http::fake(function ($request) use ($answers) {
            if (str_ends_with($request->url(), '/embeddings')) {
                return Http::response(['data' => [['embedding' => [1, 0]]]]);
            }

            $index = count($this->chatRequests);
            $this->chatRequests[] = $request->data();

            return Http::response([
                'model' => 'test-model',
                'choices' => [['message' => ['content' => $answers[min($index, count($answers) - 1)]]]],
            ]);
        });
    }
}
