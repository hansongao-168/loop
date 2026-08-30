<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KnowledgeBase extends Model
{
    protected $fillable = ['name', 'description', 'graph_version'];

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function graphEntities(): HasMany
    {
        return $this->hasMany(GraphEntity::class);
    }

    public function graphRelationships(): HasMany
    {
        return $this->hasMany(GraphRelationship::class);
    }

    public function graphCommunities(): HasMany
    {
        return $this->hasMany(GraphCommunity::class);
    }

    public function graphCommunityBuilds(): HasMany
    {
        return $this->hasMany(GraphCommunityBuild::class);
    }
}
