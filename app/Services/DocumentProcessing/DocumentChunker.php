<?php

namespace App\Services\DocumentProcessing;

/**
 * Ola 3, Punto 1 — Fase A.3: contrato del chunking, aislado para que la
 * estrategia definitiva de QuBeKa (tarea 7 de su spec) se integre sin
 * tocar el resto del flujo.
 */
interface DocumentChunker
{
    /**
     * @param  array<int, DocumentUnit>  $unidades
     * @return array<int, Chunk>
     */
    public function chunk(array $unidades): array;
}
