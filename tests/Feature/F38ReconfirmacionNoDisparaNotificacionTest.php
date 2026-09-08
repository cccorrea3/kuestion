<?php

namespace Tests\Feature;

use App\Jobs\CheckQuestionUpdatesJob;
use App\Models\Question;
use App\Models\User;
use App\Notifications\AnswerChangedNotification;
use App\Notifications\QueryErrorNotification;
use App\Services\ChangeDetector;
use App\Services\ConnectorRegistry;
use App\Services\QbkContributionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * F3.8 — Ola 2, Punto 2 (pedido explícito de QuBeKa tras H1/H2 de la Ola 1).
 *
 * Garantía: reconfirmar un nodo fuente NO dispara el ciclo de vigilancia de
 * Kuestion — no se crea versión nueva ni notificación new_version por la
 * reconfirmación. F2.8 (lado QuBeKa) cubre que version/actualizado_en no
 * cambian allá; este test cubre el lado Kuestion.
 *
 * Estructuralmente: el ChangeDetector hashea solo answer_text y la
 * reconfirmación (QbkContributionService::reconfirmarNodo +
 * Question::aplicarReconfirmacionLocal) solo toca sources de la versión
 * existente. La evidencia queda ejecutada, no asumida.
 */
class F38ReconfirmacionNoDisparaNotificacionTest extends TestCase
{
    use RefreshDatabase;

    private const RESPUESTA = 'La política de vacaciones otorga 15 días hábiles por año.';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    public function test_reconfirmar_un_nodo_y_correr_deteccion_no_genera_version_ni_new_version(): void
    {
        $question = $this->qbkQuestionWithVersion();

        // Paso 2 — reconfirmación real del lado Kuestion: la misma secuencia que
        // ejecuta QuestionDetail::reconfirmar() (servicio HTTP + actualización
        // local), con QuBeKa simulado a nivel HTTP.
        $fecha = now()->toIso8601String();
        Http::fake([
            '*/nodos/Q-1/reconfirmar*' => Http::response([
                'success' => true,
                'data' => [
                    'node_id' => 'Q-1',
                    'fecha_ultima_confirmacion' => $fecha,
                    'ultimo_confirmador_id' => 1,
                ],
            ]),
        ]);

        app(QbkContributionService::class)->reconfirmarNodo('Q-1', $question->repository->credential);
        $question->refresh()->aplicarReconfirmacionLocal();

        // Pre-estado: la reconfirmación tocó sources, nada más.
        $this->assertSame(1, $question->versions()->count());
        $this->assertSame($fecha, $question->currentVersion->sources[0]['fecha_ultima_confirmacion']);
        $this->assertCount(0, DB::table('notifications')->get());

        // Paso 3 — ciclo completo de detección de cambios (job horario), con
        // /query devolviendo la MISMA respuesta y las fuentes ya confirmadas
        // (el payload de sources SÍ cambió respecto de v1: es exactamente el
        // escenario H1/H2 que no debe disparar una notificación).
        Http::fake([
            '*/query*' => Http::response([
                'success' => true,
                'data' => [
                    'answer' => self::RESPUESTA,
                    'confidence' => 88.0,
                    'found' => true,
                    'sources' => [
                        [
                            'node_id' => 'Q-1',
                            'title' => 'Política de vacaciones',
                            'fecha_ultima_confirmacion' => $fecha,
                        ],
                    ],
                ],
            ]),
        ]);

        (new CheckQuestionUpdatesJob)->handle(app(ConnectorRegistry::class));

        // Paso 4 — sin versión nueva, sin notificación new_version, sin estado
        // de revisión pendiente.
        $this->assertSame(1, $question->versions()->count());
        $this->assertSame(1, $question->fresh()->currentVersion->version_number);
        $this->assertFalse($question->fresh()->has_unreviewed_changes);
        $this->assertCount(0, DB::table('notifications')
            ->where('type', AnswerChangedNotification::class)->get());
        $this->assertCount(0, DB::table('notifications')
            ->where('type', QueryErrorNotification::class)->get());

        // El eslabón estructural: respuesta consultada idéntica → 'unchanged'.
        $this->assertSame(
            'unchanged',
            (new ChangeDetector)->detect($question->fresh()->currentVersion->answer_text, self::RESPUESTA)['type']
        );
    }

    public function test_hash_de_vigilancia_no_cambia_al_reconfirmar(): void
    {
        $question = $this->qbkQuestionWithVersion();

        $hashAntes = $question->fresh()->currentVersion->response_hash;

        Http::fake([
            '*/nodos/Q-1/reconfirmar*' => Http::response([
                'success' => true,
                'data' => [
                    'node_id' => 'Q-1',
                    'fecha_ultima_confirmacion' => now()->toIso8601String(),
                    'ultimo_confirmador_id' => 1,
                ],
            ]),
        ]);

        app(QbkContributionService::class)->reconfirmarNodo('Q-1', $question->repository->credential);
        $question->refresh()->aplicarReconfirmacionLocal();

        $version = $question->fresh()->currentVersion;

        $this->assertSame($hashAntes, $version->response_hash);
        $this->assertSame((new ChangeDetector)->hash(self::RESPUESTA), $version->response_hash);
        // La vigencia sí quedó actualizada en sources (la reconfirmación no fue un no-op).
        $this->assertNotNull($version->sources[0]['fecha_ultima_confirmacion']);
    }

    /**
     * Control negativo: mismo rig, pero con respuesta distinta en /query →
     * SÍ se versiona y SÍ se notifica. Demuestra que el rig puede fallar y
     * que el test principal no pasa en vacío.
     */
    public function test_control_negativo_respuesta_distinta_si_genera_new_version(): void
    {
        $question = $this->qbkQuestionWithVersion();

        Http::fake([
            '*/query*' => Http::response([
                'success' => true,
                'data' => [
                    'answer' => 'El reglamento interno exige casco en toda la planta de producción.',
                    'confidence' => 91.0,
                    'found' => true,
                    'sources' => [
                        ['node_id' => 'Q-1', 'title' => 'Política de vacaciones', 'fecha_ultima_confirmacion' => now()->toIso8601String()],
                    ],
                ],
            ]),
        ]);

        (new CheckQuestionUpdatesJob)->handle(app(ConnectorRegistry::class));

        $this->assertSame(2, $question->versions()->count());
        $this->assertSame(2, $question->fresh()->currentVersion->version_number);
        $this->assertSame('new_version', $question->fresh()->currentVersion->status);
        $this->assertTrue($question->fresh()->has_unreviewed_changes);
        $this->assertCount(1, DB::table('notifications')
            ->where('type', AnswerChangedNotification::class)->get());
    }

    private function qbkQuestionWithVersion(): Question
    {
        $question = Question::factory()->create([
            'user_id' => $this->user->uuid,
            'status' => 'active',
            'review_frequency' => 'weekly',
            'has_unreviewed_changes' => false,
        ]);

        $question->repository->update([
            'connector_type' => 'qbk',
            'credential' => ['api_token' => 'kqb_test_token'],
        ]);

        $question->versions()->create([
            'version_number' => 1,
            'answer_text' => self::RESPUESTA,
            'confidence' => 88.0,
            'sources' => [
                ['node_id' => 'Q-1', 'title' => 'Política de vacaciones'],
            ],
            'response_hash' => hash('sha256', self::RESPUESTA),
            'found' => true,
            'is_current' => true,
        ]);

        return $question;
    }
}
