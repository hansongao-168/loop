<?php

namespace App\Services\Ai\Recording;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Persists LoopCallRecorded events to the ai_call_logs table. Wired up
 * via AppServiceProvider so the rest of the code path stays decoupled
 * from storage. Inserts are best-effort: if the log insert fails (DB
 * down, table missing) we log and swallow the error rather than
 * breaking the upstream call.
 */
class LoopCallRecorderListener
{
    public function handle(LoopCallRecorded $event): void
    {
        try {
            DB::table('ai_call_logs')->insert([
                'request_id' => $event->requestId,
                'provider_id' => $event->providerId,
                'model_id' => $event->modelId,
                'task_type' => $event->taskType,
                'status' => $event->status,
                'latency_ms' => $event->latencyMs,
                'prompt_tokens' => $event->promptTokens,
                'completion_tokens' => $event->completionTokens,
                'total_tokens' => $event->totalTokens,
                'knowledge_base_id' => $event->context['knowledge_base_id'] ?? null,
                'document_id' => $event->context['document_id'] ?? null,
                'document_chunk_id' => $event->context['document_chunk_id'] ?? null,
                'error_class' => $event->errorClass,
                'error_message' => $event->errorMessage,
                'metadata' => isset($event->context['metadata']) ? json_encode($event->context['metadata']) : null,
                'created_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            // best-effort; surface diagnostic to log without breaking callers
            Log::warning('LoopCallRecorderListener failed to persist ai_call_log row.', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
