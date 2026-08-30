<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GraphRelationshipEvidence extends Model
{
    protected $table = 'graph_relationship_evidence';

    protected $fillable = ['relationship_id', 'document_chunk_id', 'statement', 'confidence', 'metadata'];

    protected function casts(): array
    {
        return ['confidence' => 'float', 'metadata' => 'array'];
    }

    public function relationship(): BelongsTo
    {
        return $this->belongsTo(GraphRelationship::class, 'relationship_id');
    }

    public function chunk(): BelongsTo
    {
        return $this->belongsTo(DocumentChunk::class, 'document_chunk_id');
    }
}
