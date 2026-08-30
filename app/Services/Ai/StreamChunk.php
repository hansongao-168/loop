<?php

namespace App\Services\Ai;

/**
 * A single incremental chunk yielded by LoopRouter::stream(). Matches
 * the shape of an OpenAI-compatible SSE delta so callers can pipe it
 * straight into a streamed response without further adaptation.
 */
final class StreamChunk
{
    public function __construct(
        public readonly string $delta,
        public readonly ?string $finishReason = null,
        public readonly ?array $usage = null,
        public readonly string $providerId = '',
        public readonly string $modelId = '',
    ) {}

    public function isFinal(): bool
    {
        return $this->finishReason !== null && $this->finishReason !== '';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'delta' => $this->delta,
            'finish_reason' => $this->finishReason,
            'usage' => $this->usage,
            'provider' => $this->providerId,
            'model' => $this->modelId,
        ];
    }
}
