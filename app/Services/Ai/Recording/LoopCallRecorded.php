<?php

namespace App\Services\Ai\Recording;

/**
 * Event dispatched after every LoopRouter call (success or failure).
 * Listeners are responsible for the actual persistence so the hot path
 * stays decoupled from storage and can be turned off via
 * config('services.loop.recording.enabled').
 *
 * The default listener is LoopCallRecorderListener, which writes one
 * row to the ai_call_logs table.
 */
class LoopCallRecorded
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly string $requestId,
        public readonly string $taskType,
        public readonly string $providerId,
        public readonly string $modelId,
        public readonly string $status,
        public readonly int $latencyMs,
        public readonly ?int $promptTokens = null,
        public readonly ?int $completionTokens = null,
        public readonly ?int $totalTokens = null,
        public readonly ?string $errorClass = null,
        public readonly ?string $errorMessage = null,
        public readonly array $context = [],
    ) {}
}
