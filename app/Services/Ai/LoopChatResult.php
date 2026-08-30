<?php

namespace App\Services\Ai;

/**
 * Result of a successful chat completion call. Wraps the raw OpenAI
 * compatible payload (preserved verbatim for backwards compatibility
 * with code that still reads `choices.0.message.content` and `usage`)
 * alongside the dispatcher metadata needed for usage recording and
 * observability.
 */
final class LoopChatResult
{
    /**
     * @param  array<string, mixed>  $payload  Raw provider response.
     */
    public function __construct(
        public readonly array $payload,
        public readonly string $providerId,
        public readonly string $modelId,
        public readonly string $requestId,
        public readonly int $latencyMs,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->payload + [
            'provider' => $this->providerId,
            'request_id' => $this->requestId,
            'latency_ms' => $this->latencyMs,
        ];
    }

    /** @return array<string, mixed>|null */
    public function usage(): ?array
    {
        return $this->payload['usage'] ?? null;
    }

    public function content(): ?string
    {
        $content = data_get($this->payload, 'choices.0.message.content');

        return is_string($content) ? $content : null;
    }

    public function resolvedModel(): string
    {
        return (string) ($this->payload['model'] ?? $this->modelId);
    }
}
