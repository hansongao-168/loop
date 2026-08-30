<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class GraphCommunity extends Model
{
    protected $fillable = [
        'knowledge_base_id', 'level', 'title', 'summary', 'rank', 'embedding', 'build_version', 'metadata',
    ];

    protected function casts(): array
    {
        return ['rank' => 'float', 'embedding' => 'array', 'metadata' => 'array'];
    }

    public function knowledgeBase(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBase::class);
    }

    public function entities(): BelongsToMany
    {
        return $this->belongsToMany(GraphEntity::class, 'graph_community_members', 'community_id', 'entity_id')
            ->withPivot('membership_score')
            ->withTimestamps();
    }
}
