<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('questions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->index();
            $table->string('question_text', 2000);
            $table->longText('answer_text')->nullable();
            $table->string('status', 20)->default('active');
            $table->boolean('is_starred')->default(false);
            $table->json('tags')->nullable();
            $table->string('review_frequency', 20)->default('weekly');
            $table->timestamp('last_consulted_at')->nullable();
            $table->timestamp('last_change_detected_at')->nullable();
            $table->boolean('has_unreviewed_changes')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'is_starred']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('questions');
    }
};
