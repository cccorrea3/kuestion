<?php

namespace Tests\Feature;

use App\Livewire\QuestionFeed;
use App\Mail\AnswerChangedMail;
use App\Models\Question;
use App\Models\User;
use App\Notifications\AnswerChangedNotification;
use App\Services\ConnectorRegistry;
use App\Services\KuaforiaResponse;
use App\Services\QuestionChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Fakes\FakeRagProvider;
use Tests\TestCase;

class AnswerWasEmptyPrevTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        config(['services.kuaforia.mcp_api_key' => 'test-key']);
    }

    // 3.8 (a) — versión 2 con was_empty_prev=true cuando la anterior tenía found=false.
    public function test_new_version_marks_was_empty_prev_when_previous_found_false(): void
    {
        $fake = new FakeRagProvider;
        $fake->respondWith(new KuaforiaResponse(
            answerText: 'Ahora sí hay una respuesta real',
            confidence: 85.0,
            sources: [],
            found: true,
        ));
        $this->app->instance(get_class($fake), $fake);
        $checker = $this->checkerWith($fake);

        // Versión 1: found=false con texto informativo (comportamiento real de QBK).
        $question = $this->questionWithVersion('No encontré información relevante', found: false);

        $result = $checker->check($question);

        $this->assertSame('changed', $result['status']);
        $this->assertTrue($result['was_empty_prev']);

        $v2 = $question->fresh()->currentVersion;
        $this->assertTrue($v2->found);
        $this->assertTrue($v2->was_empty_prev);
    }

    // 3.8 (b) — was_empty_prev=false cuando la anterior ya tenía found=true.
    public function test_new_version_does_not_mark_was_empty_prev_when_previous_found_true(): void
    {
        $fake = new FakeRagProvider;
        $fake->respondWith(new KuaforiaResponse(
            answerText: 'Respuesta actualizada con cambios normales',
            confidence: 90.0,
            sources: [],
            found: true,
        ));
        $this->app->instance(get_class($fake), $fake);
        $checker = $this->checkerWith($fake);

        $question = $this->questionWithVersion('Respuesta original', found: true);

        $result = $checker->check($question);

        $this->assertSame('changed', $result['status']);
        $this->assertFalse($result['was_empty_prev']);

        $v2 = $question->fresh()->currentVersion;
        $this->assertTrue($v2->found);
        $this->assertFalse($v2->was_empty_prev);
    }

    // 3.8 (c) — la card muestra el copy especial en vez de "Cambio sin revisar".
    public function test_feed_card_shows_special_copy_when_was_empty_prev(): void
    {
        $question = $this->questionWithVersion('No encontré información relevante', found: false);
        // Simular la transición: versión 2 con was_empty_prev=true y cambios sin revisar.
        $question->versions()->where('is_current', true)->update(['is_current' => false]);
        $question->versions()->create([
            'version_number' => 2,
            'answer_text' => 'Ahora sí hay una respuesta real',
            'confidence' => 85,
            'sources' => [],
            'response_hash' => hash('sha256', 'Ahora sí hay una respuesta real'),
            'found' => true,
            'was_empty_prev' => true,
            'is_current' => true,
            'status' => 'new_version',
        ]);
        $question->update([
            'has_unreviewed_changes' => true,
            'last_change_detected_at' => now(),
        ]);

        $this->actingAs($this->user);

        Livewire::test(QuestionFeed::class)
            ->assertSee('Ahora hay información sobre algo que preguntaste')
            ->assertDontSee('Cambio sin revisar');
    }

    // 3.8 (c cont.) — cambio normal: sin copy especial, con "Cambio sin revisar".
    public function test_feed_card_keeps_normal_copy_when_not_was_empty_prev(): void
    {
        $question = $this->questionWithVersion('Respuesta original', found: true);
        $question->versions()->where('is_current', true)->update(['is_current' => false]);
        $question->versions()->create([
            'version_number' => 2,
            'answer_text' => 'Respuesta actualizada',
            'confidence' => 90,
            'sources' => [],
            'response_hash' => hash('sha256', 'Respuesta actualizada'),
            'found' => true,
            'was_empty_prev' => false,
            'is_current' => true,
            'status' => 'new_version',
        ]);
        $question->update([
            'has_unreviewed_changes' => true,
            'last_change_detected_at' => now(),
        ]);

        $this->actingAs($this->user);

        Livewire::test(QuestionFeed::class)
            ->assertSee('Cambio sin revisar')
            ->assertDontSee('Ahora hay información sobre algo que preguntaste');
    }

    // 3.8 (d) — la notificación lleva was_empty_prev=true solo cuando corresponde.
    public function test_notification_payload_includes_was_empty_prev_only_when_true(): void
    {
        Notification::fake();

        // Transición sin→con: clave presente.
        $this->user->notify(new AnswerChangedNotification(
            questionId: 'q-1',
            questionText: 'Pregunta',
            versionNumber: 2,
            changeType: 'major',
            similarity: 0.3,
            wasEmptyPrev: true,
        ));

        Notification::assertSentTo($this->user, AnswerChangedNotification::class, function ($n) {
            $payload = $n->toDatabase($this->user);
            $this->assertArrayHasKey('was_empty_prev', $payload);
            $this->assertTrue($payload['was_empty_prev']);

            return true;
        });

        Notification::fake();

        // Cambio normal: clave ausente (compatibilidad con consumidores existentes).
        $this->user->notify(new AnswerChangedNotification(
            questionId: 'q-2',
            questionText: 'Pregunta',
            versionNumber: 2,
            changeType: 'major',
            similarity: 0.3,
        ));

        Notification::assertSentTo($this->user, AnswerChangedNotification::class, function ($n) {
            $payload = $n->toDatabase($this->user);
            $this->assertArrayNotHasKey('was_empty_prev', $payload);

            return true;
        });
    }

    // 3.8 (d cont.) — el mail lleva el copy especial cuando was_empty_prev=true.
    public function test_mail_shows_special_copy_when_was_empty_prev(): void
    {
        $mail = new AnswerChangedMail(
            questionId: 'q-1',
            questionText: 'Pregunta',
            versionNumber: 2,
            changeType: 'major',
            similarity: 0.3,
            wasEmptyPrev: true,
        );

        $html = $mail->render();

        $this->assertStringContainsString('Ahora hay información sobre algo que preguntaste', $html);
        $this->assertStringNotContainsString('Cambio menor', $html);
        $this->assertStringNotContainsString('Nueva versión', $html);
    }

    // 3.8 (d cont.) — el mail mantiene el copy normal sin was_empty_prev.
    public function test_mail_keeps_normal_copy_without_was_empty_prev(): void
    {
        $mail = new AnswerChangedMail(
            questionId: 'q-1',
            questionText: 'Pregunta',
            versionNumber: 2,
            changeType: 'minor',
            similarity: 0.7,
        );

        $html = $mail->render();

        $this->assertStringContainsString('Cambio menor', $html);
        $this->assertStringNotContainsString('Ahora hay información sobre algo que preguntaste', $html);
    }

    // 3.8 (e) — el flujo completo via QuestionChecker persiste found en la notificación.
    public function test_checker_notification_carries_was_empty_prev_flag(): void
    {
        $fake = new FakeRagProvider;
        $fake->respondWith(new KuaforiaResponse(
            answerText: 'Ahora sí hay una respuesta real',
            confidence: 85.0,
            sources: [],
            found: true,
        ));
        $this->app->instance(get_class($fake), $fake);
        $checker = $this->checkerWith($fake);

        $question = $this->questionWithVersion('No encontré información relevante', found: false);

        $checker->check($question);

        $notification = DB::table('notifications')->where('type', AnswerChangedNotification::class)->first();
        $this->assertNotNull($notification);
        $data = json_decode($notification->data, true);
        $this->assertArrayHasKey('was_empty_prev', $data);
        $this->assertTrue($data['was_empty_prev']);
    }

    private function checkerWith(FakeRagProvider $fake): QuestionChecker
    {
        config(['kuestion.connectors.kuaforia.rag_provider' => get_class($fake)]);

        return new QuestionChecker(app(ConnectorRegistry::class));
    }

    private function questionWithVersion(string $answerText, bool $found): Question
    {
        $question = Question::factory()->create([
            'user_id' => $this->user->uuid,
            'status' => 'active',
            'review_frequency' => 'weekly',
            'has_unreviewed_changes' => false,
        ]);

        $question->versions()->create([
            'version_number' => 1,
            'answer_text' => $answerText,
            'confidence' => 50,
            'sources' => [],
            'response_hash' => hash('sha256', $answerText),
            'found' => $found,
            'was_empty_prev' => false,
            'is_current' => true,
        ]);

        return $question;
    }
}
