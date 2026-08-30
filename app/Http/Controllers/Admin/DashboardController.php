<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\KnowledgeBase;
use App\Services\Ai\LoopRouter;
use App\Services\Ai\ProviderHealth;
use App\Services\AiUsageReport;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(
        LoopRouter $loop,
        ProviderHealth $health,
        AiUsageReport $usage,
    ): View {
        $providers = $this->providerOverview($health);
        $defaultProvider = (string) config('services.loop.default_provider', 'openai_compatible');
        $defaultHealth = $providers[$defaultProvider]['healthy'] ?? null;

        // Only hit /models when the default provider is known-reachable;
        // a down server must not stall the dashboard on a live timeout.
        $modelServer = [
            'online' => $defaultHealth !== false ? $loop->ping($defaultProvider) : false,
            'models' => $defaultHealth === false ? [] : $loop->listModels($defaultProvider),
        ];

        return view('admin.dashboard', [
            'knowledgeBases' => KnowledgeBase::withCount(['documents'])->latest()->get(),
            'documentCount' => Document::count(),
            'chunkCount' => DocumentChunk::count(),
            'modelServer' => $modelServer,
            'providers' => $providers,
            'loopStats' => $usage->summarise(24),
            'modelStats' => $usage->perModel(24),
        ]);
    }

    /**
     * Per-provider view data from the configured providers plus the
     * latest health probe (the scheduler's loop:health keeps it fresh).
     *
     * @return array<string, array{base_url:string, healthy:?bool, latency_ms:int, checked_at:?string}>
     */
    private function providerOverview(ProviderHealth $health): array
    {
        $overview = [];
        foreach ((array) config('services.loop.providers', []) as $providerId => $config) {
            $providerId = (string) $providerId;
            if (trim((string) ($config['base_url'] ?? '')) === '') {
                continue; // optional provider (e.g. backup) not configured
            }

            $snapshot = $health->snapshot($providerId);
            $overview[$providerId] = [
                'base_url' => (string) ($config['base_url'] ?? ''),
                'healthy' => $snapshot === null ? null : $snapshot['healthy'],
                'latency_ms' => $snapshot['latency_ms'] ?? 0,
                'checked_at' => isset($snapshot['checked_at'])
                    ? date('Y-m-d H:i:s', $snapshot['checked_at'])
                    : null,
            ];
        }

        return $overview;
    }
}
