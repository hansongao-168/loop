<?php

namespace App\Services\Ai;

use App\Services\Ai\Circuit\CircuitBreaker;
use App\Services\Ai\Exceptions\CircuitOpenException;
use App\Services\Ai\Exceptions\ProviderUnavailableException;
use App\Services\Ai\Exceptions\RateLimitExceededException;
use App\Services\Ai\Limits\ConcurrencyGate;
use App\Services\Ai\Limits\TokenBucketLimiter;
use App\Services\Ai\Recording\LoopCallRecorded;
use App\Services\Ai\Recording\UsageRecorder;
use Illuminate\Support\Str;
use Throwable;

/**
 * LOOP central AI dispatcher.
 *
 * Every AI call in the project flows through this class. It resolves
 * the (provider, model) pair for the requested task, applies per-pair
 * rate limits and circuit breaking, walks the candidate chain with the
 * configured strategy (failover / round_robin / single) when a call
 * fails, deprioritizes providers whose health probe failed, and emits
 * usage events for observability.
 *
 * Services and controllers inject LoopRouter directly; there is no
 * alternative AI entry point.
 */
class LoopRouter
{
    public function __construct(
        private ProviderRegistry $providers,
        private ModelResolver $resolver,
        private TokenBucketLimiter $rateLimiter,
        private ConcurrencyGate $concurrency,
        private CircuitBreaker $breaker,
        private UsageRecorder $recorder,
        private ProviderHealth $health,
    ) {}

    /**
     * Generate an embedding vector. Returns a LoopEmbedResult so callers
     * can read both the float vector and the dispatcher metadata.
     *
     * @param  array<string, mixed>  $context  Optional metadata for logs.
     */
    public function embed(string $text, ?string $modelHint = null, array $context = []): LoopEmbedResult
    {
        return $this->dispatch('embed', $context, function (string $providerId, string $modelId) use ($text) {
            $result = $this->providers->get($this->driverFor($providerId))
                ->embed($providerId, $modelId, $text, $this->providers->configFor($providerId));

            return ['vector' => $result['vector'], 'usage' => $result['usage'] ?? null];
        }, function (array $payload, string $providerId, string $modelId, string $requestId, int $latencyMs) {
            return new LoopEmbedResult(
                $payload['vector'],
                $providerId,
                $modelId,
                $requestId,
                $latencyMs,
                $payload['usage'] ?? null,
            );
        });
    }

    /**
     * Run a non-streaming chat completion.
     *
     * @param  array<int, array{role:string, content:string}>  $messages
     * @param  array<string, mixed>  $context
     */
    public function chat(array $messages, ?string $modelHint = null, float $temperature = 0.2, array $context = []): LoopChatResult
    {
        return $this->dispatch('chat', $context, function (string $providerId, string $modelId) use ($messages, $temperature) {
            $payload = $this->providers->get($this->driverFor($providerId))
                ->chat($providerId, $modelId, $messages, $temperature, $this->providers->configFor($providerId));

            return ['payload' => $payload];
        }, function (array $payload, string $providerId, string $modelId, string $requestId, int $latencyMs) {
            return new LoopChatResult($payload['payload'], $providerId, $modelId, $requestId, $latencyMs);
        }, ['model_hint' => $modelHint, 'temperature' => $temperature]);
    }

    /**
     * Run a chat completion and decode the assistant message as JSON.
     * Returns the decoded payload as an associative array, stripping
     * any markdown code fences around it.
     *
     * @param  array<int, array{role:string, content:string}>  $messages
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function chatStructured(array $messages, ?string $modelHint = null, array $context = []): array
    {
        $chat = $this->chat($messages, $modelHint, 0.0, $context);
        $content = $chat->content();

        if ($content === null || trim($content) === '') {
            throw new \RuntimeException('Chat server returned no structured content.');
        }

        $content = trim($content);
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/is', $content, $matches) === 1) {
            $content = $matches[1];
        }

        try {
            $decoded = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            // Some models emit raw control characters (literal newlines,
            // tabs) inside JSON string values, which json_decode rejects.
            // One repair pass escapes those; if the content is broken
            // beyond that, surface the original error.
            try {
                $decoded = json_decode($this->escapeControlCharsInStrings($content), true, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new \RuntimeException('Chat server returned invalid JSON.', previous: $exception);
            }
        }

        if (! is_array($decoded)) {
            throw new \RuntimeException('Structured chat response must be a JSON object.');
        }

        return $decoded;
    }

    /**
     * Escape raw control characters inside JSON string literals. Bytes
     * outside strings (pretty-printing whitespace, structural tokens)
     * pass through untouched; multibyte UTF-8 is preserved byte-for-byte
     * because only bytes < 0x20 are rewritten.
     */
    private function escapeControlCharsInStrings(string $json): string
    {
        $out = '';
        $inString = false;
        $length = strlen($json);

        for ($i = 0; $i < $length; $i++) {
            $char = $json[$i];

            if ($inString) {
                if ($char === '\\') {
                    $out .= $char.($json[$i + 1] ?? '');
                    $i++;

                    continue;
                }
                if ($char === '"') {
                    $inString = false;
                    $out .= $char;

                    continue;
                }
                $ord = ord($char);
                if ($ord < 0x20) {
                    $out .= sprintf('\u%04x', $ord);

                    continue;
                }
                $out .= $char;

                continue;
            }

            if ($char === '"') {
                $inString = true;
            }
            $out .= $char;
        }

        return $out;
    }

