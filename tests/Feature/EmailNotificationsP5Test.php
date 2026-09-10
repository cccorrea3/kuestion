<?php

namespace Tests\Feature;

use App\Jobs\CheckContributionStatusJob;
use App\Jobs\NotifyPendingReviewJob;
use App\Jobs\NotifyReconfirmationDueJob;
use App\Livewire\Settings;
use App\Mail\ContributionDecisionMail;
use App\Mail\PendingReviewMail;
use App\Mail\ReconfirmationDueMail;
use App\Models\ContributionDraft;
use App\Models\EmailLog;
use App\Models\Question;
use App\Models\Repository;
use App\Models\User;
use App\Notifications\AnswerChangedNotification;
use App\Services\EmailDispatcher;
use App\Services\QbkContributionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ola 2, Punto 5 — checklists FA/FB/FC/FD/FE (sección 3 del plan).
 */
class EmailNotificationsP5Test extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    // ------------------------------------------------------------------
    // FA.3 — regla de preferencia (all / critical_only / none)
    // ------------------------------------------------------------------

    public function test_fa3_preference_rule_by_level(): void
    {
        $all = User::factory()->make(['email_notifications' => User::EMAIL_PREF_ALL]);
        $critical = User::factory()->make(['email_notifications' => User::EMAIL_PREF_CRITICAL_ONLY]);
        $none = User::factory()->make(['email_notifications' => User::EMAIL_PREF_NONE]);

        // all recibe todo (crítico y no crítico).
        $this->assertTrue($all->emailPreferenceAllows('new_version'));
        $this->assertTrue($all->emailPreferenceAllows('pending_review'));

        // critical_only: solo eventos críticos; new_version no es crítico (§2.2).
        $this->assertTrue($critical->emailPreferenceAllows('pending_review'));
        $this->assertTrue($critical->emailPreferenceAllows('contribution_approved'));
        $this->assertTrue($critical->emailPreferenceAllows('reconfirmation_due'));
        $this->assertFalse($critical->emailPreferenceAllows('new_version'));

        // none: nada.
        $this->assertFalse($none->emailPreferenceAllows('new_version'));
        $this->assertFalse($none->emailPreferenceAllows('pending_review'));
    }

    // ------------------------------------------------------------------
    // FA.4/FA.5 — dedupe por ventana + log de envíos
    // ------------------------------------------------------------------

    public function test_fa4_dedupe_within_window_and_outside(): void
    {
        $dispatcher = app(EmailDispatcher::class);

        $this->assertTrue($dispatcher->shouldSend($this->user, 'new_version', 'q-1'));
        $dispatcher->logSent($this->user, 'new_version', 'q-1');

        // Mismo evento + misma entidad en ventana → bloqueado.
        $this->assertFalse($dispatcher->shouldSend($this->user, 'new_version', 'q-1'));

        // Evento distinto → permitido.
        $this->assertTrue($dispatcher->shouldSend($this->user, 'pending_review', 'q-1'));

        // Fuera de ventana (log anterior a la ventana) → permitido.
        EmailLog::query()->update(['sent_at' => now()->subHours(2)]);
        $this->assertTrue($dispatcher->shouldSend($this->user, 'new_version', 'q-1'));
    }

    public function test_fa5_log_records_each_send(): void
    {
        $dispatcher = app(EmailDispatcher::class);
        $dispatcher->logSent($this->user, 'contribution_approved', '42');

        $this->assertDatabaseHas('email_logs', [
            'user_id' => $this->user->id,
            'event_type' => 'contribution_approved',
            'reference_key' => '42',
        ]);
    }

    public function test_fa4_concurrent_only_one_send_wins(): void
    {
        $dispatcher = app(EmailDispatcher::class);

        $dispatcher->logSent($this->user, 'new_version', 'q-1');
        $dispatcher->logSent($this->user, 'new_version', 'q-1');

        $this->assertSame(1, EmailLog::query()
            ->where('user_id', $this->user->id)
            ->where('event_type', 'new_version')
            ->where('reference_key', 'q-1')
            ->count());
    }

    // ------------------------------------------------------------------
    // FA.2 — UI de preferencias (3 niveles)
    // ------------------------------------------------------------------

    public function test_fa2_settings_persists_each_level(): void
    {
        foreach ([User::EMAIL_PREF_CRITICAL_ONLY, User::EMAIL_PREF_NONE, User::EMAIL_PREF_ALL] as $level) {
            Livewire::actingAs($this->user)
                ->test(Settings::class)
                ->set('emailNotifications', $level)
                ->call('updateEmailPreference');

            $this->assertSame($level, $this->user->fresh()->email_notifications);
        }
    }

    public function test_fa2_settings_shows_three_options(): void
    {
        $html = Livewire::actingAs($this->user)
            ->test(Settings::class)
            ->html();

        $this->assertStringContainsString('Todos los correos', $html);
        $this->assertStringContainsString('Solo lo importante', $html);
        $this->assertStringContainsString('Sin correos', $html);
    }

    // ------------------------------------------------------------------
    // FA.6 — baja por link firmado sin login
    // ------------------------------------------------------------------

    public function test_fa6_signed_unsubscribe_sets_none_without_login(): void
    {
        $this->user->update(['email_notifications' => User::EMAIL_PREF_ALL]);

        $url = URL::temporarySignedRoute('unsubscribe', now()->addDays(30), ['user' => $this->user->id]);

        $this->post($url); // no actingAs: sin sesión
        $this->get($url)
            ->assertOk()
            ->assertSee('Correos desactivados');

        $this->assertSame(User::EMAIL_PREF_NONE, $this->user->fresh()->email_notifications);
    }

    public function test_fa6_unsigned_unsubscribe_is_forbidden(): void
    {
        $this->get('/unsubscribe/'.$this->user->id)->assertForbidden();
    }

    // ------------------------------------------------------------------
    // FB.1–FB.4 — correo de cambio (new_version gate, preview, dedupe)
    // ------------------------------------------------------------------

    public function test_fb1_new_version_mail_has_preview_cta_and_footer(): void
    {
        // toMail() toma el userId del notifiable (para el link firmado de baja).
        $mail = (new AnswerChangedNotification(
            questionId: 'q-1',
            questionText: '¿Cuál es el procedimiento X?',
            versionNumber: 2,
            changeType: 'new_version',
            similarity: 0.55,
            preview: 'El procedimiento X consiste en tres pasos...',
        ))->toMail($this->user);

        $html = $mail->render();

        $this->assertStringContainsString('nueva versión de una respuesta', $mail->envelope()->subject);
        $this->assertStringContainsString('El procedimiento X consiste en tres pasos', $html);
        $this->assertStringContainsString('Ver cambios', $html);
        $this->assertStringContainsString('Configurar mis notificaciones', $html);
        $this->assertStringContainsString('Dejar de recibir estos correos', $html);
        $this->assertStringContainsString(route('questions.show', 'q-1'), $html);
    }

    public function test_fb1_mail_sent_only_for_new_version_with_preference_all(): void
    {
        Mail::fake();

        $notif = new AnswerChangedNotification('q-1', 'Pregunta', 2, 'new_version', 0.5);

        $this->assertContains('mail', $notif->via($this->user));

        // El envío quedó registrado en el log (dedupe del flujo real).
        $this->assertDatabaseHas('email_logs', [
            'user_id' => $this->user->id,
            'event_type' => 'new_version',
            'reference_key' => 'q-1',
        ]);
    }

    public function test_fb2_minor_never_mails(): void
    {
        Mail::fake();

        $notif = new AnswerChangedNotification('q-1', 'Pregunta', 2, 'minor', 0.95);

        $this->assertSame(['database'], $notif->via($this->user));
        $this->assertDatabaseMissing('email_logs', ['reference_key' => 'q-1']);
    }

    public function test_fb3_critical_only_and_none_do_not_receive_new_version(): void
    {
        $critical = User::factory()->make(['email_notifications' => User::EMAIL_PREF_CRITICAL_ONLY]);
        $none = User::factory()->make(['email_notifications' => User::EMAIL_PREF_NONE]);

        $notif = new AnswerChangedNotification('q-1', 'Pregunta', 2, 'new_version', 0.5);

        $this->assertSame(['database'], $notif->via($critical));
        $this->assertSame(['database'], $notif->via($none));
    }

    public function test_fb4_second_change_within_window_does_not_mail_twice(): void
    {
        $notif = new AnswerChangedNotification('q-1', 'Pregunta', 2, 'new_version', 0.5);

        $this->assertContains('mail', $notif->via($this->user));
        // Segunda detección sobre la misma pregunta dentro de la ventana: sin mail.
        $this->assertSame(['database'], $notif->via($this->user));
    }

    // ------------------------------------------------------------------
    // FC.1–FC.4 — aprobado/rechazado al autor (polling)
    // ------------------------------------------------------------------

    private function draftWithSession(array $draftAttrs = [], ?array $credential = null): ContributionDraft
    {
        $repo = Repository::factory()->create([
            'user_id' => $this->user->uuid,
            'connector_type' => 'qbk',
            'credential' => $credential ?? ['api_token' => '2|qbk_test_token'],
        ]);

        return ContributionDraft::create(array_merge([
            'user_id' => $this->user->uuid,
            'repository_id' => $repo->id,
            'qbk_session_id' => 42,
            'texto' => 'Texto del aporte de prueba',
            'status' => ContributionDraft::STATUS_SENT,
        ], $draftAttrs));
    }

    private function fakeSessionDetail(array $overrides = []): array
    {
        return [
            '*/api/v1/sesiones-analisis/42' => Http::response([
                'success' => true,
                'data' => array_merge([
                    'session_id' => 42,
                    'status' => 'promocionada',
                    'revisado_por_nombre' => 'Laura Revisora',
                    'nodos' => [],
                ], $overrides),
            ]),
        ];
    }

    public function test_fc1_promoted_session_sends_approved_mail_once(): void
    {
        Mail::fake();
        Http::fake($this->fakeSessionDetail());

        $draft = $this->draftWithSession();

        (new CheckContributionStatusJob)->handle(app(QbkContributionService::class), app(EmailDispatcher::class));

        // Los mailables son ShouldQueue: MailFake los captura como queued
        // (verificado contra MailFake::sendMail en el vendor instalado).
        Mail::assertQueued(ContributionDecisionMail::class, 1);
        Mail::assertQueued(ContributionDecisionMail::class, function ($mail) {
            return $mail->aprobado === true
                && $mail->revisadoPorNombre === 'Laura Revisora'
                && $mail->to[0]['address'] === $this->user->email;
        });

        // El draft sale del polling (decisión notificada).
        $this->assertSame(ContributionDraft::STATUS_REVIEWED, $draft->fresh()->status);
        $this->assertDatabaseHas('email_logs', [
            'event_type' => 'contribution_approved',
            'reference_key' => '42',
        ]);

        // Segunda corrida: sin duplicado.
        (new CheckContributionStatusJob)->handle(app(QbkContributionService::class), app(EmailDispatcher::class));
        Mail::assertQueued(ContributionDecisionMail::class, 1);
    }

    public function test_fc2_rejected_session_sends_rejected_mail(): void
    {
        Mail::fake();
        Http::fake($this->fakeSessionDetail(['status' => 'rechazada']));

        $this->draftWithSession();

        (new CheckContributionStatusJob)->handle(app(QbkContributionService::class), app(EmailDispatcher::class));

        Mail::assertQueued(ContributionDecisionMail::class, function ($mail) {
            return $mail->aprobado === false;
        });
    }

    public function test_fc3_unchanged_status_no_mail_between_runs(): void
    {
        Mail::fake();
        Http::fake($this->fakeSessionDetail(['status' => 'lista_para_revision']));

        $draft = $this->draftWithSession();

        (new CheckContributionStatusJob)->handle(app(QbkContributionService::class), app(EmailDispatcher::class));
        (new CheckContributionStatusJob)->handle(app(QbkContributionService::class), app(EmailDispatcher::class));

        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        $this->assertSame(ContributionDraft::STATUS_SENT, $draft->fresh()->status);
    }

    public function test_fc4_qbk_down_does_not_false_mark(): void
    {
        Mail::fake();
        Http::fake(['*' => Http::response('', 500)]);

        $draft = $this->draftWithSession();

        (new CheckContributionStatusJob)->handle(app(QbkContributionService::class), app(EmailDispatcher::class));

        Mail::assertNothingSent();
        $this->assertSame(ContributionDraft::STATUS_SENT, $draft->fresh()->status);
    }

    public function test_fc_pre_b2_draft_without_author_notified_is_reviewed(): void
    {
        Mail::fake();
        Http::fake($this->fakeSessionDetail());

        // Draft ya revisado localmente por su propio autor: no se notifica.
        $this->draftWithSession(['status' => ContributionDraft::STATUS_REVIEWED]);

        (new CheckContributionStatusJob)->handle(app(QbkContributionService::class), app(EmailDispatcher::class));

        Mail::assertNothingSent();
    }

    // ------------------------------------------------------------------
    // FD.1/FD.2 — aporte pendiente → revisores (listado + /miembros)
    // ------------------------------------------------------------------

    public function test_fd_pending_session_notifies_workspace_reviewers_once(): void
    {
        Mail::fake();

        $repo = Repository::factory()->create([
            'user_id' => $this->user->uuid,
            'connector_type' => 'qbk',
            'credential' => ['api_token' => '2|qbk_test_token'],
            'resolved_workspace_id' => 'ws-1',
        ]);

        $reviewer = User::factory()->create(['email' => 'revisor@equipo.cl']);

        Http::fake([
            '*/api/v1/sesiones-analisis?*' => Http::response([
                'success' => true,
                'data' => [
                    ['session_id' => '77', 'status' => 'lista_para_revision', 'texto_original_del_aporte' => 'Aporte de Juan', 'autor_nombre' => 'Juan'],
                ],
            ], 200),
            '*/api/v1/workspaces/ws-1/miembros' => Http::response([
                'success' => true,
                'data' => [
                    'workspace_id' => 'ws-1',
                    'miembros' => [
                        ['user_id' => 1, 'nombre' => 'Laura', 'email' => 'revisor@equipo.cl', 'rol' => 'editor'],
                        ['user_id' => 2, 'nombre' => 'Externo', 'email' => 'sin-cuenta@equipo.cl', 'rol' => 'revisor'],
                    ],
                ],
            ]),
        ]);

        (new NotifyPendingReviewJob)->handle(app(QbkContributionService::class), app(EmailDispatcher::class));

        // Revisor con cuenta en Kuestion: recibe el correo con la atribución del autor.
        Mail::assertQueued(PendingReviewMail::class, 1);
        Mail::assertQueued(PendingReviewMail::class, function ($mail) use ($reviewer) {
            return $mail->to[0]['address'] === $reviewer->email
                && $mail->autorNombre === 'Juan'
                && $mail->sessionId === 77;
        });

        // Miembro de QuBeKa sin cuenta en Kuestion: sin correo (sin control de baja).
        // El único log de la sesión 77 es el del revisor con cuenta.
        $this->assertDatabaseHas('email_logs', [
            'reference_key' => '77',
            'event_type' => 'pending_review',
            'user_id' => $reviewer->id,
        ]);
        $this->assertSame(1, EmailLog::query()->where('reference_key', '77')->count());

        // Segunda corrida: dedupe por (revisor, sesión) — sin duplicado.
        (new NotifyPendingReviewJob)->handle(app(QbkContributionService::class), app(EmailDispatcher::class));
        Mail::assertQueued(PendingReviewMail::class, 1);
    }

    // ------------------------------------------------------------------
    // FE.1 — reconfirmación pendiente (datos del Punto 2, ya implementado)
    // ------------------------------------------------------------------

    private function qbkQuestionWithSources(array $sources): Question
    {
        $repo = Repository::factory()->create([
            'user_id' => $this->user->uuid,
            'connector_type' => 'qbk',
            'credential' => ['api_token' => '2|qbk_test_token'],
        ]);

        $question = Question::factory()->create([
            'user_id' => $this->user->uuid,
            'repository_id' => $repo->id,
        ]);

        $question->versions()->create([
            'version_number' => 1,
            'answer_text' => 'Respuesta test',
            'confidence' => 80,
            'sources' => $sources,
            'response_hash' => hash('sha256', 'Respuesta test'),
            'is_current' => true,
            'found' => true,
        ]);

        return $question->refresh();
    }

    public function test_fe1_question_over_threshold_gets_reconfirmation_mail_once(): void
    {
        Mail::fake();

        $vencida = $this->qbkQuestionWithSources([
            ['node_id' => 'NK-001', 'fecha_ultima_confirmacion' => now()->subDays(95)->toIso8601String()],
        ]);
        // Confirmada dentro del umbral: no recibe correo.
        $this->qbkQuestionWithSources([
            ['node_id' => 'NK-002', 'fecha_ultima_confirmacion' => now()->subDays(10)->toIso8601String()],
        ]);

        (new NotifyReconfirmationDueJob)->handle(app(EmailDispatcher::class));

        Mail::assertQueued(ReconfirmationDueMail::class, 1);
        Mail::assertQueued(ReconfirmationDueMail::class, function ($mail) use ($vencida) {
            return $mail->to[0]['address'] === $this->user->email
                && $mail->questionId === $vencida->id
                && $mail->dias >= 95;
        });

        // Segunda corrida (mismo día): dedupe — un solo correo.
        (new NotifyReconfirmationDueJob)->handle(app(EmailDispatcher::class));
        Mail::assertQueued(ReconfirmationDueMail::class, 1);
    }

    // ------------------------------------------------------------------
    // FE.3 — plantilla de vigencia crítica lista (no conectada: B5)
    // ------------------------------------------------------------------

    public function test_fe3_validity_alert_template_renders(): void
    {
        $html = view('emails.validity-alert', [
            'questionText' => '¿Cuál es el procedimiento X?',
            'url' => 'http://localhost/questions/q-1',
            'settingsUrl' => 'http://localhost/settings',
            'unsubscribeUrl' => 'http://localhost/unsubscribe',
        ])->render();

        $this->assertStringContainsString('necesita tu revisión', $html);
        $this->assertStringContainsString('Revisar ahora', $html);
    }
}
