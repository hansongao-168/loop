<?php

namespace Tests\Feature;

use App\Models\KnowledgeBase;
use App\Services\Ai\Exceptions\CircuitOpenException;
use App\Services\Ai\Exceptions\ProviderUnavailableException;
use App\Services\Ai\Exceptions\RateLimitExceededException;
use App\Services\Ai\LoopRouter;
use App\Services\Ai\ProviderHealth;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LoopRouterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.ai.api_key' => 'test-key',
            'services.loop.default_provider' => 'openai_compatible',
            'services.loop.default_strategy' => 'failover',
            'services.loop.providers' => [
                'openai_compatible' => [
                    'driver' => 'openai_compatible',
                    'base_url' => 'http://primary.test/v1',
                    'api_key' => 'test',
                    'timeout' => 5,
                    'retry' => ['times' => 0, 'sleep_ms' => 0],
                ],
                'openai_compatible_backup' => [
                    'driver' => 'openai_compatible',
                    'base_url' => 'http://backup.test/v1',
                    'api_key' => 'test',
                    'timeout' => 5,
                    'retry' => ['times' => 0, 'sleep_ms' => 0],
                ],
            ],
            'services.loop.models' => [
                'chat_direct' => [
                    ['provider' => 'openai_compatible', 'model' => 'qwen3:8b'],
                ],
                'chat_direct_failover' => [
                    ['provider' => 'openai_compatible', 'model' => 'qwen3:8b'],
                    ['provider' => 'openai_compatible_backup', 'model' => 'qwen3:8b'],
                ],
                'embed' => [
                    ['provider' => 'openai_compatible', 'model' => 'nomic-embed-text'],
                ],
                'chat' => [
                    ['provider' => 'openai_compatible', 'model' => 'qwen3:8b'],
                ],
                'extract' => [['provider' => 'openai_compatible', 'model' => 'qwen3:8b']],
                'summary' => [['provider' => 'openai_compatible', 'model' => 'qwen3:8b']],
                'rerank' => [['provider' => 'openai_compatible', 'model' => 'qwen3:8b']],
                'answer' => [['provider' => 'openai_compatible', 'model' => 'qwen3:8b']],
            ],
            'services.loop.circuit.failure_threshold' => 3,
            'services.loop.circuit.cooldown_seconds' => 30,
            'services.loop.limits.default' => ['rpm' => 0, 'tpm' => 0, 'concurrency' => 0],
            'services.loop.recording.enabled' => true,
            'services.loop.recording.sample_rate' => 1.0,
        ]);
    }

    public function test_embed_returns_vector_and_records_usage_to_ai_call_logs(): void
    {
        Http::fake([
            '*/embeddings' => Http::response([
                'data' => [['embedding' => [0.1, 0.2, 0.3]]],
                'usage' => ['prompt_tokens' => 5, 'total_tokens' => 5],
            ]),
        ]);

        $result = app(LoopRouter::class)->embed('hello');

        $this->assertSame([0.1, 0.2, 0.3], $result->vector);
        $this->assertSame('nomic-embed-text', $result->modelId);
        $this->assertSame('openai_compatible', $result->providerId);
        $this->assertDatabaseHas('ai_call_logs', [
            'task_type' => 'embed',
            'provider_id' => 'openai_compatible',
            'model_id' => 'nomic-embed-text',
            'status' => 'success',
            'prompt_tokens' => 5,
            'total_tokens' => 5,
        ]);
    }

    public function test_chat_returns_structured_payload_and_records_usage(): void
    {
        $knowledgeBase = KnowledgeBase::create(['name' => 'Logs']);
        Http::fake([
            '*/chat/completions' => Http::response([
                'model' => 'qwen3:8b',
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'answer [1]']]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 4, 'total_tokens' => 14],
            ]),
        ]);

        $result = app(LoopRouter::class)->chat(
            [['role' => 'user', 'content' => 'q']],
            null,
            0.2,
            ['task' => 'answer', 'knowledge_base_id' => $knowledgeBase->id],
        );

        $this->assertSame('answer [1]', $result->content());
        $this->assertSame('qwen3:8b', $result->resolvedModel());
        $this->assertDatabaseHas('ai_call_logs', [
            'task_type' => 'answer',
            'status' => 'success',
            'prompt_tokens' => 10,
            'completion_tokens' => 4,
            'total_tokens' => 14,
            'knowledge_base_id' => $knowledgeBase->id,
        ]);
    }

    public function test_chat_structured_strips_markdown_fences_and_returns_array(): void
    {
        Http::fake([
            '*/chat/completions' => Http::response([
                'model' => 'qwen3:8b',
                'choices' => [['message' => ['role' => 'assistant', 'content' => "```json\n{\"answer\":42}\n```"]]],
            ]),
        ]);

        $result = app(LoopRouter::class)->chatStructured(
            [['role' => 'user', 'content' => 'q']],
            null,
            ['task' => 'extract'],
        );

        $this->assertSame(['answer' => 42], $result);
    }

    public function test_failover_walks_to_the_second_provider_when_the_first_returns_503(): void
    {
        Http::fake([
            '*/chat/completions' => function ($request) {
                $url = $request->url();
                if (str_contains($url, 'primary.test')) {
                    return Http::response(['error' => 'upstream busy'], 503);
                }

                return Http::response([
                    'model' => 'qwen3:8b',
                    'choices' => [['message' => ['role' => 'assistant', 'content' => 'fallback ok']]],
                ]);
            },
        ]);

        $result = app(LoopRouter::class)->chat(
            [['role' => 'user', 'content' => 'q']],
            null,
            0.2,
            ['task' => 'chat_direct_failover'],
        );

        $this->assertSame('fallback ok', $result->content());
        $this->assertSame('openai_compatible_backup', $result->providerId);
        $this->assertDatabaseHas('ai_call_logs', [
            'provider_id' => 'openai_compatible',
            'status' => 'failed',
        ]);
        $this->assertDatabaseHas('ai_call_logs', [
            'provider_id' => 'openai_compatible_backup',
            'status' => 'success',
        ]);
    }

    public function test_circuit_breaker_short_circuits_after_repeated_failures(): void
    {
        config(['services.loop.circuit.failure_threshold' => 2]);
        Http::fake([
            '*/chat/completions' => Http::response(['error' => 'busy'], 503),
        ]);

        $router = app(LoopRouter::class);

        // 2 failures recorded → circuit should now be open
        for ($i = 0; $i < 2; $i++) {
            try {
                $router->chat([['role' => 'user', 'content' => "q{$i}"]], null, 0.2, ['task' => 'chat_direct']);
                $this->fail('Expected provider to fail before circuit opens.');
            } catch (ProviderUnavailableException) {
                // expected
            }
        }

        // Third attempt must short-circuit without HTTP traffic
        Http::fake([]); // wipe fakes; if LoopRouter reaches the wire the test will fail
        $this->expectException(CircuitOpenException::class);
        $router->chat([['role' => 'user', 'content' => 'q']], null, 0.2, ['task' => 'chat_direct']);
    }

    public function test_rpm_rate_limit_throws_when_budget_exhausted(): void
    {
        config(['services.loop.limits.default' => ['rpm' => 1, 'tpm' => 0, 'concurrency' => 0]]);
        Http::fake([
            '*/embeddings' => Http::response(['data' => [['embedding' => [0.1]]]]),
        ]);

        $router = app(LoopRouter::class);
        $router->embed('first');

        $this->expectException(RateLimitExceededException::class);
        $router->embed('second');
    }

    public function test_streaming_yields_each_sse_chunk(): void
    {
        $sse = "data: {\"choices\":[{\"delta\":{\"content\":\"Hel\"}}]}\n\n"
            ."data: {\"choices\":[{\"delta\":{\"content\":\"lo\"}}]}\n\n"
            ."data: {\"choices\":[{\"finish_reason\":\"stop\"}]}\n\n"
            ."data: [DONE]\n\n";

        Http::fake([
            '*/chat/completions' => Http::response($sse, 200, ['Content-Type' => 'text/event-stream']),
        ]);

        $chunks = [];
        foreach (app(LoopRouter::class)->stream(
            [['role' => 'user', 'content' => 'q']],
            null,
            0.2,
            ['task' => 'chat_direct'],
        ) as $chunk) {
            $chunks[] = $chunk;
        }

        $this->assertCount(3, $chunks);
        $this->assertSame('Hel', $chunks[0]->delta);
        $this->assertSame('lo', $chunks[1]->delta);
        $this->assertSame('stop', $chunks[2]->finishReason);
        $this->assertDatabaseHas('ai_call_logs', [
            'task_type' => 'chat_direct',
            'status' => 'success',
        ]);
    }

    public function test_ping_returns_true_when_provider_responds(): void
    {
        Http::fake([
            '*/models' => Http::response(['data' => [['id' => 'qwen3:8b'], ['id' => 'nomic-embed-text']]]),
        ]);

        $this->assertTrue(app(LoopRouter::class)->ping('openai_compatible'));
        $this->assertSame(['qwen3:8b', 'nomic-embed-text'], app(LoopRouter::class)->listModels('openai_compatible'));
    }

    public function test_ping_returns_false_when_provider_fails(): void
    {
        Http::fake([
            '*/models' => Http::response(['error' => 'down'], 500),
        ]);

        $this->assertFalse(app(LoopRouter::class)->ping('openai_compatible'));
        $this->assertSame([], app(LoopRouter::class)->listModels('openai_compatible'));
    }

    public function test_provider_unavailable_when_chain_is_empty(): void
    {
        config(['services.loop.models' => ['embed' => []]]);

        $this->expectException(ProviderUnavailableException::class);
        app(LoopRouter::class)->embed('hello');
    }

    public function test_circuit_open_exception_is_typed_for_callers(): void
    {
        // Type sanity: the exception carries provider/model/cooldown and
        // is throwable so future code can catch it specifically.
        $exception = new CircuitOpenException('openai_compatible', 'qwen3:8b', 30);
        $this->assertSame('openai_compatible', $exception->providerId);
        $this->assertSame('qwen3:8b', $exception->modelId);
        $this->assertSame(30, $exception->cooldownSeconds);
    }

    public function test_round_robin_rotates_the_starting_candidate_per_request(): void
    {
        config([
            'services.loop.default_strategy' => 'round_robin',
            'services.loop.models.chat_direct_rr' => [
                ['provider' => 'openai_compatible', 'model' => 'primary-model'],
                ['provider' => 'openai_compatible_backup', 'model' => 'backup-model'],
            ],
        ]);
        // Seed the rotation counter so the sequence is deterministic.
        Cache::store()->put('loop:rr:chat_direct_rr', 0, 60);

        Http::fake([
            '*/chat/completions' => fn () => Http::response([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'ok']]],
            ]),
        ]);

        $router = app(LoopRouter::class);
        $first = $router->chat([['role' => 'user', 'content' => 'q']], null, 0.2, ['task' => 'chat_direct_rr']);
        $second = $router->chat([['role' => 'user', 'content' => 'q']], null, 0.2, ['task' => 'chat_direct_rr']);
        $third = $router->chat([['role' => 'user', 'content' => 'q']], null, 0.2, ['task' => 'chat_direct_rr']);

        $this->assertSame('openai_compatible', $first->providerId);
        $this->assertSame('openai_compatible_backup', $second->providerId);
        // Wraps back to the first candidate after a full cycle.
        $this->assertSame('openai_compatible', $third->providerId);
    }

    public function test_single_strategy_never_fails_over_to_the_backup(): void
    {
        config([
            'services.loop.default_strategy' => 'single',
            'services.loop.models.chat_direct_single' => [
                ['provider' => 'openai_compatible', 'model' => 'm1'],
                ['provider' => 'openai_compatible_backup', 'model' => 'm2'],
            ],
        ]);

        Http::fake([
            '*/chat/completions' => Http::response(['error' => 'busy'], 503),
        ]);

        try {
            app(LoopRouter::class)->chat([['role' => 'user', 'content' => 'q']], null, 0.2, ['task' => 'chat_direct_single']);
            $this->fail('Expected the single-strategy call to fail.');
        } catch (ProviderUnavailableException) {
            // expected
        }

        // Only the primary candidate may be attempted — no failover.
        Http::assertSentCount(1);
    }

    public function test_unhealthy_provider_is_deprioritized_for_new_requests(): void
    {
        config(['services.loop.models.chat_direct_hc' => [
            ['provider' => 'openai_compatible', 'model' => 'm1'],
            ['provider' => 'openai_compatible_backup', 'model' => 'm2'],
        ]]);

        app(ProviderHealth::class)->record('openai_compatible', false);

        Http::fake([
            '*/chat/completions' => fn () => Http::response([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'from backup']]],
            ]),
        ]);

        $result = app(LoopRouter::class)->chat([['role' => 'user', 'content' => 'q']], null, 0.2, ['task' => 'chat_direct_hc']);

        // The probed-unhealthy primary is skipped without an attempt;
        // the healthy backup serves the request directly.
        $this->assertSame('openai_compatible_backup', $result->providerId);
        Http::assertSentCount(1);
    }

    public function test_successful_call_clears_a_stale_unhealthy_probe(): void
    {
        config(['services.loop.models.chat_direct_recover' => [
            ['provider' => 'openai_compatible_backup', 'model' => 'm2'],
        ]]);

        $health = app(ProviderHealth::class);
        $health->record('openai_compatible_backup', false);

        Http::fake([
            '*/chat/completions' => fn () => Http::response([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'ok']]],
            ]),
        ]);

        app(LoopRouter::class)->chat([['role' => 'user', 'content' => 'q']], null, 0.2, ['task' => 'chat_direct_recover']);

        $this->assertTrue($health->isHealthy('openai_compatible_backup'));
    }

    public function test_misconfigured_provider_candidate_is_skipped(): void
    {
        config([
            'services.loop.providers' => [
                'openai_compatible' => [
                    'driver' => 'openai_compatible',
                    'base_url' => '', // not configured
                    'api_key' => 'test',
                    'timeout' => 5,
                ],
                'openai_compatible_backup' => [
                    'driver' => 'openai_compatible',
                    'base_url' => 'http://backup.test/v1',
                    'api_key' => 'test',
                    'timeout' => 5,
                ],
            ],
            'services.loop.models.chat_direct_missing' => [
                ['provider' => 'openai_compatible', 'model' => 'm1'],
                ['provider' => 'openai_compatible_backup', 'model' => 'm2'],
            ],
        ]);

        Http::fake([
            '*/chat/completions' => fn () => Http::response([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'fallback ok']]],
            ]),
        ]);

        $result = app(LoopRouter::class)->chat([['role' => 'user', 'content' => 'q']], null, 0.2, ['task' => 'chat_direct_missing']);

        $this->assertSame('openai_compatible_backup', $result->providerId);
        Http::assertSentCount(1);
    }

    public function test_loop_health_command_records_probe_results(): void
    {
        Http::fake([
            'http://primary.test/*/models' => Http::response(['data' => []]),
            'http://backup.test/*/models' => Http::response(['error' => 'down'], 500),
        ]);

        $this->artisan('loop:health')->assertExitCode(Command::FAILURE);

        $health = app(ProviderHealth::class);
        $this->assertTrue($health->isHealthy('openai_compatible'));
        $this->assertFalse($health->isHealthy('openai_compatible_backup'));
    }
}
