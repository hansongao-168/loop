<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\BuildGraphCommunities;
use App\Models\Document;
use App\Models\GraphEntity;
use App\Models\KnowledgeBase;
use App\Services\DocumentIngestor;
use App\Services\RagQueryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class KnowledgeController extends Controller
{
    public function storeBase(Request $request): RedirectResponse
    {
        KnowledgeBase::create($request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]));

        return back()->with('success', '知识库已创建。');
    }

    public function show(Request $request, KnowledgeBase $knowledgeBase): View
    {
        return view('admin.knowledge', $this->viewData($request, $knowledgeBase));
    }

    public function ingest(Request $request, KnowledgeBase $knowledgeBase, DocumentIngestor $ingestor): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'source' => ['nullable', 'string', 'max:2048'],
            'content' => ['required', 'string'],
            'async' => ['sometimes', 'boolean'],
        ]);

        try {
            if ($data['async'] ?? false) {
                $ingestor->ingestAsync($knowledgeBase, $data);

                return back()->with('success', '文档已进入异步索引队列。');
            }
            $ingestor->ingest($knowledgeBase, $data);
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['content' => '导入失败，请检查模型服务或改用异步导入。']);
        }

        return back()->with('success', '文档已切片并写入知识库。');
    }

    public function retryDocument(Document $document, DocumentIngestor $ingestor): RedirectResponse
    {
        if ($document->status !== 'failed') {
            return back()->withErrors(['document' => '只有失败的文档可以重试。']);
        }
        $ingestor->retryIndex($document);

        return back()->with('success', '文档已重新进入索引队列。');
    }

    public function destroyDocument(Document $document): RedirectResponse
    {
        $document->delete();

        return back()->with('success', '文档已删除。');
    }

    public function query(Request $request, KnowledgeBase $knowledgeBase, RagQueryService $queries): View
    {
        $data = $request->validate([
            'question' => ['required', 'string', 'max:10000'],
            'mode' => ['sometimes', 'string', 'in:auto,local,global,vector'],
        ]);
        $result = null;
        $error = null;
        try {
            $result = $queries->ask($knowledgeBase, $data['question'], [
                'mode' => $data['mode'] ?? 'auto',
                'include_graph' => true,
            ]);
        } catch (Throwable $exception) {
            report($exception);
            $error = '问答失败，请确认模型服务正在运行。';
        }

        return view('admin.knowledge', $this->viewData($request, $knowledgeBase) + [
            'result' => $result,
            'question' => $data['question'],
            'queryMode' => $data['mode'] ?? 'auto',
            'queryError' => $error,
        ]);
    }

    public function rebuildCommunities(KnowledgeBase $knowledgeBase): RedirectResponse
    {
        if ($knowledgeBase->graphEntities()->doesntExist()) {
            return back()->withErrors(['graph' => '图谱中没有实体，无法构建社区。']);
        }
        $build = $knowledgeBase->graphCommunityBuilds()->create([
            'graph_version' => $knowledgeBase->fresh()->graph_version,
            'status' => 'pending',
        ]);
        BuildGraphCommunities::dispatch($build->id);

        return back()->with('success', '社区重建任务已进入队列。');
    }

    public function entity(KnowledgeBase $knowledgeBase, GraphEntity $entity): View
    {
        abort_unless($entity->knowledge_base_id === $knowledgeBase->id, 404);
        $entity->load([
            'mentions.chunk.document:id,knowledge_base_id,title,source',
            'outgoingRelationships.targetEntity',
            'outgoingRelationships.evidence.chunk.document:id,knowledge_base_id,title,source',
            'incomingRelationships.sourceEntity',
            'incomingRelationships.evidence.chunk.document:id,knowledge_base_id,title,source',
        ]);

        return view('admin.graph-entity', compact('knowledgeBase', 'entity'));
    }

    private function viewData(Request $request, KnowledgeBase $knowledgeBase): array
    {
        $knowledgeBase->load(['documents' => fn ($query) => $query->withCount('chunks')->latest()]);
        $search = trim((string) $request->query('entity_search', ''));
        $entities = $knowledgeBase->graphEntities()
            ->withCount(['mentions', 'incomingRelationships', 'outgoingRelationships'])
            ->when($search !== '', fn ($query) => $query->where('canonical_name', 'like', '%'.$search.'%'))
            ->orderByDesc('mentions_count')
            ->orderBy('canonical_name')
            ->paginate(15, ['*'], 'entity_page')
            ->withQueryString();

        return [
            'knowledgeBase' => $knowledgeBase,
            'entities' => $entities,
            'entitySearch' => $search,
            'graphStats' => [
                'entities' => $knowledgeBase->graphEntities()->count(),
                'relationships' => $knowledgeBase->graphRelationships()->count(),
                'communities' => $knowledgeBase->graphCommunities()->count(),
                'graph_version' => $knowledgeBase->graph_version,
            ],
            'communityBuilds' => $knowledgeBase->graphCommunityBuilds()->latest()->limit(5)->get(),
        ];
    }
}
