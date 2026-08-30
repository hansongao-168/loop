<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\BuildGraphCommunities;
use App\Models\GraphCommunityBuild;
use App\Models\GraphEntity;
use App\Models\KnowledgeBase;
use App\Services\CommunityBuildService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KnowledgeGraphController extends Controller
{
    public function rebuildCommunities(Request $request, KnowledgeBase $knowledgeBase, CommunityBuildService $communities): JsonResponse
    {
        $data = $request->validate(['async' => ['sometimes', 'boolean']]);
        abort_if($knowledgeBase->graphEntities()->doesntExist(), 409, 'The knowledge graph has no entities.');

        if ($data['async'] ?? false) {
            $build = $knowledgeBase->graphCommunityBuilds()->create([
                'graph_version' => $knowledgeBase->fresh()->graph_version,
                'status' => 'pending',
            ]);
            BuildGraphCommunities::dispatch($build->id);

            return response()->json($build, 202);
        }

        return response()->json($communities->rebuild($knowledgeBase));
    }

    public function communityBuildStatus(KnowledgeBase $knowledgeBase, GraphCommunityBuild $build): JsonResponse
    {
        abort_unless($build->knowledge_base_id === $knowledgeBase->id, 404);

        return response()->json($build);
    }

    public function index(Request $request, KnowledgeBase $knowledgeBase): JsonResponse
    {
        $data = $request->validate([
            'type' => ['sometimes', 'string', 'max:100'],
            'search' => ['sometimes', 'string', 'max:255'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);

        $entities = $knowledgeBase->graphEntities()
            ->withCount(['mentions', 'incomingRelationships', 'outgoingRelationships'])
            ->when($data['type'] ?? null, fn ($query, $type) => $query->where('type', $type))
            ->when($data['search'] ?? null, fn ($query, $search) => $query->where('canonical_name', 'like', '%'.$search.'%'))
            ->orderBy('canonical_name')
            ->paginate($data['per_page'] ?? 25);

        return response()->json([
            'stats' => [
                'entities' => $knowledgeBase->graphEntities()->count(),
                'relationships' => $knowledgeBase->graphRelationships()->count(),
                'communities' => $knowledgeBase->graphCommunities()->count(),
            ],
            'entities' => $entities,
        ]);
    }

    public function show(KnowledgeBase $knowledgeBase, GraphEntity $entity): JsonResponse
    {
        abort_unless($entity->knowledge_base_id === $knowledgeBase->id, 404);

        return response()->json($entity->load([
            'mentions.chunk.document:id,knowledge_base_id,title,source',
            'outgoingRelationships.targetEntity',
            'outgoingRelationships.evidence.chunk.document:id,knowledge_base_id,title,source',
            'incomingRelationships.sourceEntity',
            'incomingRelationships.evidence.chunk.document:id,knowledge_base_id,title,source',
        ]));
    }
}
