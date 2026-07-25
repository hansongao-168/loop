<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Document extends Model
{
    protected $fillable = ['knowledge_base_id', 'title', 'source', 'metadata', 'status'];
    protected function casts(): array { return ['metadata' => 'array']; }
    public function knowledgeBase(): BelongsTo { return $this->belongsTo(KnowledgeBase::class); }
    public function chunks(): HasMany { return $this->hasMany(DocumentChunk::class); }
}
