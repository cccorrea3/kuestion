<?php

namespace App\Notifications;

use App\Mail\AnswerChangedMail;
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
    ) {}

    /**
     * database: siempre (el badge in-app depende de ella).
     * mail: solo si el usuario activó las notificaciones por correo.
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if ($notifiable->email_notifications) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'question_id' => $this->questionId,
            'question_text' => $this->questionText,
            'version_number' => $this->versionNumber,
            'change_type' => $this->changeType,
            'similarity' => $this->similarity,
        ];
    }

    public function toMail(object $notifiable): AnswerChangedMail
    {
        $m = new AnswerChangedMail(
            questionId: $this->questionId,
            questionText: $this->questionText,
            versionNumber: $this->versionNumber,
            changeType: $this->changeType,
            similarity: $this->similarity,
        );
        $m->to($notifiable->routeNotificationFor('mail'));

        return $m;
    }
}
