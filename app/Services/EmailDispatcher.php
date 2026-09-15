<?php

namespace App\Services;

use App\Models\EmailLog;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Servicio central de envío de correos (Ola 2, Punto 5 — Fase A.2).
 *
 * Combina la decisión de preferencia (A.1/A.3) con la deduplicación por ventana
 * y el registro de envíos (spec §5: dedupe por evento/entidad, log de trazabilidad).
 * Todo correo de la Ola 2 pasa por shouldSend() → logSent(); el dedupe es también
 * la garantía de que los jobs de polling (C/D) no re-envían entre corridas.
 */
class EmailDispatcher
{
    /**
     * ¿Este evento debe enviarse a este usuario ahora?
     * false si: la preferencia no lo permite (A.3) o ya se envió en la ventana.
     */
    public function shouldSend(User $user, string $eventType, string $referenceKey): bool
    {
        if (! $user->emailPreferenceAllows($eventType)) {
            return false;
        }

        return ! EmailLog::query()
            ->where('user_id', $user->id)
            ->where('event_type', $eventType)
            ->where('reference_key', $referenceKey)
            ->where('sent_at', '>=', $this->windowStart())
            ->exists();
    }

    /**
     * Reclama el envío para este evento/entidad en la ventana actual y devuelve
     * si este proceso ganó el derecho a enviar.
     *
     * Atómico por diseño (B2): la decisión de enviar se toma sobre el resultado
     * del insert con índice único (user_id, event_type, reference_key, sent_at).
     * En carrera, solo el proceso cuya inserción gana devuelve true; el resto
     * ve la fila existente y no envía.
     */
    public function claim(User $user, string $eventType, string $referenceKey): bool
    {
        if (! $this->shouldSend($user, $eventType, $referenceKey)) {
            return false;
        }

        return $this->logSent($user, $eventType, $referenceKey);
    }

    /**
     * Registra el envío. En carrera, el índice único hace que solo una inserción
     * gane — el correo recién enviado nunca produce duplicados.
     */
    public function logSent(User $user, string $eventType, string $referenceKey): bool
    {
        return EmailLog::firstOrCreate([
            'user_id' => $user->id,
            'event_type' => $eventType,
            'reference_key' => $referenceKey,
            'sent_at' => $this->windowBucket(),
        ])->wasRecentlyCreated;
    }

    /**
     * Inicio de la ventana de dedupe, anclado a la grilla de bloques
     * (config/kuestion.email.ventana_dedupe_min, default 30).
     *
     * logSent() persiste sent_at = windowBucket() (inicio del bloque). Si acá se
     * usara una ventana deslizante exacta (now - N), un envío cerca del límite de
     * bloque quedaba fuera de la ventana apenas cruzaba la frontera y se duplicaba
     * (hallazgo B1). Al medir desde la misma grilla, entre envíos del mismo
     * evento/entidad pasa siempre ≥ N minutos (N..2N según posición en el bloque).
     */
    private function windowStart(): CarbonInterface
    {
        return $this->windowBucket()->subMinutes($this->windowMinutes());
    }

    /**
     * Bucket temporal de la ventana: al redondear sent_at al inicio de su bloque,
     * varios envíos dentro del mismo bloque colisionan en el índice único.
     */
    private function windowBucket(): CarbonInterface
    {
        $minutes = $this->windowMinutes();

        return now()->startOfMinute()->subMinutes(now()->format('i') % $minutes);
    }

    private function windowMinutes(): int
    {
        return max(1, (int) config('kuestion.email.ventana_dedupe_min', 30));
    }
}
