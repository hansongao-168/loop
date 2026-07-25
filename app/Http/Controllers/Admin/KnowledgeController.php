<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\KnowledgeBase;
use App\Services\RagService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class KnowledgeController extends Controller
{
    public function storeBase(Request $request): RedirectResponse
    {
        KnowledgeBase::create($request->validate(['name' => ['required','string','max:255'], 'description' => ['nullable','string']]));
        return back()->with('success', '知识库已创建。');
    }
    public function show(KnowledgeBase $knowledgeBase): View
    {
        return view('admin.knowledge', ['knowledgeBase' => $knowledgeBase->load(['documents' => fn ($q) => $q->withCount('chunks')->latest()])]);
    }
    public function ingest(Request $request, KnowledgeBase $knowledgeBase, RagService $rag): RedirectResponse
    {
        $data = $request->validate(['title' => ['required','string','max:255'], 'source' => ['nullable','string','max:2048'], 'content' => ['required','string']]);
        try { $rag->ingest($knowledgeBase, $data); }
        catch (\Throwable $e) { report($e); return back()->withErrors(['content' => '导入失败，请检查 LLM/Embedding 服务器是否已启动。']); }
        return back()->with('success', '文档已切片并写入知识库。');
    }
    public function destroyDocument(Document $document): RedirectResponse
    {
        $document->delete();
        return back()->with('success', '文档已删除。');
    }
    public function query(Request $request, KnowledgeBase $knowledgeBase, RagService $rag): View
    {
        $data = $request->validate(['question' => ['required','string','max:10000']]);
        $result = null; $error = null;
        try { $result = $rag->ask($knowledgeBase, $data['question']); }
        catch (\Throwable $e) { report($e); $error = '问答失败，请确认模型已经下载并且 Ollama 正在运行。'; }
        return view('admin.knowledge', ['knowledgeBase' => $knowledgeBase->load(['documents' => fn ($q) => $q->withCount('chunks')->latest()]), 'result' => $result, 'question' => $data['question'], 'queryError' => $error]);
    }
}
