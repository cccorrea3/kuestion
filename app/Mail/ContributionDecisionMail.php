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
 * Ola 2, Punto 5 — Fase C.2: correo al autor cuando su aporte fue aprobado o
 * rechazado (detección por polling de la Fase C.1). Copy según spec §2.1:
 * texto del aporte, CTA y pie de baja (mismo pie que B.3).
 */
class ContributionDecisionMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  bool  $aprobado  true → aprobado; false → rechazado
     * @param  string|null  $revisadoPorNombre  Nombre del revisor si QuBeKa lo expuso (B4/C.4)
     */
    public function __construct(
        public readonly bool $aprobado,
        public readonly string $textoAporte,
        public readonly int $sessionId,
        public readonly int $userId,
        public readonly ?string $revisadoPorNombre = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->aprobado
                ? 'Kuestion: tu aporte fue aprobado'
                : 'Kuestion: tu aporte fue rechazado',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.contribution-decision',
            with: [
                'aprobado' => $this->aprobado,
                'textoAporte' => $this->textoAporte,
                'sessionId' => $this->sessionId,
                'revisadoPorNombre' => $this->revisadoPorNombre,
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
