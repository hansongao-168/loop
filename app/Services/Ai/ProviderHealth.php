<?php

namespace App\Services\Ai;

use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Cache-backed health probe store for providers. The `loop:health`
 * command (and the scheduler) writes one probe result per provider; the
 * LoopRouter reads it to deprioritize providers whose last probe failed
 * so traffic automatically shifts to healthy providers.
 *
 * Unknown state (no probe recorded, or the probe is older than the TTL)
 * is reported as null — the router then keeps the configured candidate
 * order instead of guessing.
 */
class ProviderHealth
{
    public function __construct(
        private CacheRepository $cache,
        private int $ttlSeconds = 300,
    ) {}

    public function record(string $providerId, bool $healthy, int $latencyMs = 0): void
    {
        $this->cache->put($this->key($providerId), [
            'healthy' => $healthy,
            'latency_ms' => $latencyMs,
            'checked_at' => time(),
        ], $this->ttlSeconds);
    }

    /**
     * null = no fresh probe (treat as unknown), true/false = last probe
     * result.
     */
    public function isHealthy(string $providerId): ?bool
    {
        $state = $this->cache->get($this->key($providerId));
        if (! is_array($state) || ! array_key_exists('healthy', $state)) {
            return null;
        }

        return (bool) $state['healthy'];
    }

    /**
     * @return array{healthy:bool, latency_ms:int, checked_at:int}|null
     */
    public function snapshot(string $providerId): ?array
    {
        $state = $this->cache->get($this->key($providerId));

        return is_array($state) ? [
            'healthy' => (bool) ($state['healthy'] ?? false),
            'latency_ms' => (int) ($state['latency_ms'] ?? 0),
            'checked_at' => (int) ($state['checked_at'] ?? 0),
        ] : null;
    }

    private function key(string $providerId): string
    {
        return sprintf('loop:health:%s', $providerId);
    }
}
