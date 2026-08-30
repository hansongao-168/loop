<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GraphMention extends Model
{
    protected $fillable = ['entity_id', 'document_chunk_id', 'surface_form', 'confidence', 'metadata'];

    protected function casts(): array
    {
        return ['confidence' => 'float', 'metadata' => 'array'];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(GraphEntity::class, 'entity_id');
    }

    public function chunk(): BelongsTo
    {
        return $this->belongsTo(DocumentChunk::class, 'document_chunk_id');
    }
}
