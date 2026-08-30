<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('graph_entities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('knowledge_base_id')->constrained()->cascadeOnDelete();
            $table->string('canonical_name');
            $table->string('normalized_name');
            $table->string('type', 100);
            $table->text('description')->nullable();
            $table->json('aliases')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['knowledge_base_id', 'type', 'normalized_name'], 'graph_entities_identity_unique');
            $table->index(['knowledge_base_id', 'canonical_name']);
        });

        Schema::create('graph_relationships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('knowledge_base_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_entity_id')->constrained('graph_entities')->cascadeOnDelete();
            $table->foreignId('target_entity_id')->constrained('graph_entities')->cascadeOnDelete();
            $table->string('type', 100);
            $table->text('description')->nullable();
            $table->unsignedInteger('weight')->default(1);
            $table->decimal('confidence', 5, 4)->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['knowledge_base_id', 'source_entity_id', 'target_entity_id', 'type'], 'graph_relationships_identity_unique');
        });

        Schema::create('graph_mentions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')->constrained('graph_entities')->cascadeOnDelete();
            $table->foreignId('document_chunk_id')->constrained()->cascadeOnDelete();
            $table->string('surface_form');
            $table->decimal('confidence', 5, 4)->default(1);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['entity_id', 'document_chunk_id', 'surface_form'], 'graph_mentions_identity_unique');
        });

        Schema::create('graph_relationship_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('relationship_id')->constrained('graph_relationships')->cascadeOnDelete();
            $table->foreignId('document_chunk_id')->constrained()->cascadeOnDelete();
            $table->text('statement');
            $table->decimal('confidence', 5, 4)->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['relationship_id', 'document_chunk_id'], 'graph_relationship_evidence_identity_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('graph_relationship_evidence');
        Schema::dropIfExists('graph_mentions');
        Schema::dropIfExists('graph_relationships');
        Schema::dropIfExists('graph_entities');
    }
};
