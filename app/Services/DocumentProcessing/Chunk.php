<?php

namespace App\Services\DocumentProcessing;

/**
 * Ola 3, Punto 1 — Fase A.3: chunk resultante del DocumentChunker.
 *
 * La trazabilidad de origen vive en QuBeKa (§1.6): Kuestion envía
 * pagina_origen/orden por chunk y no reconstruye nada localmente.
 */
final class Chunk
{
    public function __construct(
        public readonly string $texto,
        public readonly ?int $paginaInicio,
        public readonly ?int $paginaFin,
        public readonly ?string $heading,
        public readonly int $orden,
    ) {}

    /**
     * Forma §3.1 del contrato propuesto: { texto, pagina_origen, orden }.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'texto' => $this->texto,
            'pagina_origen' => $this->paginaInicio,
            'orden' => $this->orden,
        ];
    }
}
