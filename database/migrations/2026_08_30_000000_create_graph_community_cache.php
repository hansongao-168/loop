<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Content-addressed cache for community summaries. Keyed by a
        // fingerprint of the community's membership and relationships:
        // when a rebuild computes the same fingerprint, the stored
        // summary/embedding is reused without any model call. Survives
        // community invalidation (only graph_communities rows are
        // dropped); scoped to the knowledge base and cascaded on its
        // deletion.
        Schema::create('graph_community_cache', function (Blueprint $table) {
            $table->id();
            $table->foreignId('knowledge_base_id')->constrained()->cascadeOnDelete();
            $table->string('signature', 64);
            $table->string('title');
            $table->longText('summary');
            $table->json('embedding')->nullable();
            $table->timestamps();
            $table->unique(['knowledge_base_id', 'signature']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('graph_community_cache');
    }
};
