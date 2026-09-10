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

// 1.1.5 — Mailable del correo de cambio. Se devuelve desde AnswerChangedNotification::toMail()
// para que el mail sea una instancia Mailable (testeable con Mail::fake) en lugar de una
// vista cruda (que el canal mail de Laravel envía sin pasar por el fake).
//
// Ola 2, Punto 5 — B.2/B.3: alineado al spec (§2.3) — asunto por evento, preview de la
// nueva respuesta, CTA "Ver cambios" y pie con configuración + baja por link firmado.
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
        // Ola 2, Punto 5 — B.2: primer párrafo de la nueva respuesta.
        public readonly ?string $preview = null,
        // B.3: necesario para generar el link firmado de baja en el pie.
        public readonly ?int $userId = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Kuestion: nueva versión de una respuesta que vigilas',
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
                'preview' => $this->preview,
                'url' => route('questions.show', $this->questionId),
                'settingsUrl' => URL::temporarySignedRoute('settings-subscribe', now()->addDays(30)),
                'unsubscribeUrl' => $this->unsubscribeUrl(),
            ],
        );
    }

    /** B.3 — baja sin login (A.4), válida 30 días; si no hay usuario, sin link de baja. */
    private function unsubscribeUrl(): ?string
    {
        if ($this->userId === null || ! User::query()->find($this->userId)) {
            return null;
        }

        return URL::temporarySignedRoute(
            'unsubscribe',
            now()->addDays(30),
            ['user' => $this->userId],
        );
    }
}
