<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('graph_communities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('knowledge_base_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('level')->default(0);
            $table->string('title');
            $table->longText('summary');
            $table->decimal('rank', 10, 4)->default(0);
            $table->json('embedding')->nullable();
            $table->uuid('build_version');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['knowledge_base_id', 'rank']);
            $table->index(['knowledge_base_id', 'build_version']);
        });

        Schema::create('graph_community_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('community_id')->constrained('graph_communities')->cascadeOnDelete();
            $table->foreignId('entity_id')->constrained('graph_entities')->cascadeOnDelete();
            $table->decimal('membership_score', 5, 4)->default(1);
            $table->timestamps();
            $table->unique(['community_id', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('graph_community_members');
        Schema::dropIfExists('graph_communities');
    }
};
