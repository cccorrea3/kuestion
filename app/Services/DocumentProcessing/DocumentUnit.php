<?php

namespace App\Services\DocumentProcessing;

/**
 * Ola 3, Punto 1 — Fase A.2: unidad de texto extraída de un documento.
 *
 * Output tipado del DocumentExtractor: cada unidad conserva su metadata
 * de origen (§1.2) para que el chunker pueda llevarla hasta el chunk.
 */
final class DocumentUnit
{
    public function __construct(
        public readonly string $texto,
        public readonly ?int $pagina,
        public readonly ?string $heading,
    ) {}

    /**
     * @return array<int, self>
     */
    public static function fromTextoPlano(string $texto): array
    {
        return [new self($texto, null, null)];
    }
}
