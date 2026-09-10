<?php

namespace App\Jobs;

use App\Mail\ReconfirmationDueMail;
use App\Models\Question;
use App\Services\EmailDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Ola 2, Punto 5 — Fase E.1: correo de reconfirmación pendiente (evento crítico
 * §2.2), spec §6-7 (~9am). Fuente de datos: vigenciaQbk() del Punto 2 (ya
 * implementado) — la misma lista de la pestaña "reconfirmar" de la bandeja.
 *
 * D2 (decisión asumida del plan): un solo correo al cruzar el umbral — el dedupe
 * por ventana de A.2 lo aplica; una nueva ventana lo volvería a avisar solo si
 * el producto lo pide más adelante.
 */
class NotifyReconfirmationDueJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public array $backoff = [60, 300, 900];

    public function handle(EmailDispatcher $dispatcher): void
    {
        // Misma consulta que ReviewTray::vencidas (fuente P2), con datos del repo.
        $questions = Question::query()
            ->with('user', 'repository', 'currentVersion')
            ->whereHas('repository', fn ($q) => $q->where('connector_type', 'qbk')->where('status', 'active'))
            ->get();

        foreach ($questions as $question) {
            $vigencia = $question->vigenciaQbk();

            if (! in_array($vigencia['estado'], ['vencida', 'sin_dato'], true)) {
                continue;
            }

            // D5 — el autor es el dueño de la pregunta (ruteo mail ya definido en User).
            $user = $question->user;
            if (! $user) {
                continue;
            }

            if (! $dispatcher->shouldSend($user, 'reconfirmation_due', $question->id)) {
                continue;
            }

            try {
                Mail::to($user->email)->send(new ReconfirmationDueMail(
                    questionId: $question->id,
                    questionText: str($question->question_text)->limit(120)->value(),
                    dias: (int) ($vigencia['dias'] ?? 0),
                    userId: $user->id,
                ));
            } catch (\Throwable $e) {
                // F.4 — fallo de envío registrado con reintento de cola; el dedupe
                // no se marca, así la próxima corrida reintenta el envío.
                Log::warning('NotifyReconfirmationDueJob: fallo enviando correo', [
                    'question_id' => $question->id,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            $dispatcher->logSent($user, 'reconfirmation_due', $question->id);
        }
    }
}
