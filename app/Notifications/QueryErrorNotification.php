<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

// 1.8 — Error de consulta (Kuaforia devolvió vacío). Solo canal database (in-app); el correo
// para errores transitorios sería ruido. Mismo patrón que AnswerChangedNotification.
class QueryErrorNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $questionId,
        public readonly string $questionText,
        public readonly string $reason,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'question_id' => $this->questionId,
            'question_text' => $this->questionText,
            'motivo' => $this->reason,
        ];
    }
}
