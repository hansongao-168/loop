<?php

namespace App\Services\Ai\Exceptions;

use RuntimeException;

/**
 * Thrown when a (provider, model) pair has exhausted its configured
 * RPM/TPM/concurrency budget. Callers may catch this and either retry
 * later or surface a 429-style error to the user.
 */
class RateLimitExceededException extends RuntimeException
{
    public function __construct(
        public readonly string $providerId,
        public readonly string $modelId,
        public readonly string $dimension,
        public readonly int $limit,
    ) {
        parent::__construct(sprintf(
            'Rate limit exceeded for %s:%s (dimension=%s, limit=%d).',
            $providerId, $modelId, $dimension, $limit,
        ));
    }
}
