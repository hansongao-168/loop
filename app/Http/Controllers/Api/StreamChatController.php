<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Ai\Exceptions\ProviderUnavailableException;
use App\Services\Ai\LoopRouter;
use App\Services\Ai\StreamChunk;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * SSE streaming endpoint. Forwards chat completion deltas line by line
 * in the standard `data: {json}\n\n` SSE wire format. Wraps the
 * dispatcher's Generator so each yielded StreamChunk becomes one SSE
 * event; the loop also emits a final `data: [DONE]\n\n` sentinel so
 * clients can detect completion.
 *
 * Only the `chat` task is streamed; RAG answers still go through the
 * synchronous controller to keep citation numbering deterministic.
 */
class StreamChatController extends Controller
{
    public function __invoke(Request $request, LoopRouter $loop): StreamedResponse
    {
        $data = $request->validate([
            'messages' => ['required', 'array', 'min:1'],
            'messages.*.role' => ['required', 'in:system,user,assistant'],
            'messages.*.content' => ['required', 'string'],
            'model' => ['sometimes', 'string'],
            'temperature' => ['sometimes', 'numeric', 'between:0,2'],
        ]);

        $temperature = (float) ($data['temperature'] ?? 0.2);
        $modelHint = $data['model'] ?? null;

        $callback = function () use ($loop, $data, $temperature, $modelHint) {
            $encoder = null;
            try {
                foreach ($loop->stream($data['messages'], $modelHint, $temperature, ['task' => 'chat_direct']) as $chunk) {
                    $this->emit($chunk);
                }
                echo "data: [DONE]\n\n";
                @ob_flush();
                flush();
            } catch (ProviderUnavailableException $exception) {
                $this->emitError($exception->getMessage());
            } catch (\Throwable $exception) {
                $this->emitError($exception->getMessage());
            }
        };

        return response()->stream($callback, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private function emit(StreamChunk $chunk): void
    {
        echo 'data: '.json_encode($chunk->toArray(), JSON_UNESCAPED_UNICODE)."\n\n";
        @ob_flush();
        flush();
    }

    private function emitError(string $message): void
    {
        echo 'data: '.json_encode(['error' => $message])."\n\n";
        echo "data: [DONE]\n\n";
        @ob_flush();
        flush();
    }
}
