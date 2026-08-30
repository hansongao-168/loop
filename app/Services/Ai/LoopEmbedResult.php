<?php

namespace App\Services\Ai;

/**
 * Result of a successful embedding call. Wraps the float vector together
 * with the provider/model that produced it and the request id used for
 * tracing in ai_call_logs.
 */
final class LoopEmbedResult
{
    /** @param list<float> $vector */
    public function __construct(
        public readonly array $vector,
        public readonly string $providerId,
        public readonly string $modelId,
        public readonly string $requestId,
        public readonly int $latencyMs,
        public readonly ?array $usage = null,
    ) {}

    public function toLegacyShape(): array
    {
        // Returns the OpenAI-compatible `data.0.embedding` slice shape so
        // legacy callers can keep reading $result['data'][0]['embedding'].
        return [
            'data' => [['embedding' => $this->vector]],
            'model' => $this->modelId,
            'provider' => $this->providerId,
            'request_id' => $this->requestId,
            'latency_ms' => $this->latencyMs,
            'usage' => $this->usage,
        ];
    }
}
