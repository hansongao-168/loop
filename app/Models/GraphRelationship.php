<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GraphRelationship extends Model
{
    protected $fillable = ['knowledge_base_id', 'source_entity_id', 'target_entity_id', 'type', 'description', 'weight', 'confidence', 'metadata'];

    protected function casts(): array
    {
        return ['confidence' => 'float', 'metadata' => 'array'];
    }

    public function knowledgeBase(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBase::class);
    }

    public function sourceEntity(): BelongsTo
    {
        return $this->belongsTo(GraphEntity::class, 'source_entity_id');
    }

    public function targetEntity(): BelongsTo
    {
        return $this->belongsTo(GraphEntity::class, 'target_entity_id');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(GraphRelationshipEvidence::class, 'relationship_id');
    }
}
