<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Content-addressed cache row for a community summary. The signature
 * fingerprints everything the summary is derived from (level, member
 * ids, relationship ids with weights, evidence count), so a hit is by
 * construction still valid. Rows survive community invalidation and are
 * removed with their knowledge base.
 */
class GraphCommunityCache extends Model
{
    protected $table = 'graph_community_cache';

    protected $fillable = [
        'knowledge_base_id', 'signature', 'title', 'summary', 'embedding',
    ];

    protected function casts(): array
    {
        return ['embedding' => 'array'];
    }

    public function knowledgeBase(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBase::class);
    }
}
