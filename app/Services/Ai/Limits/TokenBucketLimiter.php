<?php

namespace App\Services\Ai\Limits;

use App\Services\Ai\Exceptions\RateLimitExceededException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Lightweight token-bucket limiter backed by the configured cache store.
 * Tracks per-minute request count and per-minute token consumption for
 * each (provider, model) pair. `concurrency` is handled separately by
 * ConcurrencyGate so it can use a short-lived local counter.
 *
 * The cache abstraction means a deployment can switch CACHE_STORE from
 * `database` to `redis` without touching this class.
 */
class TokenBucketLimiter
{
    public function __construct(
        private CacheRepository $cache,
        private array $defaultLimits = [],
        private array $perPairLimits = [],
        private int $windowSeconds = 60,
    ) {}

    public function acquire(string $providerId, string $modelId, int $estimatedTokens = 0): void
    {
        $limits = $this->resolve($providerId, $modelId);
        if ($limits === []) {
            return;
        }

        $rpmKey = $this->key($providerId, $modelId, 'rpm');
        $tpmKey = $this->key($providerId, $modelId, 'tpm');

        if (! empty($limits['rpm'])) {
            $current = (int) $this->cache->get($rpmKey, 0);
            if ($current >= (int) $limits['rpm']) {
                throw new RateLimitExceededException($providerId, $modelId, 'rpm', (int) $limits['rpm']);
            }
        }

        if (! empty($limits['tpm']) && $estimatedTokens > 0) {
            $current = (int) $this->cache->get($tpmKey, 0);
            if (($current + $estimatedTokens) > (int) $limits['tpm']) {
                throw new RateLimitExceededException($providerId, $modelId, 'tpm', (int) $limits['tpm']);
            }
        }
    }

    public function record(string $providerId, string $modelId, int $consumedTokens): void
    {
        $limits = $this->resolve($providerId, $modelId);
        if ($limits === []) {
            return;
        }

        if (! empty($limits['rpm'])) {
            $key = $this->key($providerId, $modelId, 'rpm');
            $this->cache->add($key, 1, $this->windowSeconds) ? null : $this->cache->increment($key);
        }

        if (! empty($limits['tpm']) && $consumedTokens > 0) {
            $key = $this->key($providerId, $modelId, 'tpm');
            if ($this->cache->add($key, $consumedTokens, $this->windowSeconds)) {
                return;
            }
            $this->cache->increment($key, $consumedTokens);
        }
    }

    public function release(string $providerId, string $modelId): void
    {
        // Currently a no-op: TTL expiry of the counter window handles
        // bucketing. Kept for symmetry with ConcurrencyGate and to give
        // callers a stable surface.
    }

    private function resolve(string $providerId, string $modelId): array
    {
        $composite = $providerId.':'.$modelId;
        if (isset($this->perPairLimits[$composite])) {
            return $this->perPairLimits[$composite];
        }

        return $this->defaultLimits;
    }

    private function key(string $providerId, string $modelId, string $dimension): string
    {
        return sprintf('loop:limit:%s:%s:%s:%d', $providerId, $modelId, $dimension, $this->windowStart());
    }

    private function windowStart(): int
    {
        return (int) floor(time() / $this->windowSeconds) * $this->windowSeconds;
    }
}
