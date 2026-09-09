<?php

namespace App\Services\Explicacion;

/**
 * Normaliza el objeto `explicacion` del contrato Ola 2 §5.3 al formato interno
 * que consumen el presentador y la UI (Fase A.2/A.3).
 *
 * Campos (por nodo, snake_case, según QuBeKa — verificado en código real):
 *   decision_type: string|null      (Q/SQ/H/N-K/N-A)
 *   confidence:     float|null      (0.0-1.0, auto-evaluación del LLM — señal orientativa)
 *   reasons:        list<string>
 *   alternatives_considered: list<array{type: string, reason: string}>
 *   detected_patterns: list<string>
 *
 * Sesiones creadas antes del despliegue no traen el objeto: se normalizan a
 * ['sin_detalle' => true] para que la UI muestre el estado de degradación
 * (A.4 / D3) — nunca se inventa una explicación.
 *
 * Regla de precedencia Q4.5: explicacion.* es la única fuente de display.
 * Este normalizador nunca cae a confianza/justificacion_ia.
 */
class ExplicacionNormalizer
{
    /**
     * @param  array<string, mixed>  $raw  Objeto explicacion crudo del contrato
     * @return array{sin_detalle: bool, decision_type: string|null, confidence: float|null, reasons: list<string>, alternatives_considered: list<array{type: string, reason: string}>, detected_patterns: list<string>}
     */
    public static function fromArray(array $raw): array
    {
        $decisionType = isset($raw['decision_type']) && is_string($raw['decision_type']) && $raw['decision_type'] !== ''
            ? $raw['decision_type']
            : null;

        $confidence = isset($raw['confidence']) && is_numeric($raw['confidence'])
            ? (float) $raw['confidence']
            : null;

        $reasons = self::stringList($raw['reasons'] ?? null);

        // Contrato §2.3 del documento: lista de objetos {type, reason}.
        // Entradas sin 'type' utilizable se descartan (default seguro).
        $alternatives = [];
        foreach ((array) ($raw['alternatives_considered'] ?? []) as $alt) {
            if (! is_array($alt)) {
                continue;
            }

            $type = isset($alt['type']) && is_string($alt['type']) && $alt['type'] !== '' ? $alt['type'] : null;
            $reason = isset($alt['reason']) && is_string($alt['reason']) ? $alt['reason'] : '';

            if ($type !== null) {
                $alternatives[] = ['type' => $type, 'reason' => $reason];
            }
        }

        return [
            'sin_detalle' => false,
            'decision_type' => $decisionType,
            'confidence' => $confidence,
            'reasons' => $reasons,
            'alternatives_considered' => $alternatives,
            'detected_patterns' => self::stringList($raw['detected_patterns'] ?? null),
        ];
    }

    /**
     * A.4 — estado de degradación para sesiones sin metadatos (pre-despliegue).
     *
     * @return array{sin_detalle: bool, decision_type: null, confidence: null, reasons: array{}, alternatives_considered: array{}, detected_patterns: array{}}
     */
    public static function sinDetalle(): array
    {
        return [
            'sin_detalle' => true,
            'decision_type' => null,
            'confidence' => null,
            'reasons' => [],
            'alternatives_considered' => [],
            'detected_patterns' => [],
        ];
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn ($v) => is_string($v) ? $v : '', $value),
            fn (string $v): bool => $v !== '',
        ));
    }
}
