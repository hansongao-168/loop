<?php

namespace App\Console\Commands;

use App\Services\Ai\LoopRouter;
use App\Services\Ai\ProviderHealth;
use Illuminate\Console\Command;

/**
 * Probes every configured provider with a lightweight GET /models
 * request and stores the result so the LoopRouter can deprioritize
 * unhealthy providers. Designed to run from the scheduler
 * (every few minutes) but also usable ad hoc for diagnostics.
 */
class LoopHealth extends Command
{
    protected $signature = 'loop:health
        {--json : Output the probe results as JSON instead of a table}';

    protected $description = 'Probe every LOOP provider and record health so routing can avoid down providers';

    public function handle(LoopRouter $router, ProviderHealth $health): int
    {
        $providers = (array) config('services.loop.providers', []);
        if ($providers === []) {
            $this->warn('No providers configured under services.loop.providers.');

            return self::SUCCESS;
        }

        $results = [];
        $anyDown = false;

        foreach ($providers as $providerId => $config) {
            if (trim((string) ($config['base_url'] ?? '')) === '') {
                // Not configured (e.g. the optional backup provider);
                // leave any previous probe result untouched.
                continue;
            }

            $startedAt = microtime(true);
            $online = $router->ping((string) $providerId);
            $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

            $health->record((string) $providerId, $online, $latencyMs);
            $anyDown = $anyDown || ! $online;

            $results[] = [
                'provider' => (string) $providerId,
                'base_url' => (string) ($config['base_url'] ?? ''),
                'healthy' => $online ? 'yes' : 'NO',
                'latency_ms' => $latencyMs,
            ];
        }

        if ($this->option('json')) {
            $this->line(json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(['Provider', 'Base URL', 'Healthy', 'Latency (ms)'], $results);
        }

        return $anyDown ? self::FAILURE : self::SUCCESS;
    }
}
