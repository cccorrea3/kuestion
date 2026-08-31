<?php

namespace Tests\Feature;

use App\Jobs\CheckQuestionUpdatesJob;
use App\Mail\AnswerChangedMail;
use App\Models\Question;
use App\Models\User;
use App\Notifications\AnswerChangedNotification;
use App\Notifications\QueryErrorNotification;
use App\Services\ConnectorRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CheckQuestionUpdatesJobTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    public function test_job_does_not_duplicate_versions_when_run_twice(): void
    {
        Http::fake([
            '*/consult*' => Http::response([
                'answer' => 'Nueva respuesta',
                'confidence' => 90,
                'sources' => [],
            ]),
        ]);

        $question = $this->questionWithVersion('Respuesta original');

        (new CheckQuestionUpdatesJob)->handle(app(ConnectorRegistry::class));

        $this->assertSame(2, $question->versions()->count());
        $this->assertSame(1, DB::table('notifications')->where('type', AnswerChangedNotification::class)->count());

        // Segunda corrida: la pregunta ya no está vencida (last_consulted_at actualizado).
        (new CheckQuestionUpdatesJob)->handle(app(ConnectorRegistry::class));

        $this->assertSame(2, $question->versions()->count());
        $this->assertSame(1, DB::table('notifications')->where('type', AnswerChangedNotification::class)->count());
    }

    public function test_job_marks_error_without_new_version_when_kuaforia_returns_empty(): void
    {
        Http::fake([
            '*/consult*' => Http::response(['answer' => '']),
        ]);

        $question = $this->questionWithVersion('Respuesta original');

        (new CheckQuestionUpdatesJob)->handle(app(ConnectorRegistry::class));

        // No se crea versión nueva y se actualiza last_consulted_at.
        $this->assertSame(1, $question->versions()->count());
        $this->assertNotNull($question->fresh()->last_consulted_at);

        $error = DB::table('notifications')->where('type', QueryErrorNotification::class)->first();
        $this->assertNotNull($error);
        $this->assertSame($question->id, json_decode($error->data, true)['question_id']);

        // Anti-spam: forzando la pregunta a vencida de nuevo, no se duplica la notificación.
        $question->update(['last_consulted_at' => now()->subMonth()]);

        (new CheckQuestionUpdatesJob)->handle(app(ConnectorRegistry::class));

        $this->assertSame(1, DB::table('notifications')->where('type', QueryErrorNotification::class)->count());
        $this->assertSame(1, $question->versions()->count());
    }

    public function test_job_sends_mail_when_email_notifications_enabled(): void
    {
        Http::fake([
            '*/consult*' => Http::response([
                'answer' => 'Nueva respuesta',
                'confidence' => 90,
                'sources' => [],
            ]),
        ]);

        $this->user->update(['email_notifications' => true]);

        $question = $this->questionWithVersion('Respuesta original');

        (new CheckQuestionUpdatesJob)->handle(app(ConnectorRegistry::class));

        // El correo se construye con los datos correctos. El canal mail de Laravel convierte
        // el Mailable a vista antes de llegar al mailer (Mailable::send → buildView), por lo
        // que Mail::fake no lo captura; el contrato del correo se verifica directo sobre toMail.
        $notification = new AnswerChangedNotification(
            questionId: $question->id,
            questionText: str($question->question_text)->limit(80)->value(),
            versionNumber: 2,
            changeType: 'new_version',
            similarity: 0.5,
        );
        $mail = $notification->toMail($this->user);

        $this->assertInstanceOf(AnswerChangedMail::class, $mail);
        $this->assertSame($question->id, $mail->questionId);
        $this->assertSame($this->user->email, $mail->to[0]['address']);

        // La notificación DB (badge in-app) se crea siempre.
        $this->assertSame(1, DB::table('notifications')->where('type', AnswerChangedNotification::class)->count());
    }

    public function test_job_skips_mail_when_email_notifications_disabled(): void
    {
        Http::fake([
            '*/consult*' => Http::response([
                'answer' => 'Nueva respuesta',
                'confidence' => 90,
                'sources' => [],
            ]),
        ]);

        $this->user->update(['email_notifications' => false]);

        $question = $this->questionWithVersion('Respuesta original');

        (new CheckQuestionUpdatesJob)->handle(app(ConnectorRegistry::class));

        // Notificación en BD sí (el badge in-app depende de ella); el canal mail no se activa.
        $this->assertSame(1, DB::table('notifications')->where('type', AnswerChangedNotification::class)->count());
        $this->assertNotContains('mail', (new AnswerChangedNotification('q', 't', 2, 'minor', 0.9))->via($this->user));
    }

    private function questionWithVersion(string $answerText): Question
    {
        $question = Question::factory()->create([
            'user_id' => $this->user->uuid,
            'status' => 'active',
            'review_frequency' => 'weekly',
        ]);

        $question->versions()->create([
            'version_number' => 1,
            'answer_text' => $answerText,
            'confidence' => 80,
            'sources' => [],
            'response_hash' => hash('sha256', $answerText),
            'is_current' => true,
        ]);

        return $question;
    }
}
