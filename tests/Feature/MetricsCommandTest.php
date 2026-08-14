<?php

namespace Tests\Feature;

use App\Models\AnswerVersion;
use App\Models\DailyMetric;
use App\Models\Question;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MetricsCommandTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-10 12:00:00');

        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_collect_aggregates_metrics_for_target_date(): void
    {
        // Activas: solo 2 (estado activo y sin soft-delete).
        Question::factory()->count(2)->create([
            'user_id' => $this->user->uuid,
            'status' => 'active',
            'has_unreviewed_changes' => false,
        ]);

        // No cuentan como activas: 1 archivada, 1 soft-deleted, y las de creación controlada.
        Question::factory()->create([
            'user_id' => $this->user->uuid,
            'status' => 'archived',
            'has_unreviewed_changes' => false,
        ]);
        $trashed = Question::factory()->create([
            'user_id' => $this->user->uuid,
            'has_unreviewed_changes' => false,
        ]);
        $trashed->delete();

        // Creadas: 2 dentro del día objetivo, 1 fuera. Todas archivadas para no sumar a activas.
        $q1 = $this->createQuestionOn('2026-08-09 09:00:00');
        $q2 = $this->createQuestionOn('2026-08-09 10:00:00');
        $this->createQuestionOn('2026-08-08 09:00:00');

        // Detectados: v2 y v3 de q1 el día objetivo; v2 de q2 fuera del día.
        $this->createVersionOn($q1, 2, '2026-08-09 11:00:00');
        $this->createVersionOn($q1, 3, '2026-08-09 12:00:00');
        $this->createVersionOn($q2, 2, '2026-08-08 10:00:00');

        // Revisados: 2 answer_changed leídas el día; 1 sin leer; 1 de otro tipo.
        $this->createNotification('answer_changed', '2026-08-09 10:00:00', '2026-08-09 12:00:00');
        $this->createNotification('answer_changed', '2026-08-09 09:00:00', '2026-08-09 11:00:00');
        $this->createNotification('answer_changed', '2026-08-09 08:00:00', null);
        $this->createNotification('other', '2026-08-09 08:00:00', '2026-08-09 12:00:00');

        // Sin revisar: solo la explícita (el resto tiene has_unreviewed_changes = false).
        Question::factory()->create([
            'user_id' => $this->user->uuid,
            'status' => 'archived',
            'has_unreviewed_changes' => true,
        ]);

        $this->artisan('metrics:collect', ['--date' => '2026-08-09'])->assertSuccessful();

        $metric = DailyMetric::where('metric_date', '2026-08-09')->first();

        $this->assertNotNull($metric);
        $this->assertSame(2, $metric->preguntas_activas);
        $this->assertSame(2, $metric->preguntas_creadas);
        $this->assertSame(2, $metric->cambios_detectados);
        $this->assertSame(2, $metric->cambios_revisados);
        $this->assertSame(1, $metric->cambios_sin_revisar);
        // (12:00-10:00)=2h y (11:00-09:00)=2h → promedio 2.0 h.
        $this->assertEqualsWithDelta(2.0, (float) $metric->tiempo_revision_promedio_horas, 0.01);
    }

    public function test_collect_defaults_to_yesterday(): void
    {
        $this->artisan('metrics:collect')->assertSuccessful();

        $this->assertDatabaseHas('daily_metrics', ['metric_date' => '2026-08-09']);
    }

    public function test_collect_is_idempotent(): void
    {
        $this->artisan('metrics:collect', ['--date' => '2026-08-09'])->assertSuccessful();
        $this->artisan('metrics:collect', ['--date' => '2026-08-09'])->assertSuccessful();

        $this->assertSame(1, DailyMetric::where('metric_date', '2026-08-09')->count());
    }

    public function test_collect_creates_zeroed_row_without_data(): void
    {
        $this->artisan('metrics:collect', ['--date' => '2026-08-09'])->assertSuccessful();

        $metric = DailyMetric::where('metric_date', '2026-08-09')->first();

        $this->assertSame(0, $metric->preguntas_activas);
        $this->assertNull($metric->tiempo_revision_promedio_horas);
    }

    public function test_collect_rejects_invalid_date(): void
    {
        $this->artisan('metrics:collect', ['--date' => 'no-es-fecha'])->assertFailed();

        $this->assertDatabaseCount('daily_metrics', 0);
    }

    public function test_show_prints_metrics_table(): void
    {
        $this->artisan('metrics:collect', ['--date' => '2026-08-09'])->assertSuccessful();

        $this->artisan('metrics:show', ['--date' => '2026-08-09'])
            ->expectsOutputToContain('2026-08-09')
            ->assertSuccessful();

        $this->artisan('metrics:show', ['--range' => 7])->assertSuccessful();
    }

    public function test_show_handles_empty_state(): void
    {
        $this->artisan('metrics:show')
            ->expectsOutputToContain('Todavía no hay métricas')
            ->assertSuccessful();

        $this->artisan('metrics:show', ['--date' => '2026-08-09'])->assertFailed();
    }

    private function createQuestionOn(string $createdAt): Question
    {
        $question = Question::factory()->create([
            'user_id' => $this->user->uuid,
            'status' => 'archived',
            'has_unreviewed_changes' => false,
        ]);
        $question->created_at = $createdAt;
        $question->save();

        return $question;
    }

    private function createVersionOn(Question $question, int $number, string $createdAt): AnswerVersion
    {
        $version = $question->versions()->create([
            'version_number' => $number,
            'answer_text' => "respuesta v{$number}",
            'confidence' => 90,
            'sources' => [],
            'response_hash' => hash('sha256', "v{$number}"),
            'is_current' => false,
        ]);

        $version->created_at = $createdAt;
        $version->save();

        return $version;
    }

    private function createNotification(string $type, string $createdAt, ?string $readAt): void
    {
        // Esquema estándar de Laravel (Bloque 1): notifiable_type + notifiable_id (PK de users).
        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(),
            'notifiable_type' => User::class,
            'notifiable_id' => $this->user->id,
            'type' => $type,
            'data' => json_encode(['question_id' => (string) Str::uuid()]),
            'created_at' => $createdAt,
            'read_at' => $readAt,
        ]);
    }
}
