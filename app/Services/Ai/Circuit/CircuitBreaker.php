<?php

namespace App\Services\Ai\Circuit;

use App\Services\Ai\Exceptions\CircuitOpenException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Cache-backed circuit breaker per (provider, model) pair. The breaker
 * tracks a rolling failure counter and an `opened_at` timestamp. While
 * the breaker is open (within cooldown) every call short-circuits with
 * CircuitOpenException, allowing the router to skip to the next
 * candidate in the failover chain.
 *
 * A single successful call resets the failure counter and clears the
 * open timestamp.
 */
class CircuitBreaker
{
    public function __construct(
        private CacheRepository $cache,
        private int $failureThreshold = 5,
        private int $cooldownSeconds = 30,
    ) {}

    public function guard(string $providerId, string $modelId): void
    {
        $state = $this->read($providerId, $modelId);
        if ($state['opened_at'] === null) {
            return;
        }

        if ((time() - (int) $state['opened_at']) < $this->cooldownSeconds) {
            throw new CircuitOpenException($providerId, $modelId, $this->cooldownSeconds);
        }

        // Cooldown elapsed — move to half-open by clearing the open flag
        // and resetting the counter. The next call will validate.
        $this->cache->put($this->key($providerId, $modelId), [
            'failures' => 0,
            'opened_at' => null,
        ], $this->cooldownSeconds * 4);
    }

    public function recordSuccess(string $providerId, string $modelId): void
    {
        $this->cache->put($this->key($providerId, $modelId), [
            'failures' => 0,
            'opened_at' => null,
        ], $this->cooldownSeconds * 4);
    }

    public function recordFailure(string $providerId, string $modelId): void
    {
        $state = $this->read($providerId, $modelId);
        $failures = (int) $state['failures'] + 1;
        $openedAt = $failures >= $this->failureThreshold ? time() : $state['opened_at'];

        $this->cache->put($this->key($providerId, $modelId), [
            'failures' => $failures,
            'opened_at' => $openedAt,
        ], $this->cooldownSeconds * 4);
    }

    /** @return array{failures:int, opened_at:?int} */
    public function status(string $providerId, string $modelId): array
    {
        return $this->read($providerId, $modelId);
    }

    private function read(string $providerId, string $modelId): array
    {
        $raw = $this->cache->get($this->key($providerId, $modelId), []);
        if (! is_array($raw)) {
            $raw = [];
        }

        return [
            'failures' => (int) ($raw['failures'] ?? 0),
            'opened_at' => isset($raw['opened_at']) ? (int) $raw['opened_at'] : null,
        ];
    }

    private function key(string $providerId, string $modelId): string
    {
        return sprintf('loop:circuit:%s:%s', $providerId, $modelId);
    }
}
