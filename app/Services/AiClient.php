<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class AiClient
{
    private function client(): PendingRequest
    {
        return Http::baseUrl(config('services.ai.base_url'))
            ->withToken(config('services.ai.api_key_upstream'))
            ->acceptJson()
            ->timeout(config('services.ai.timeout'))
            ->retry(2, 300);
    }

    /** @return list<float> */
    public function embed(string $text): array
    {
        $response = $this->client()->post('/embeddings', [
            'model' => config('services.ai.embedding_model'),
            'input' => $text,
        ])->throw()->json('data.0.embedding');

        if (! is_array($response) || $response === []) {
            throw new \RuntimeException('Embedding server returned no vector.');
        }

        return array_map('floatval', $response);
    }

    /** @param array<int, array{role:string, content:string}> $messages */
    public function chat(array $messages, ?string $model = null, float $temperature = 0.2): array
    {
        return $this->client()->post('/chat/completions', [
            'model' => $model ?: config('services.ai.chat_model'),
            'messages' => $messages,
            'temperature' => $temperature,
            'stream' => false,
        ])->throw()->json();
    }
}
