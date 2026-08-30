<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GraphCommunityBuild extends Model
{
    protected $fillable = [
        'knowledge_base_id', 'graph_version', 'status', 'build_version', 'communities_count',
        'started_at', 'completed_at', 'failure_reason',
    ];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function knowledgeBase(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBase::class);
    }
}
