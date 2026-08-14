<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

// 1.1.5 — Mailable del correo de cambio. Se devuelve desde AnswerChangedNotification::toMail()
// para que el mail sea una instancia Mailable (testeable con Mail::fake) en lugar de una
// vista cruda (que el canal mail de Laravel envía sin pasar por el fake).
class AnswerChangedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $questionId,
        public readonly string $questionText,
        public readonly int $versionNumber,
        public readonly string $changeType,
        public readonly float $similarity,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Kuestion: respuesta actualizada',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.answer-changed',
            with: [
                'questionId' => $this->questionId,
                'questionText' => $this->questionText,
                'versionNumber' => $this->versionNumber,
                'changeType' => $this->changeType,
                'similarity' => $this->similarity,
                'url' => route('questions.show', $this->questionId),
            ],
        );
    }
}
