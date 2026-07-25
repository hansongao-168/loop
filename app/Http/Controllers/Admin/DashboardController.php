<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\KnowledgeBase;
use Illuminate\Support\Facades\Http;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $modelServer = ['online' => false, 'models' => []];
        try {
            $response = Http::baseUrl(config('services.ai.base_url'))->timeout(3)
                ->withToken(config('services.ai.api_key_upstream'))->get('/models');
            $modelServer = ['online' => $response->successful(), 'models' => $response->json('data', [])];
        } catch (\Throwable) {}

        return view('admin.dashboard', [
            'knowledgeBases' => KnowledgeBase::withCount(['documents'])->latest()->get(),
            'documentCount' => Document::count(), 'chunkCount' => DocumentChunk::count(),
            'modelServer' => $modelServer,
        ]);
    }
}
