<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GraphEntity extends Model
{
    protected $fillable = ['knowledge_base_id', 'canonical_name', 'normalized_name', 'type', 'description', 'aliases', 'embedding', 'metadata'];

    protected function casts(): array
    {
        return ['aliases' => 'array', 'embedding' => 'array', 'metadata' => 'array'];
    }

    public function knowledgeBase(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBase::class);
    }

    public function mentions(): HasMany
    {
        return $this->hasMany(GraphMention::class, 'entity_id');
    }

    public function outgoingRelationships(): HasMany
    {
        return $this->hasMany(GraphRelationship::class, 'source_entity_id');
    }

    public function incomingRelationships(): HasMany
    {
        return $this->hasMany(GraphRelationship::class, 'target_entity_id');
    }
}
