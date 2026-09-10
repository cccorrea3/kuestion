<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Ola 2, Punto 5 — Fase A.2: registro de envíos + dedupe por ventana (spec §5).
// Unique (user_id, event_type, reference_key, sent_at): el dedupe por ventana
// redondea sent_at a la ventana (ver EmailDispatcher::windowBucket) y así el
// índice único hace cumplir "un envío por evento/entidad por ventana".
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('event_type', 64);
            $table->string('reference_key', 191);
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->unique(['user_id', 'event_type', 'reference_key', 'sent_at'], 'email_logs_dedupe_unique');
            $table->index('sent_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_logs');
    }
};
