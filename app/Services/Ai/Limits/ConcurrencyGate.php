<?php

namespace App\Services\Ai\Limits;

use App\Services\Ai\Exceptions\RateLimitExceededException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Process-local-ish concurrency gate. Each acquire() returns a
 * ConcurrencyLease that MUST be released via release() when the
 * upstream call completes (success OR failure). Throws when the
 * configured `concurrency` ceiling is reached.
 *
 * Cache-backed so multiple workers (PHP-FPM / queue workers) still
 * coordinate via shared storage.
 */
class ConcurrencyGate
{
    public function __construct(
        private CacheRepository $cache,
        private array $defaultLimits = [],
        private array $perPairLimits = [],
        private int $leaseTtl = 30,
    ) {}

    public function acquire(string $providerId, string $modelId): ConcurrencyLease
    {
        $limit = $this->resolve($providerId, $modelId);
        if ($limit <= 0) {
            return new ConcurrencyLease($providerId, $modelId, false);
        }

        $key = $this->key($providerId, $modelId);
        $current = (int) $this->cache->get($key, 0);

        if ($current >= $limit) {
            throw new RateLimitExceededException($providerId, $modelId, 'concurrency', $limit);
        }

        $this->cache->put($key, $current + 1, $this->leaseTtl);

        return new ConcurrencyLease($providerId, $modelId, true, function () use ($key) {
            $value = (int) $this->cache->get($key, 0);
            $this->cache->put($key, max(0, $value - 1), $this->leaseTtl);
        });
    }

    private function resolve(string $providerId, string $modelId): int
    {
        $composite = $providerId.':'.$modelId;
        if (isset($this->perPairLimits[$composite])) {
            return (int) $this->perPairLimits[$composite];
        }

        return (int) ($this->defaultLimits['concurrency'] ?? 0);
    }

    private function key(string $providerId, string $modelId): string
    {
        return sprintf('loop:concurrency:%s:%s', $providerId, $modelId);
    }
}
