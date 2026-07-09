<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('answer_versions', function (Blueprint $table) {
            $table->string('feedback', 20)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('answer_versions', function (Blueprint $table) {
            $table->dropColumn('feedback');
        });
    }
};
