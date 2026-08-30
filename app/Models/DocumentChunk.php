<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentChunk extends Model
{
    protected $fillable = ['document_id', 'position', 'content', 'embedding', 'metadata'];

    protected function casts(): array
    {
        return ['embedding' => 'array', 'metadata' => 'array'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function graphMentions(): HasMany
    {
        return $this->hasMany(GraphMention::class);
    }

    public function graphRelationshipEvidence(): HasMany
    {
        return $this->hasMany(GraphRelationshipEvidence::class);
    }
}
