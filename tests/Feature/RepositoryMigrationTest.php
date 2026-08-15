<?php

namespace Tests\Feature;

use App\Models\Question;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class RepositoryMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_backfill_assigns_orphan_questions_to_users_default_repository(): void
    {
        $user = User::factory()->create();
        $repo = Repository::factory()->create([
            'user_id' => $user->uuid,
            'is_default' => true,
            'resolved_tenant_slug' => 'ispend',
        ]);

        // Simula preguntas pre-existentes: vuelve atrás la migración G1 (000004, drop de
        // columnas de users), D2 (000003, NOT NULL) y A2 (000002, repository_id + backfill)
        // — 3 pasos. Si se agrega otra migración de questions después de 000003, actualizar
        // este conteo (el teardown de DatabaseMigrations hace rollback completo, así que la
        // suite queda limpia entre clases).
        Artisan::call('migrate:rollback', ['--step' => 3]);

        $question = Question::create([
            'user_id' => $user->uuid,
            'question_text' => 'Pregunta huérfana previa a la migración',
        ]);

        // Re-ejecuta A2 (columna nullable → backfill → FK restrict) y D2 (NOT NULL).
        Artisan::call('migrate');

        $this->assertSame($repo->id, $question->fresh()->repository_id);
    }
}
