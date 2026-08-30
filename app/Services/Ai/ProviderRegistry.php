<?php

namespace App\Services\Ai;

use App\Services\Ai\Adapters\OpenAICompatibleAdapter;
use InvalidArgumentException;

/**
 * Registry of provider adapters keyed by driver id. Reads
 * config('services.loop.providers') to build the lookup map; each entry
 * must declare a `driver` that resolves to a registered adapter class.
 */
class ProviderRegistry
{
    /** @var array<string, ProviderAdapter> */
    private array $adapters = [];

    public function __construct(private array $providersConfig = [])
    {
        $this->register(new OpenAICompatibleAdapter);
    }

    public function register(ProviderAdapter $adapter): void
    {
        $this->adapters[$adapter->driverId()] = $adapter;
    }

    public function get(string $driverId): ProviderAdapter
    {
        if (! isset($this->adapters[$driverId])) {
            throw new InvalidArgumentException(sprintf('No provider adapter registered for driver "%s".', $driverId));
        }

        return $this->adapters[$driverId];
    }

    public function has(string $driverId): bool
    {
        return isset($this->adapters[$driverId]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function providersConfig(): array
    {
        return $this->providersConfig;
    }

    public function configFor(string $providerId): array
    {
        $entry = $this->providersConfig[$providerId] ?? null;
        if (! is_array($entry)) {
            throw new InvalidArgumentException(sprintf('Provider "%s" is not configured under services.loop.providers.', $providerId));
        }

        return $entry;
    }
}
