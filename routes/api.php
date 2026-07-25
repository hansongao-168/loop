<?php

use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\KnowledgeBaseController;
use App\Http\Controllers\Api\RagController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => ['status' => 'ok', 'service' => config('app.name')]);

Route::middleware(['ai.key', 'throttle:60,1'])->group(function () {
    Route::apiResource('knowledge-bases', KnowledgeBaseController::class)->only(['index', 'store', 'destroy']);
    Route::post('knowledge-bases/{knowledgeBase}/documents', [RagController::class, 'ingest']);
    Route::post('knowledge-bases/{knowledgeBase}/query', [RagController::class, 'query']);
    Route::post('v1/chat/completions', ChatController::class);
});