    /**
     * Stream chat completion deltas. Yields StreamChunk objects in the
     * order produced by the upstream provider.
     *
     * @param  array<int, array{role:string, content:string}>  $messages
     * @param  array<string, mixed>  $context
     * @return \Generator<int, StreamChunk, void, void>
     */
    public function stream(array $messages, ?string $modelHint = null, float $temperature = 0.2, array $context = []): \Generator
    {
        $task = (string) ($context['task'] ?? 'chat');
        $candidates = $this->orderedCandidates($task, $modelHint);
        $requestId = (string) Str::uuid();
        $errors = [];

        foreach ($candidates as $candidate) {
            $providerId = $candidate['provider'];
            $modelId = $candidate['model'];

            if (! $this->isConfigured($providerId)) {
                $errors[] = sprintf('%s: provider is not configured (missing base_url)', $providerId);

                continue;
            }

            try {
                $this->breaker->guard($providerId, $modelId);
                $this->rateLimiter->acquire($providerId, $modelId, $this->estimateTokens($messages));
                $lease = $this->concurrency->acquire($providerId, $modelId);
            } catch (CircuitOpenException|RateLimitExceededException $exception) {
                $errors[] = $exception::class.': '.$exception->getMessage();

                continue;
            }

            $startedAt = microtime(true);
            $adapter = $this->providers->get($this->driverFor($providerId));
            $config = $this->providers->configFor($providerId);

            try {
                $generator = $adapter->stream($providerId, $modelId, $messages, $temperature, $config);
                $accumulated = '';
                $usage = null;

                foreach ($generator as $chunk) {
                    $accumulated .= $chunk->delta;
                    if ($chunk->usage !== null) {
                        $usage = $chunk->usage;
                    }
                    yield $chunk;
                }

                $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
                $this->breaker->recordSuccess($providerId, $modelId);
                $this->rateLimiter->record($providerId, $modelId, $this->extractTotalTokens($usage));
                // A successful real call is the strongest health signal:
                // clears a stale probe failure so the provider is
                // promoted back within the candidate chain.
                $this->health->record($providerId, true, $latencyMs);

                $this->recorder->record(new LoopCallRecorded(
                    requestId: $requestId,
                    taskType: $task,
                    providerId: $providerId,
                    modelId: $modelId,
                    status: 'success',
                    latencyMs: $latencyMs,
                    promptTokens: $usage['prompt_tokens'] ?? null,
                    completionTokens: $usage['completion_tokens'] ?? null,
                    totalTokens: $usage['total_tokens'] ?? null,
                    context: $context,
                ));

                return;
            } catch (Throwable $exception) {
                $this->breaker->recordFailure($providerId, $modelId);
                $errors[] = $exception::class.': '.$exception->getMessage();
                $this->recorder->record(new LoopCallRecorded(
                    requestId: $requestId,
                    taskType: $task,
                    providerId: $providerId,
                    modelId: $modelId,
                    status: 'failed',
                    latencyMs: (int) round((microtime(true) - $startedAt) * 1000),
                    errorClass: $exception::class,
                    errorMessage: $exception->getMessage(),
                    context: $context,
                ));

                continue;
            } finally {
                $lease->release();
            }
        }

        throw new ProviderUnavailableException(sprintf(
            'All provider/model candidates failed for streamed task "%s". Errors: %s',
            $task, implode('; ', $errors),
        ));
    }

    public function listModels(string $providerId): array
    {
        $adapter = $this->providers->get($this->driverFor($providerId));

        return $adapter->listModels($providerId, $this->providers->configFor($providerId));
    }

