<?php

namespace App\Providers;

use App\Models\Document;
use App\Services\Ai\Circuit\CircuitBreaker;
use App\Services\Ai\Limits\ConcurrencyGate;
use App\Services\Ai\Limits\TokenBucketLimiter;
use App\Services\Ai\LoopRouter;
use App\Services\Ai\ModelResolver;
use App\Services\Ai\ProviderHealth;
use App\Services\Ai\ProviderRegistry;
use App\Services\Ai\Recording\LoopCallRecorded;
use App\Services\Ai\Recording\LoopCallRecorderListener;
use App\Services\Ai\Recording\UsageRecorder;
use App\Services\GraphRepository;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ProviderRegistry::class, function ($app) {
            return new ProviderRegistry(
                (array) config('services.loop.providers', []),
            );
        });

        $this->app->singleton(ModelResolver::class, function ($app) {
            return new ModelResolver(
                (array) config('services.loop.models', []),
                (string) config('services.loop.default_strategy', 'failover'),
                $app->make(CacheRepository::class),
            );
        });

        $this->app->singleton(ProviderHealth::class, function () {
            return new ProviderHealth(
                app(CacheRepository::class),
                (int) config('services.loop.health.ttl_seconds', 300),
            );
        });

        $this->app->singleton(TokenBucketLimiter::class, function ($app) {
            return new TokenBucketLimiter(
                $app->make(CacheRepository::class),
                (array) config('services.loop.limits.default', []),
                (array) config('services.loop.limits.per_pair', []),
            );
        });

        $this->app->singleton(ConcurrencyGate::class, function ($app) {
            return new ConcurrencyGate(
                $app->make(CacheRepository::class),
                (array) config('services.loop.limits.default', []),
                (array) config('services.loop.limits.per_pair', []),
            );
        });

        $this->app->singleton(CircuitBreaker::class, function () {
            return new CircuitBreaker(
                $this->app->make(CacheRepository::class),
                (int) config('services.loop.circuit.failure_threshold', 5),
                (int) config('services.loop.circuit.cooldown_seconds', 30),
            );
        });

        $this->app->singleton(UsageRecorder::class, function () {
            return new UsageRecorder(
                (bool) config('services.loop.recording.enabled', true),
                (float) config('services.loop.recording.sample_rate', 1.0),
            );
        });

        $this->app->singleton(LoopRouter::class, function ($app) {
            return new LoopRouter(
                $app->make(ProviderRegistry::class),
                $app->make(ModelResolver::class),
                $app->make(TokenBucketLimiter::class),
                $app->make(ConcurrencyGate::class),
                $app->make(CircuitBreaker::class),
                $app->make(UsageRecorder::class),
                $app->make(ProviderHealth::class),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(LoopCallRecorded::class, LoopCallRecorderListener::class);

        // Composition-root wiring for model events: the model layer must
        // not know about services, so the graph cleanup that must follow
        // a document deletion is registered here.
        Document::deleted(function (Document $document) {
            app(GraphRepository::class)->removeOrphans($document->knowledge_base_id);
        });
    }
}
