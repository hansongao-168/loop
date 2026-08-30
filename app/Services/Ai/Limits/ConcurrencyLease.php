<?php

namespace App\Services\Ai\Limits;

use Closure;

/**
 * RAII-style lease returned by ConcurrencyGate::acquire(). If the gate
 * issued a real lease ($active === true), the caller MUST invoke
 * release() once the upstream call completes to free the slot.
 */
final class ConcurrencyLease
{
    private bool $released = false;

    public function __construct(
        public readonly string $providerId,
        public readonly string $modelId,
        public readonly bool $active,
        private ?Closure $onRelease = null,
    ) {}

    public function release(): void
    {
        if ($this->released || ! $this->active) {
            return;
        }
        $this->released = true;
        if ($this->onRelease !== null) {
            ($this->onRelease)();
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
