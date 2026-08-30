<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->longText('source_content')->nullable()->after('metadata');
            $table->unsignedInteger('index_version')->default(1)->after('status');
            $table->timestamp('indexed_at')->nullable()->after('index_version');
            $table->text('failure_reason')->nullable()->after('indexed_at');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn(['source_content', 'index_version', 'indexed_at', 'failure_reason']);
        });
    }
};
