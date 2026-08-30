<?php

namespace Tests\Feature;

use App\Models\KnowledgeBase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RagEvaluationCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.graph_rag.enabled' => false,
            'services.ai.api_key' => 'test-key',
        ]);
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/embeddings')) {
                return Http::response(['data' => [['embedding' => [1, 0]]]]);
            }

            return Http::response([
                'model' => 'test-model',
                'choices' => [['message' => ['content' => 'The answer is supported [1].']]],
            ]);
        });
    }

    public function test_command_writes_a_report_and_passes_satisfied_thresholds(): void
    {
        $knowledgeBase = $this->createFixture();
        [$datasetPath, $reportPath] = $this->temporaryPaths();
        file_put_contents($datasetPath, json_encode([
            'name' => 'command-test',
            'knowledge_base_id' => $knowledgeBase->id,
            'cases' => [[
                'id' => 'case-1', 'question' => 'What is ZX-491?', 'mode' => 'vector',
                'expected_mode' => 'vector', 'top_k' => 1, 'expected_sources' => ['Expected document'],
                'answerable' => true, 'expected_answer_contains' => ['supported'],
            ]],
        ], JSON_THROW_ON_ERROR));

        try {
            $this->artisan('rag:evaluate', [
                'dataset' => $datasetPath,
                '--output' => $reportPath,
                '--min-recall' => '1',
                '--min-mrr' => '1',
                '--min-mode-accuracy' => '1',
                '--min-citation-validity' => '1',
                '--min-abstention-accuracy' => '1',
            ])->assertSuccessful();

            $report = json_decode((string) file_get_contents($reportPath), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('command-test', $report['dataset']);
            $this->assertSame(1, $report['summary']['recall_at_k']);
            $this->assertSame(1, $report['summary']['mrr']);
            $this->assertSame(1, $report['summary']['mode_accuracy']);
            $this->assertSame(1, $report['summary']['citation_validity']);
            $this->assertSame(1, $report['summary']['abstention_accuracy']);
            $this->assertSame(1, $report['summary']['answer_term_coverage']);
            $this->assertSame(['vector', 'keyword'], $report['cases'][0]['actual_sources'][0]['channels']);
        } finally {
            @unlink($datasetPath);
            @unlink($reportPath);
        }
    }

    public function test_command_fails_when_a_quality_threshold_is_not_met(): void
    {
        $knowledgeBase = $this->createFixture();
        [$datasetPath, $reportPath] = $this->temporaryPaths();
        file_put_contents($datasetPath, json_encode([
            'knowledge_base_id' => $knowledgeBase->id,
            'cases' => [[
                'question' => 'What is ZX-491?', 'mode' => 'vector', 'expected_sources' => ['Missing document'],
            ]],
        ], JSON_THROW_ON_ERROR));

        try {
            $this->artisan('rag:evaluate', [
                'dataset' => $datasetPath,
                '--min-recall' => '0.5',
            ])->assertFailed();
        } finally {
            @unlink($datasetPath);
            @unlink($reportPath);
        }
    }

    public function test_command_rejects_an_empty_dataset_without_calling_the_model(): void
    {
        Http::fake();
        [$datasetPath, $reportPath] = $this->temporaryPaths();
        file_put_contents($datasetPath, json_encode(['knowledge_base_id' => 1, 'cases' => []], JSON_THROW_ON_ERROR));

        try {
            $this->artisan('rag:evaluate', ['dataset' => $datasetPath])->assertFailed();
            Http::assertNothingSent();
        } finally {
            @unlink($datasetPath);
            @unlink($reportPath);
        }
    }

    public function test_command_detects_an_out_of_range_citation(): void
    {
        Http::swap(new Factory);
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/embeddings')) {
                return Http::response(['data' => [['embedding' => [1, 0]]]]);
            }

            return Http::response(['choices' => [['message' => ['content' => 'Unsupported citation [2].']]]]);
        });
        $knowledgeBase = $this->createFixture();
        [$datasetPath, $reportPath] = $this->temporaryPaths();
        file_put_contents($datasetPath, json_encode([
            'knowledge_base_id' => $knowledgeBase->id,
            'cases' => [[
                'question' => 'What is ZX-491?', 'answerable' => true, 'expected_sources' => ['Expected document'],
            ]],
        ], JSON_THROW_ON_ERROR));

        try {
            $exitCode = $this->artisan('rag:evaluate', [
                'dataset' => $datasetPath, '--min-citation-validity' => '1', '--output' => $reportPath,
            ])->run();
            $report = json_decode((string) file_get_contents($reportPath), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(1, $exitCode, json_encode($report['summary']));
            $this->assertSame(0, $report['summary']['citation_validity']);
            $this->assertSame([2], $report['cases'][0]['citations']);
        } finally {
            @unlink($datasetPath);
            @unlink($reportPath);
        }
    }

    public function test_command_scores_a_correct_abstention_for_an_unanswerable_case(): void
    {
        Http::swap(new Factory);
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/embeddings')) {
                return Http::response(['data' => [['embedding' => [1, 0]]]]);
            }

            return Http::response(['choices' => [['message' => ['content' => '现有资料不足，无法回答。']]]]);
        });
        $knowledgeBase = KnowledgeBase::create(['name' => 'Empty evaluation']);
        [$datasetPath, $reportPath] = $this->temporaryPaths();
        file_put_contents($datasetPath, json_encode([
            'knowledge_base_id' => $knowledgeBase->id,
            'cases' => [[
                'question' => '不存在的信息是什么？', 'answerable' => false, 'expected_sources' => [],
            ]],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        try {
            $exitCode = $this->artisan('rag:evaluate', [
                'dataset' => $datasetPath, '--min-abstention-accuracy' => '1', '--output' => $reportPath,
            ])->run();
            $report = json_decode((string) file_get_contents($reportPath), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(0, $exitCode, json_encode($report['summary']));
            $this->assertSame(1, $report['summary']['abstention_accuracy']);
            $this->assertTrue($report['cases'][0]['is_abstention']);
            $this->assertNull($report['summary']['citation_validity']);
        } finally {
            @unlink($datasetPath);
            @unlink($reportPath);
        }
    }

    private function createFixture(): KnowledgeBase
    {
        $knowledgeBase = KnowledgeBase::create(['name' => 'Evaluation']);
        $document = $knowledgeBase->documents()->create([
            'title' => 'Expected document', 'source_content' => 'ZX-491 is resolved.', 'status' => 'ready', 'index_version' => 1,
        ]);
        $document->chunks()->create([
            'position' => 0, 'content' => 'ZX-491 is resolved.', 'embedding' => [1, 0],
        ]);

        return $knowledgeBase;
    }

    private function temporaryPaths(): array
    {
        return [
            tempnam(sys_get_temp_dir(), 'loop-rag-dataset-'),
            tempnam(sys_get_temp_dir(), 'loop-rag-report-'),
        ];
    }
}
