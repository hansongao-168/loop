<?php

namespace App\Services\Ai\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Thrown when no provider/model in the candidate chain can satisfy the
 * request — either because every candidate failed, the chain is empty,
 * or a configured provider is not registered.
 */
class ProviderUnavailableException extends RuntimeException
{
    public function __construct(string $message = 'No AI provider is available for the requested task.', ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
