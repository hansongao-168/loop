<?php

namespace App\Jobs;

use App\Models\GraphCommunityBuild;
use App\Services\CommunityBuildService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class BuildGraphCommunities implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1200;

    public function __construct(public int $buildId) {}

    public function middleware(): array
    {
        $build = GraphCommunityBuild::query()->find($this->buildId);
        $key = $build ? "community-build:{$build->knowledge_base_id}" : "community-build-record:{$this->buildId}";

        return [(new WithoutOverlapping($key))->expireAfter($this->timeout + 60)];
    }

    public function handle(CommunityBuildService $service): void
    {
        $build = GraphCommunityBuild::query()->with('knowledgeBase')->find($this->buildId);
        if (! $build || $build->status !== 'pending') {
            return;
        }

        $build->update(['status' => 'building', 'started_at' => now(), 'failure_reason' => null]);
        try {
            $result = $service->rebuild($build->knowledgeBase, $build->graph_version);
            $build->update([
                'status' => 'ready',
                'build_version' => $result['build_version'],
                'communities_count' => $result['communities'],
                'completed_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $build->update([
                'status' => 'failed',
                'failure_reason' => mb_substr($exception->getMessage(), 0, 2000),
                'completed_at' => now(),
            ]);
            throw $exception;
        }
    }
}
