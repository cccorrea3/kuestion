<?php

namespace App\Services\Explicacion;

/**
 * Fase B — traduce los metadatos de explicabilidad a lenguaje natural con
 * plantillas fijas (§5 del documento de entrada: nada de LLM en runtime).
 *
 * Copy centralizado: este es el único lugar donde vive el texto (B.1).
 * El vocabulario replica las reglas de clasificación de QuBeKa
 * (reglas-clasificacion-explicabilidad.md) para no contradecir al
 * clasificador (B.3): Kuestion solo referencia reasons/patrones/alternativas
 * que QuBeKa envía — no re-clasifica ni infiere.
 *
 * Semáforo de confianza (§2.4, D4): verde >80%, amarillo 50-80%, rojo <50%.
 */
class ExplicacionPresenter
{
    /** Umbral verde (confianza estrictamente mayor). */
    public const UMBRAL_VERDE = 0.8;

    /** Umbral ámbar (por encima es verde; por debajo es rojo). */
    public const UMBRAL_AMARILLO = 0.5;

    /**
     * Fase principal por tipo — intención según el método QBK.
     */
    private const FRASES_TIPO = [
        'Q' => 'Se clasificó como Pregunta porque el texto abre un tema nuevo.',
        'SQ' => 'Se clasificó como Sub-pregunta porque el texto descompone un tema ya planteado.',
        'H' => 'Se clasificó como Hipótesis porque el texto afirma una causa o respuesta tentativa que se puede confirmar o refutar.',
        'N-K' => 'Se clasificó como Nota de conocimiento porque el texto aporta evidencia verificable.',
        'N-A' => 'Se clasificó como Nota de acción porque el texto describe una tarea o encargo a ejecutar.',
    ];

    /** @var array<string, string> */
    private const PATRONES_LEGIBLES = [
        'causa_declarada' => 'palabras de causa',
        'procedimiento' => 'instrucciones o pasos de acción',
        'hecho_confirmado' => 'fuente citada o dato verificable',
        'pregunta' => 'forma de pregunta',
        'afirmacion_sin_fuente' => 'afirmación sin fuente',
        'principio_enfoque' => 'declaración de principio o enfoque',
    ];

    /**
     * Frase principal de la decisión (FB.1). Con razones, cita la primera
     * (la evidencia del texto que activó la regla — §4 de las reglas de QuBeKa).
     *
     * @param  array<string, mixed>  $explicacion  Salida de ExplicacionNormalizer
     */
    public function frasePrincipal(array $explicacion): string
    {
        if ($explicacion['sin_detalle']) {
            return 'Este aporte no tiene detalle de clasificación disponible.';
        }

        $tipo = $explicacion['decision_type'];
        $base = self::FRASES_TIPO[$tipo] ?? 'Se clasificó el contenido según el método de conocimiento.';

        // La razón de QuBeKa cita la evidencia del texto (§4 de sus reglas):
        // se agrega como oración aparte, preservando su capitalización original.
        $razon = $explicacion['reasons'][0] ?? null;

        return $razon !== null
            ? $base.' '.rtrim($razon, '.').'.'
            : $base;
    }

    /**
     * Alternativas descartadas (FB.2): "También se evaluó como X, pero se descartó porque…".
     *
     * La razón puede venir como motivo puro ("no cita fuente verificable", forma de
     * las reglas de QuBeKa) o como oración completa ("Se descartó porque…", forma
     * observada en producción) — la plantilla se adapta, sin duplicar el verbo.
     *
     * @param  array<string, mixed>  $explicacion
     * @return list<string>
     */
    public function alternativas(array $explicacion): array
    {
        return array_map(
            function (array $alt): string {
                $tipo = $this->tipoLegible($alt['type']);
                $razon = $alt['reason'] !== '' ? $this->normalizarFrase($alt['reason']) : 'no predominó esa señal en el texto';

                $yaLoDice = str_contains(mb_strtolower($razon), 'se descart');

                return $yaLoDice
                    ? sprintf('También se evaluó como %s, pero %s.', $tipo, $razon)
                    : sprintf('También se evaluó como %s, pero se descartó porque %s.', $tipo, $razon);
            },
            $explicacion['alternatives_considered'],
        );
    }

    /**
     * Señales detectadas en lenguaje natural (FB.5: solo las que envía QuBeKa).
     *
     * @param  array<string, mixed>  $explicacion
     * @return list<string>
     */
    public function patronesLegibles(array $explicacion): array
    {
        return array_map(
            fn (string $patron): string => self::PATRONES_LEGIBLES[$patron] ?? str($patron)->replace('_', ' '),
            $explicacion['detected_patterns'],
        );
    }

    /**
     * B.2 — semáforo de confianza (§2.4): verde/amarillo/rojo.
     *
     * @param  array<string, mixed>  $explicacion
     * @return string|null null cuando no hay confianza medible
     */
    public function semaforo(array $explicacion): ?string
    {
        $confidence = $explicacion['confidence'];

        if ($confidence === null) {
            return null;
        }

        if ($confidence > self::UMBRAL_VERDE) {
            return 'verde';
        }

        if ($confidence >= self::UMBRAL_AMARILLO) {
            return 'amarillo';
        }

        return 'rojo';
    }

    /** Porcentaje entero para display ("85%"), null sin confianza. */
    public function porcentaje(array $explicacion): ?int
    {
        return $explicacion['confidence'] !== null
            ? (int) round($explicacion['confidence'] * 100)
            : null;
    }

    /**
     * Advertencia por confianza baja (§2.4, D5) — copy honesto y genérico,
     * sin inferir por cuenta propia (B.3).
     *
     * @param  array<string, mixed>  $explicacion
     */
    public function advertencia(array $explicacion): ?string
    {
        return $this->semaforo($explicacion) === 'rojo'
            ? 'El sistema no encontró suficiente evidencia para esta clasificación. Revisala antes de aprobar.'
            : null;
    }

    /**
     * Bloque completo listo para la UI (C.1).
     *
     * @param  array<string, mixed>  $explicacion
     * @return array{sin_detalle: bool, frase: string, alternativas: list<string>, patrones: list<string>, porcentaje: int|null, semaforo: string|null, advertencia: string|null}
     */
    public function presentar(array $explicacion): array
    {
        return [
            'sin_detalle' => $explicacion['sin_detalle'],
            'frase' => $this->frasePrincipal($explicacion),
            'alternativas' => $this->alternativas($explicacion),
            'patrones' => $this->patronesLegibles($explicacion),
            'porcentaje' => $this->porcentaje($explicacion),
            'semaforo' => $this->semaforo($explicacion),
            'advertencia' => $this->advertencia($explicacion),
        ];
    }

    /**
     * Etiquetas de tipo en lenguaje natural (mismas de la revisión existente;
     * N-A según el vocabulario QBK — Nota de acción).
     */
    public function tipoLegible(string $tipo): string
    {
        return match ($tipo) {
            'Q' => 'Pregunta',
            'SQ' => 'Sub-pregunta',
            'H' => 'Hipótesis',
            'N-K' => 'Nota de conocimiento',
            'N-A' => 'Nota de acción',
            default => $tipo,
        };
    }

    /** Minúscula inicial + quita punto final para empotrar la razón en la plantilla. */
    private function normalizarFrase(string $frase): string
    {
        $frase = rtrim($frase, '.');

        return mb_strtolower(mb_substr($frase, 0, 1)).mb_substr($frase, 1);
    }
}
