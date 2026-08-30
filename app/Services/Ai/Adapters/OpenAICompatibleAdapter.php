<?php

namespace App\Services\Ai\Adapters;

use App\Services\Ai\ProviderAdapter;
use App\Services\Ai\StreamChunk;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Default adapter that talks to any OpenAI-compatible HTTP endpoint
 * (Ollama, vLLM, LM Studio, cloud gateways). Request shapes match the
 * original gateway client so existing providers keep working.
 */
class OpenAICompatibleAdapter implements ProviderAdapter
{
    public function __construct(private ?HttpFactory $factory = null) {}

    public function driverId(): string
    {
        return 'openai_compatible';
    }

    public function embed(string $providerId, string $modelId, string $text, array $config): array
    {
        $payload = $this->client($config)->post('/embeddings', [
            'model' => $modelId,
            'input' => $text,
        ])->throw()->json();

        $vector = data_get($payload, 'data.0.embedding');
        if (! is_array($vector) || $vector === []) {
            throw new RuntimeException('Embedding server returned no vector.');
        }

        $usage = data_get($payload, 'usage');
        if (! is_array($usage)) {
            $usage = null;
        }

        return [
            'vector' => array_map('floatval', $vector),
            'usage' => $usage,
        ];
    }

    public function chat(string $providerId, string $modelId, array $messages, float $temperature, array $config): array
    {
        return $this->client($config)->post('/chat/completions', [
            'model' => $modelId,
            'messages' => $messages,
            'temperature' => $temperature,
            'stream' => false,
        ])->throw()->json();
    }

    public function stream(string $providerId, string $modelId, array $messages, float $temperature, array $config): \Generator
    {
        $response = $this->client($config)->post('/chat/completions', [
            'model' => $modelId,
            'messages' => $messages,
            'temperature' => $temperature,
            'stream' => true,
        ]);

        $body = $response->throw()->toPsrResponse()->getBody();
        $buffer = '';

        while (! $body->eof()) {
            $chunk = $body->read(1024);
            if ($chunk === '') {
                continue;
            }
            $buffer .= $chunk;

            while (($position = strpos($buffer, "\n\n")) !== false) {
                $rawEvent = substr($buffer, 0, $position);
                $buffer = substr($buffer, $position + 2);
                $event = $this->parseSseEvent($rawEvent, $providerId, $modelId);
                if ($event !== null) {
                    yield $event;
                }
            }
        }

        if (trim($buffer) !== '') {
            $event = $this->parseSseEvent($buffer, $providerId, $modelId);
            if ($event !== null) {
                yield $event;
            }
        }
    }

    public function ping(string $providerId, array $config): bool
    {
        try {
            return $this->client($config, shortTimeout: true)->get('/models')->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function listModels(string $providerId, array $config): array
    {
        try {
            $payload = $this->client($config, shortTimeout: true)->get('/models')->throw()->json('data', []);
        } catch (\Throwable) {
            return [];
        }

        $ids = [];
        foreach (is_array($payload) ? $payload : [] as $entry) {
            if (isset($entry['id'])) {
                $ids[] = (string) $entry['id'];
            }
        }

        return $ids;
    }

    private function client(array $config, bool $shortTimeout = false)
    {
        $factory = $this->factory ?? Http::getFacadeRoot();
        $request = $factory
            ->baseUrl($config['base_url'] ?? '')
            ->withToken($config['api_key'] ?? '')
            ->acceptJson()
            ->timeout($shortTimeout ? 3 : (int) ($config['timeout'] ?? 120));

        $retry = $config['retry'] ?? [];
        if (! empty($retry['times'])) {
            $request = $request->retry((int) $retry['times'], (int) ($retry['sleep_ms'] ?? 300));
        }

        return $request;
    }

    private function parseSseEvent(string $raw, string $providerId, string $modelId): ?StreamChunk
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        // OpenAI-compatible gateways prefix each event with literal "data: ".
        $payloads = [];
        foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, ':')) {
                continue;
            }
            if (str_starts_with($line, 'data:')) {
                $payloads[] = trim(substr($line, 5));
            }
        }

        $delta = '';
        $finish = null;
        $usage = null;

        foreach ($payloads as $payload) {
            if ($payload === '[DONE]') {
                continue;
            }
            $decoded = json_decode($payload, true);
            if (! is_array($decoded)) {
                continue;
            }

            $piece = (string) data_get($decoded, 'choices.0.delta.content', '');
            if ($piece !== '') {
                $delta .= $piece;
            }

            $candidate = data_get($decoded, 'choices.0.finish_reason');
            if (is_string($candidate) && $candidate !== '') {
                $finish = $candidate;
            }

            if (isset($decoded['usage']) && is_array($decoded['usage'])) {
                $usage = $decoded['usage'];
            }
        }

        if ($delta === '' && $finish === null && $usage === null) {
            return null;
        }

        return new StreamChunk($delta, $finish, $usage, $providerId, $modelId);
    }
}
