<?php

namespace App\Notifications;

use App\Mail\AnswerChangedMail;
use App\Services\EmailDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

// 1.1.3 — Reemplaza el insert crudo del job. El payload de toDatabase conserva exactamente
// las mismas claves que escribía el job (question_id, question_text, version_number,
// change_type, similarity), así ningún consumidor existente necesita cambios.
class AnswerChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $questionId,
        public readonly string $questionText,
        public readonly int $versionNumber,
        public readonly string $changeType,
        public readonly float $similarity,
        // 8.4 — Señales estructuradas (MCP), opcionales. null → la notificación
        // conserva el payload base idéntico al de antes (degradación con gracia).
        public readonly ?array $signals = null,
        // Ola 1 P5/6 — F3 (3.4): transición "sin respuesta → con respuesta".
        public readonly bool $wasEmptyPrev = false,
        // Ola 2, Punto 5 — B.2: primer párrafo de la nueva respuesta (spec §2.3).
        // Sin tipo ni readonly: las notificaciones en cola serializadas antes de este
        // deploy no traen la propiedad y una propiedad tipada quedaría "uninitialized"
        // al hidratar (hallazgo del E2E real). Untyped + default null sí recibe su
        // default durante unserialize → compatibilidad con payloads legacy.
        public $preview = null,
    ) {}

    /**
     * database: siempre (el badge in-app depende de ella).
     * mail: solo para `new_version` (spec §2.1 — los `minor` quedan solo in-app),
     * con la preferencia del usuario (A.3: solo `all` — este evento no es crítico)
     * y sin duplicado en la ventana de dedupe (A.2/B.4).
     *
     * Nota: la decisión de mail vive aquí (no en QuestionChecker) para que cualquier
     * remitente futuro respete el gate del spec. El dedupe se aplica en el momento
     * de decidir canales, único punto por el que pasa cada detección.
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        // En cola, via() puede ejecutarse más de una vez ante reintentos: logSent()
        // es idempotente (firstOrCreate sobre el bucket), así que no duplica.
        if (
            $this->changeType === 'new_version'
            && $notifiable->emailPreferenceAllows('new_version')
            && app(EmailDispatcher::class)->shouldSend($notifiable, 'new_version', $this->questionId)
        ) {
            app(EmailDispatcher::class)->logSent($notifiable, 'new_version', $this->questionId);
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toDatabase(object $notifiable): array
    {
        $payload = [
            'question_id' => $this->questionId,
            'question_text' => $this->questionText,
            'version_number' => $this->versionNumber,
            'change_type' => $this->changeType,
            'similarity' => $this->similarity,
        ];

        // Solo se agrega la clave cuando hay señales: sin ellas el payload es
        // byte a byte el de antes (los consumidores filtran por data->question_id).
        if ($this->signals !== null) {
            $payload['signals'] = $this->signals;
        }

        // Solo cuando es una transición sin→con: el payload base no cambia.
        if ($this->wasEmptyPrev) {
            $payload['was_empty_prev'] = true;
        }

        return $payload;
    }

    public function toMail(object $notifiable): AnswerChangedMail
    {
        $m = new AnswerChangedMail(
            questionId: $this->questionId,
            questionText: $this->questionText,
            versionNumber: $this->versionNumber,
            changeType: $this->changeType,
            similarity: $this->similarity,
            wasEmptyPrev: $this->wasEmptyPrev,
            preview: $this->preview,
            userId: (int) $notifiable->id,
        );
        $m->to($notifiable->routeNotificationFor('mail'));

        return $m;
    }
}
