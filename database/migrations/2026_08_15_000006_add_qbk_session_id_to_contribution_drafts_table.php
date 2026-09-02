<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contribution_drafts', function (Blueprint $table) {
            $table->unsignedBigInteger('qbk_session_id')->nullable()->after('repository_id');
            $table->index('qbk_session_id');
            $table->index(['user_id', 'qbk_session_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('contribution_drafts', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'qbk_session_id', 'status']);
            $table->dropIndex(['qbk_session_id']);
            $table->dropColumn('qbk_session_id');
        });
    }
};
