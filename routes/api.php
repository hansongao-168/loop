<?php

use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\KnowledgeBaseController;
use App\Http\Controllers\Api\KnowledgeGraphController;
use App\Http\Controllers\Api\RagController;
use App\Http\Controllers\Api\StreamChatController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => ['status' => 'ok', 'service' => config('app.name')]);

Route::middleware(['ai.key', 'throttle:60,1'])->group(function () {
    Route::apiResource('knowledge-bases', KnowledgeBaseController::class)->only(['index', 'store', 'destroy']);
    Route::post('knowledge-bases/{knowledgeBase}/documents', [RagController::class, 'ingest']);
    Route::get('knowledge-bases/{knowledgeBase}/documents/{document}/index-status', [RagController::class, 'status']);
    Route::post('knowledge-bases/{knowledgeBase}/documents/{document}/retry-index', [RagController::class, 'retry']);
    Route::post('knowledge-bases/{knowledgeBase}/query', [RagController::class, 'query']);
    Route::get('knowledge-bases/{knowledgeBase}/graph', [KnowledgeGraphController::class, 'index']);
    Route::post('knowledge-bases/{knowledgeBase}/graph/rebuild-communities', [KnowledgeGraphController::class, 'rebuildCommunities']);
    Route::get('knowledge-bases/{knowledgeBase}/graph/community-builds/{build}', [KnowledgeGraphController::class, 'communityBuildStatus']);
    Route::get('knowledge-bases/{knowledgeBase}/graph/entities/{entity}', [KnowledgeGraphController::class, 'show']);
    Route::post('v1/chat/completions', ChatController::class);
    Route::post('v1/chat/stream', StreamChatController::class);
});
