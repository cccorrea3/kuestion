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
 * Ola 2, Punto 5 — Fase D.2: correo a los revisores del workspace cuando hay un
 * aporte pendiente de revisión (spec §2.1, flujo 4.2: "Juan aportó, tú revisas").
 * CTA "Revisar ahora" → bandeja del Punto 1. Pie idéntico a los otros mails.
 */
class PendingReviewMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $autorNombre,
        public readonly string $textoAporte,
        public readonly int $sessionId,
        public readonly int $userId,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Kuestion: hay un aporte pendiente de tu revisión',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.pending-review',
            with: [
                'autorNombre' => $this->autorNombre,
                'textoAporte' => $this->textoAporte,
                'sessionId' => $this->sessionId,
                'url' => route('reviews.index'),
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
