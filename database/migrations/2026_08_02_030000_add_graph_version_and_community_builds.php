<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_bases', function (Blueprint $table) {
            $table->unsignedBigInteger('graph_version')->default(0)->after('description');
        });

        Schema::create('graph_community_builds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('knowledge_base_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('graph_version');
            $table->string('status')->default('pending');
            $table->uuid('build_version')->nullable();
            $table->unsignedInteger('communities_count')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();
            $table->index(['knowledge_base_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('graph_community_builds');
        Schema::table('knowledge_bases', function (Blueprint $table) {
            $table->dropColumn('graph_version');
        });
    }
};
