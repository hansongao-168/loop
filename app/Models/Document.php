<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Document extends Model
{
    protected $hidden = ['source_content'];

    protected $fillable = [
        'knowledge_base_id', 'title', 'source', 'metadata', 'source_content', 'status',
        'index_version', 'indexed_at', 'failure_reason',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'indexed_at' => 'datetime'];
    }

    public function knowledgeBase(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBase::class);
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(DocumentChunk::class);
    }
}
