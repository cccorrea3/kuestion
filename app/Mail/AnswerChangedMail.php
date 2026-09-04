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
        // Ola 1 P5/6 — F3 (3.5): transición "sin respuesta → con respuesta" (copy especial).
        public readonly bool $wasEmptyPrev = false,
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
                'wasEmptyPrev' => $this->wasEmptyPrev,
                'url' => route('questions.show', $this->questionId),
            ],
        );
    }
}
