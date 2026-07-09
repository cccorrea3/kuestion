<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('question_relations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('source_question_id');
            $table->uuid('target_question_id');
            $table->string('label', 100);
            $table->string('relation_type', 20)->default('tag_suggested');
            $table->timestamp('created_at')->nullable();

            $table->foreign('source_question_id')->references('id')->on('questions')->cascadeOnDelete();
            $table->foreign('target_question_id')->references('id')->on('questions')->cascadeOnDelete();
            $table->unique(['source_question_id', 'target_question_id', 'label'], 'rel_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_relations');
    }
};
