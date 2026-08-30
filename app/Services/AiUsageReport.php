<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Aggregates the ai_call_logs table for the admin dashboard. Keeps the
 * reporting SQL out of controllers (dependency rule: controllers must
 * not contain data-aggregation queries).
 *
 * All methods are best-effort: if the table is missing or empty they
 * return zeroed structures instead of failing the dashboard.
 */
class AiUsageReport
{
    /**
     * Overall call summary for the last N hours.
     *
     * @return array{calls:int, failures:int, tokens:int, avg_latency_ms:int}
     */
    public function summarise(int $hours = 24): array
    {
        try {
            $rows = DB::table('ai_call_logs')
                ->where('created_at', '>=', now()->subHours($hours))
                ->selectRaw('status, COUNT(*) as count, COALESCE(SUM(total_tokens),0) as tokens, COALESCE(AVG(latency_ms),0) as avg_latency')
                ->groupBy('status')
                ->get();

            $calls = (int) $rows->sum('count');

            return [
                'calls' => $calls,
                'failures' => (int) $rows->where('status', 'failed')->sum('count'),
                'tokens' => (int) $rows->sum('tokens'),
                'avg_latency_ms' => $calls > 0 ? (int) round((float) $rows->avg('avg_latency')) : 0,
            ];
        } catch (\Throwable) {
            return ['calls' => 0, 'failures' => 0, 'tokens' => 0, 'avg_latency_ms' => 0];
        }
    }

    /**
     * Per (provider, model) breakdown so multi-model switching is
     * observable: which models actually serve, their success rate and
     * latency.
     *
     * @return list<array{provider:string, model:string, task:string, calls:int, failures:int, tokens:int, avg_latency_ms:int}>
     */
    public function perModel(int $hours = 24, int $limit = 12): array
    {
        try {
            return DB::table('ai_call_logs')
                ->where('created_at', '>=', now()->subHours($hours))
                ->selectRaw('provider_id, model_id, task_type, COUNT(*) as calls, '
                    ."SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failures, "
                    .'COALESCE(SUM(total_tokens),0) as tokens, COALESCE(AVG(latency_ms),0) as avg_latency')
                ->groupBy('provider_id', 'model_id', 'task_type')
                ->orderByDesc('calls')
                ->limit($limit)
                ->get()
                ->map(fn ($row) => [
                    'provider' => (string) $row->provider_id,
                    'model' => (string) $row->model_id,
                    'task' => (string) $row->task_type,
                    'calls' => (int) $row->calls,
                    'failures' => (int) $row->failures,
                    'tokens' => (int) $row->tokens,
                    'avg_latency_ms' => (int) round((float) $row->avg_latency),
                ])
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }
}
