<?php

namespace App\Services\Ai\Exceptions;

use RuntimeException;

/**
 * Thrown when the circuit breaker for a (provider, model) pair is open,
 * meaning the dispatcher has observed repeated failures and is
 * short-circuiting further requests until the cooldown elapses.
 */
class CircuitOpenException extends RuntimeException
{
    public function __construct(
        public readonly string $providerId,
        public readonly string $modelId,
        public readonly int $cooldownSeconds,
    ) {
        parent::__construct(sprintf(
            'Circuit open for %s:%s; retry after %d seconds.',
            $providerId, $modelId, $cooldownSeconds,
        ));
    }
}
