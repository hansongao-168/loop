<?php

namespace App\Services\Ai;

use App\Services\Ai\Exceptions\ProviderUnavailableException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Picks the (provider, model) candidate chain for a given task and
 * orders it according to the configured strategy.
 *
 * Chains come from config('services.loop.models') and may be written as
 * a comma separated `provider@model` string (the common .env form) or as
 * a list of ['provider' => ..., 'model' => ...] arrays.
 *
 * Strategies:
 *   failover    - keep declaration order; LoopRouter walks the chain and
 *                 switches on failure.
 *   round_robin - rotate the starting candidate per request via a cache
 *                 counter so load spreads across models; failures still
 *                 fall over to the next candidate within one request.
 *   single      - only the first candidate is ever exposed.
 *
 * Tasks supported: embed, chat, extract, summary, rerank, answer,
 * chat_direct. Additional tasks can be added by extending
 * config('services.loop.models') with the same key.
 */
class ModelResolver
{
    public function __construct(
        private array $modelsConfig = [],
        private string $defaultStrategy = 'failover',
        private ?CacheRepository $cache = null,
    ) {}

    /**
     * Normalised candidate chain in declaration order (no strategy
     * applied).
     *
     * @return list<array{provider:string, model:string}>
     */
    public function candidates(string $task, ?string $modelHint = null): array
    {
        $chain = $this->normaliseChain($this->modelsConfig[$task] ?? []);

        if ($chain === []) {
            throw new ProviderUnavailableException(sprintf('No model candidates configured for task "%s".', $task));
        }

        // Caller provided an explicit model override (e.g. user passed `model`
        // in the chat request body). Pin it onto the first provider so
        // failover still works against the same upstream family.
        if (is_string($modelHint) && $modelHint !== '') {
            $chain[0]['model'] = $modelHint;
        }

        return $chain;
    }

    /**
     * Candidate chain with the configured strategy applied. LoopRouter
     * walks the returned order and still fails over within one request.
     *
     * @return list<array{provider:string, model:string}>
     */
    public function ordered(string $task, ?string $modelHint = null): array
    {
        $chain = $this->candidates($task, $modelHint);

        return match ($this->defaultStrategy) {
            'single' => [$chain[0]],
            'round_robin' => $this->rotate($task, $chain),
            default => $chain,
        };
    }

    public function strategy(): string
    {
        return $this->defaultStrategy;
    }

    /**
     * Rotate the chain so request N starts at candidate N % count. The
     * counter lives in the shared cache store so web workers and queue
     * workers rotate together.
     *
     * @param  list<array{provider:string, model:string}>  $chain
     * @return list<array{provider:string, model:string}>
     */
    private function rotate(string $task, array $chain): array
    {
        if (count($chain) < 2 || $this->cache === null) {
            return $chain;
        }

        $counter = (int) $this->cache->increment($this->rotationKey($task));
        if ($counter < 1) {
            // Cache stores without atomic increment may return garbage
            // or null on the first call; fall back to declaration order.
            $counter = 1;
            $this->cache->put($this->rotationKey($task), 1, 3600);
        }

        $offset = ($counter - 1) % count($chain);

        return array_merge(
            array_slice($chain, $offset),
            array_slice($chain, 0, $offset),
        );
    }

    /**
     * @param  mixed  $raw  string | array | null
     * @return list<array{provider:string, model:string}>
     */
    private function normaliseChain(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = array_map('trim', explode(',', $raw));
        }

        if (! is_array($raw)) {
            return [];
        }

        $chain = [];
        foreach ($raw as $entry) {
            $candidate = $this->normaliseEntry($entry);
            if ($candidate !== null) {
                $chain[] = $candidate;
            }
        }

        return $chain;
    }

    /**
     * @return array{provider:string, model:string}|null
     */
    private function normaliseEntry(mixed $entry): ?array
    {
        if (is_string($entry)) {
            $entry = trim($entry);
            if ($entry === '' || ! str_contains($entry, '@')) {
                return null;
            }

            $separator = strpos($entry, '@');

            return [
                'provider' => substr($entry, 0, $separator),
                'model' => substr($entry, $separator + 1),
            ];
        }

        if (is_array($entry) && ! empty($entry['provider']) && ! empty($entry['model'])) {
            return [
                'provider' => (string) $entry['provider'],
                'model' => (string) $entry['model'],
            ];
        }

        return null;
    }

    private function rotationKey(string $task): string
    {
        return sprintf('loop:rr:%s', $task);
    }
}
