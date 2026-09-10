<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

/**
 * Ola 2, Punto 5 — Fase E.1: correo de reconfirmación pendiente (evento crítico
 * §2.2). Usa los datos del Punto 2 (vigenciaQbk): nodos QBK sobre el umbral de
 * 90 días sin reconfirmación. CTA "Reconfirmar" → detalle de la pregunta
 * (acción del Punto 2). Pie idéntico a los otros mails.
 */
class ReconfirmationDueMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $questionId,
        public readonly string $questionText,
        public readonly int $dias,
        public readonly int $userId,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Kuestion: ¿sigue siendo válido este conocimiento?',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.reconfirmation-due',
            with: [
                'questionText' => $this->questionText,
                'dias' => $this->dias,
                'url' => route('questions.show', $this->questionId),
                'settingsUrl' => URL::temporarySignedRoute('settings-subscribe', now()->addDays(30)),
                'unsubscribeUrl' => $this->unsubscribeUrl(),
            ],
        );
    }

    private function unsubscribeUrl(): ?string
    {
        if (! User::query()->find($this->userId)) {
            return null;
        }

        return URL::temporarySignedRoute(
            'unsubscribe',
            now()->addDays(30),
            ['user' => $this->userId],
        );
    }
}
