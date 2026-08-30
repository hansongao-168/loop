<?php

namespace App\Services\Ai\Recording;

use Illuminate\Support\Facades\Event;

/**
 * Thin façade around LoopCallRecorded event dispatch. Keeps the
 * sampling logic in one place so the router code stays declarative.
 */
class UsageRecorder
{
    public function __construct(
        private bool $enabled = true,
        private float $sampleRate = 1.0,
    ) {}

    public function record(LoopCallRecorded $event): void
    {
        if (! $this->enabled) {
            return;
        }
        if ($this->sampleRate < 1.0 && mt_rand() / mt_getrandmax() > $this->sampleRate) {
            return;
        }
        Event::dispatch($event);
    }
}
