<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('answer_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('question_id')->index();
            $table->unsignedInteger('version_number');
            $table->longText('answer_text');
            $table->decimal('confidence', 5, 2);
            $table->json('sources')->nullable();
            $table->string('response_hash', 64);
            $table->boolean('is_current')->default(false);
            $table->string('status', 20)->default('current');
            $table->timestamp('created_at')->nullable();

            $table->foreign('question_id')->references('id')->on('questions')->cascadeOnDelete();
            $table->unique(['question_id', 'version_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('answer_versions');
    }
};