    public function ping(string $providerId): bool
    {
        try {
            $adapter = $this->providers->get($this->driverFor($providerId));

            return $adapter->ping($providerId, $this->providers->configFor($providerId));
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Generic dispatch: walks the failover chain, applies rate limit /
     * circuit guard, and funnels the result through the supplied
     * factory closures. Returned objects come out of $resultFactory.
     *
     * @template T
     *
     * @param  array<string, mixed>  $context
     * @param  callable(string,string):array<string,mixed>  $call
     * @param  callable(array<string,mixed>,string,string,string,int):T  $resultFactory
     * @param  array<string, mixed>  $extras  extra fields to thread through (model_hint, temperature)
     * @return T
     */
    private function dispatch(string $task, array $context, callable $call, callable $resultFactory, array $extras = [])
    {
        $modelHint = $extras['model_hint'] ?? null;
        // Allow callers to override the routing task via context['task'].
        // Useful when the public method (chat/embed) is reused for
        // multiple logical roles (answer vs extract vs chat_direct).
        $task = (string) ($context['task'] ?? $task);
        $candidates = $this->orderedCandidates($task, is_string($modelHint) ? $modelHint : null);
        $requestId = (string) Str::uuid();
        $errors = [];
        $lastException = null;

        foreach ($candidates as $candidate) {
            $providerId = $candidate['provider'];
            $modelId = $candidate['model'];

            if (! $this->isConfigured($providerId)) {
                $errors[] = sprintf('%s: provider is not configured (missing base_url)', $providerId);

                continue;
            }

            try {
                $this->breaker->guard($providerId, $modelId);
                $this->rateLimiter->acquire($providerId, $modelId);
                $lease = $this->concurrency->acquire($providerId, $modelId);
            } catch (CircuitOpenException|RateLimitExceededException $exception) {
                $errors[] = $exception::class.': '.$exception->getMessage();
                $lastException = $exception;

                continue;
            }

            $startedAt = microtime(true);

            try {
                $payload = $call($providerId, $modelId);
                $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

                $this->breaker->recordSuccess($providerId, $modelId);
                $this->rateLimiter->record(
                    $providerId,
                    $modelId,
                    $this->extractTotalTokens($payload['payload']['usage'] ?? ($payload['usage'] ?? null)),
                );
                // Successful real call clears a stale probe failure (see stream()).
                $this->health->record($providerId, true, $latencyMs);

                $usage = $payload['payload']['usage'] ?? ($payload['usage'] ?? null);

                $this->recorder->record(new LoopCallRecorded(
                    requestId: $requestId,
                    taskType: $task,
                    providerId: $providerId,
                    modelId: $modelId,
                    status: 'success',
                    latencyMs: $latencyMs,
                    promptTokens: $usage['prompt_tokens'] ?? null,
                    completionTokens: $usage['completion_tokens'] ?? null,
                    totalTokens: $usage['total_tokens'] ?? null,
                    context: $context,
                ));

                return $resultFactory($payload, $providerId, $modelId, $requestId, $latencyMs);
            } catch (Throwable $exception) {
                $this->breaker->recordFailure($providerId, $modelId);
                $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
                $errors[] = $exception::class.': '.$exception->getMessage();
                $lastException = $exception;

                $this->recorder->record(new LoopCallRecorded(
                    requestId: $requestId,
                    taskType: $task,
                    providerId: $providerId,
                    modelId: $modelId,
                    status: 'failed',
                    latencyMs: $latencyMs,
                    errorClass: $exception::class,
                    errorMessage: $exception->getMessage(),
                    context: $context,
                ));

                continue;
            } finally {
                $lease->release();
            }
        }

        // Prefer surfacing the typed dispatcher exception (circuit open
        // / rate limit) directly so callers can switch on it. Only wrap
        // when the failures came from actual upstream call attempts.
        if ($lastException instanceof CircuitOpenException || $lastException instanceof RateLimitExceededException) {
            throw $lastException;
        }

        throw new ProviderUnavailableException(sprintf(
            'All provider/model candidates failed for task "%s". Errors: %s',
            $task, implode('; ', $errors),
        ), $lastException);
    }

    private function driverFor(string $providerId): string
    {
        $entry = $this->providers->providersConfig()[$providerId] ?? null;
        if (! is_array($entry) || empty($entry['driver'])) {
            throw new ProviderUnavailableException(sprintf('Provider "%s" is missing a `driver` declaration.', $providerId));
        }

        return (string) $entry['driver'];
    }

    /**
     * Candidate chain with providers whose latest health probe failed
     * moved to the back, so traffic automatically shifts away from down
     * providers. All other candidates keep the strategy order — healthy
     * and unknown states must not reorder round-robin rotation or
     * failover priority. Unhealthy providers are still tried as a last
     * resort.
     *
     * @return list<array{provider:string, model:string}>
     */
    private function orderedCandidates(string $task, ?string $modelHint): array
    {
        $candidates = $this->resolver->ordered($task, $modelHint);

        $live = [];
        $unhealthy = [];
        foreach ($candidates as $candidate) {
            if ($this->health->isHealthy($candidate['provider']) === false) {
                $unhealthy[] = $candidate;
            } else {
                $live[] = $candidate;
            }
        }

        return array_merge($live, $unhealthy);
    }

    /**
     * A candidate is only callable when its provider exists in
     * config and declares a base URL. Misconfigured candidates are
     * skipped without poisoning the circuit breaker or the call log —
     * a config gap is not an upstream failure.
     */
    private function isConfigured(string $providerId): bool
    {
        try {
            $config = $this->providers->configFor($providerId);
        } catch (\InvalidArgumentException) {
            return false;
        }

        return trim((string) ($config['base_url'] ?? '')) !== '';
    }

    private function estimateTokens(array $messages): int
    {
        // Rough heuristic: 1 token ≈ 4 chars. We over-estimate rather
        // than under-estimate so rate limit accounting stays safe.
        $chars = 0;
        foreach ($messages as $message) {
            $chars += strlen((string) ($message['content'] ?? ''));
        }

        return (int) ceil($chars / 4);
    }

    private function extractTotalTokens(?array $usage): int
    {
        if ($usage === null) {
            return 0;
        }

        return (int) ($usage['total_tokens'] ?? 0);
    }
}
