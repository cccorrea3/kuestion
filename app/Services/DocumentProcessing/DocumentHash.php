<?php

namespace App\Services\DocumentProcessing;

/**
 * Ola 3, Punto 1 — Fase A.6/§4: hash del contenido extraído.
 *
 * Base del aviso previo "este documento ya se subió": mismo archivo
 * dos veces → mismo hash (FA-7). Se calcula sobre el texto extraído
 * (no sobre el binario) para que el mismo contenido en PDF y DOCX
 * genere el mismo hash.
 */
final class DocumentHash
{
    /**
     * @param  array<int, DocumentUnit>  $unidades
     */
    public static function de(array $unidades): string
    {
        $texto = implode("\n", array_map(fn (DocumentUnit $u) => $u->texto, $unidades));

        return hash('sha256', $texto);
    }
}
