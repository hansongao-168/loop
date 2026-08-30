<?php

namespace App\Services\Ai;

/**
 * Contract every AI provider driver must satisfy. Implementations are
 * stateless: all routing/dispatch concerns (rate limits, circuit
 * breaking, retries) live in LoopRouter, not in the adapter.
 */
interface ProviderAdapter
{
    /**
     * Returns the provider key this adapter serves, e.g. 'openai_compatible'.
     * Must match the `driver` field on a provider config entry.
     */
    public function driverId(): string;

    /**
     * Generate an embedding vector for $text using $model. Returns the
     * vector together with any usage block the provider exposed so the
     * dispatcher can record it in ai_call_logs.
     *
     * @return array{vector:list<float>, usage:?array<string,mixed>}
     */
    public function embed(string $providerId, string $modelId, string $text, array $config): array;

    /**
     * Run a non-streaming chat completion. Returns the raw provider
     * payload so callers can read both the content and the usage block.
     *
     * @param  array<int, array{role:string, content:string}>  $messages
     * @return array<string, mixed>
     */
    public function chat(string $providerId, string $modelId, array $messages, float $temperature, array $config): array;

    /**
     * Stream chat completion deltas. Each yielded item must be a
     * StreamChunk. Implementations should still surface the final usage
     * block (if available) on the chunk where finish_reason is set.
     *
     * @param  array<int, array{role:string, content:string}>  $messages
     * @return \Generator<int, StreamChunk, void, void>
     */
    public function stream(string $providerId, string $modelId, array $messages, float $temperature, array $config): \Generator;

    /**
     * Ping the provider for a quick health check. Used by the admin
     * dashboard to show online status.
     */
    public function ping(string $providerId, array $config): bool;

    /**
     * List available model ids on the provider. Used by the admin
     * dashboard model browser.
     *
     * @return list<string>
     */
    public function listModels(string $providerId, array $config): array;
}
