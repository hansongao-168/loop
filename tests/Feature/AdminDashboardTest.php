<?php

namespace Tests\Feature;

use App\Services\Ai\ProviderHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.loop.default_provider' => 'openai_compatible',
            'services.loop.providers' => [
                'openai_compatible' => [
                    'driver' => 'openai_compatible',
                    'base_url' => 'http://primary.test/v1',
                    'api_key' => 'test',
                    'timeout' => 5,
                ],
                'backup' => [
                    'driver' => 'openai_compatible',
                    'base_url' => 'http://backup.test/v1',
                    'api_key' => 'test',
                    'timeout' => 5,
                ],
                'not_configured' => [
                    'driver' => 'openai_compatible',
                    'base_url' => '',
                    'api_key' => 'test',
                ],
            ],
        ]);
    }

    public function test_dashboard_shows_provider_health_and_per_model_usage(): void
    {
        $health = app(ProviderHealth::class);
        $health->record('openai_compatible', true, 42);
        $health->record('backup', false, 3000);

        // One successful answer call on the primary model and one failed
        // embed that failed over to the backup model.
        $this->seedCall('openai_compatible', 'qwen3:8b', 'answer', 'success', 120, 14);
        $this->seedCall('openai_compatible', 'nomic-embed-text', 'embed', 'failed', 3000, 0);
        $this->seedCall('backup', 'qwen2.5:7b', 'embed', 'success', 900, 12);

        Http::fake([
            'http://primary.test/*/models' => Http::response(['data' => [['id' => 'qwen3:8b']]]),
        ]);

        $this->admin()->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Provider 健康')
            ->assertSee('http://backup.test/v1')
            ->assertSee('离线')
            ->assertSee('模型用量 (24h)')
            ->assertSee('openai_compatible / qwen3:8b')
            ->assertSee('backup / qwen2.5:7b');
    }

    public function test_dashboard_skips_live_model_probe_when_default_provider_is_probed_down(): void
    {
        app(ProviderHealth::class)->record('openai_compatible', false, 3000);

        // No HTTP fakes: if the controller still pings the provider the
        // request would attempt real network traffic and fail the test.
        $this->admin()->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('离线');
    }

    public function test_dashboard_requires_authentication(): void
    {
        $this->get(route('admin.dashboard'))->assertRedirect(route('admin.login'));
    }

    private function admin(): static
    {
        return $this->withSession(['admin_authenticated' => true]);
    }

    private function seedCall(
        string $provider,
        string $model,
        string $task,
        string $status,
        int $latencyMs,
        int $tokens,
    ): void {
        DB::table('ai_call_logs')->insert([
            'request_id' => (string) Str::uuid(),
            'provider_id' => $provider,
            'model_id' => $model,
            'task_type' => $task,
            'status' => $status,
            'latency_ms' => $latencyMs,
            'total_tokens' => $tokens,
            'created_at' => now(),
        ]);
    }
}
