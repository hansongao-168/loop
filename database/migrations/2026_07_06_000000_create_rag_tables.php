<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_bases', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
        });
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('knowledge_base_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('source')->nullable();
            $table->json('metadata')->nullable();
            $table->string('status')->default('ready');
            $table->timestamps();
        });
        Schema::create('document_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->longText('content');
            $table->json('embedding');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['document_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_chunks');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('knowledge_bases');
    }
};
