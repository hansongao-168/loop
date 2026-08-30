<?php

namespace App\Console\Commands;

use App\Models\KnowledgeBase;
use App\Services\RagEvaluationService;
use Illuminate\Console\Command;

class EvaluateRag extends Command
{
    protected $signature = 'rag:evaluate
        {dataset : Path to the evaluation JSON dataset}
        {--knowledge-base= : Override dataset knowledge_base_id}
        {--max-cases= : Evaluate only the first N cases}
        {--output= : Write the complete JSON report to this path}
        {--min-recall=0 : Fail when average Recall@K is below this value}
        {--min-mrr=0 : Fail when MRR is below this value}
        {--min-mode-accuracy=0 : Fail when mode accuracy is below this value}
        {--min-citation-validity=0 : Fail when citation validity is below this value}
        {--min-abstention-accuracy=0 : Fail when abstention accuracy is below this value}';

    protected $description = 'Run the repeatable RAG/GraphRAG evaluation dataset';

    public function handle(RagEvaluationService $evaluation): int
    {
        try {
            $dataset = $this->readDataset((string) $this->argument('dataset'));
            $knowledgeBaseId = $this->option('knowledge-base') ?: ($dataset['knowledge_base_id'] ?? null);
            if (! $knowledgeBaseId) {
                throw new \InvalidArgumentException('Provide --knowledge-base or knowledge_base_id in the dataset.');
            }
            $knowledgeBase = KnowledgeBase::query()->findOrFail($knowledgeBaseId);
            $maxCases = $this->option('max-cases');
            $report = $evaluation->evaluate($knowledgeBase, $dataset, $maxCases === null ? null : (int) $maxCases);
            $this->renderSummary($report['summary']);

            if ($output = $this->option('output')) {
                $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                if (file_put_contents((string) $output, $json."\n") === false) {
                    throw new \RuntimeException("Unable to write evaluation report to {$output}.");
                }
                $this->info("Report written to {$output}");
            }

            return $this->meetsThresholds($report['summary']) ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function readDataset(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new \InvalidArgumentException("Evaluation dataset is not readable: {$path}");
        }

        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    private function renderSummary(array $summary): void
    {
        $this->table(['Metric', 'Value'], collect($summary)->map(fn ($value, $metric) => [
            $metric, $value === null ? 'n/a' : $value,
        ])->values()->all());
    }

    private function meetsThresholds(array $summary): bool
    {
        $thresholds = [
            'recall_at_k' => (float) $this->option('min-recall'),
            'mrr' => (float) $this->option('min-mrr'),
            'mode_accuracy' => (float) $this->option('min-mode-accuracy'),
            'citation_validity' => (float) $this->option('min-citation-validity'),
            'abstention_accuracy' => (float) $this->option('min-abstention-accuracy'),
        ];
        $passed = true;
        foreach ($thresholds as $metric => $minimum) {
            if ($minimum > 0 && ($summary[$metric] === null || $summary[$metric] < $minimum)) {
                $this->error("{$metric} is below the required threshold {$minimum}.");
                $passed = false;
            }
        }

        return $passed;
    }
}
