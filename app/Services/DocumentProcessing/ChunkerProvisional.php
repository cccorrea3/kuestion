<?php

namespace App\Services\DocumentProcessing;

use Illuminate\Support\Facades\Config;

/**
 * Ola 3, Punto 1 — Fase A.3: estrategia provisional (§1.3 propuesta:
 * ~3000 chars objetivo, ~300 de overlap).
 *
 * Reglas del plan: ningún chunk arranca a mitad de frase; el overlap
 * corta en límite de frase; cada chunk conserva página(s) y heading de
 * origen. Cuando QuBeKa defina la suya (tarea 7), se implementa la
 * interfaz y se reemplaza esta clase — el resto del flujo no cambia.
 */
final class ChunkerProvisional implements DocumentChunker
{
    /**
     * FA-6: documento > max_paginas → rechazado antes de chunkar.
     * Acepta DocumentUnit[] (o arrays con clave 'pagina', para pruebas).
     *
     * @param  array<int, DocumentUnit|array<string, mixed>>  $unidades
     */
    public function cuentaPaginas(array $unidades): int
    {
        $paginas = [];

        foreach ($unidades as $unidad) {
            $numero = $unidad instanceof DocumentUnit
                ? $unidad->pagina
                : ($unidad['pagina'] ?? null);

            if ($numero !== null) {
                $paginas[$numero] = true;
            }
        }

        return count($paginas);
    }

    /**
     * @param  array<int, DocumentUnit>  $unidades
     * @return array<int, Chunk>
     */
    public function chunk(array $unidades): array
    {
        $maxPaginas = (int) Config::get('kuestion.documentos.max_paginas', 100);

        if ($this->cuentaPaginas($unidades) > $maxPaginas) {
            throw new \RuntimeException("El documento supera el máximo de {$maxPaginas} páginas permitidas.");
        }

        $objetivo = (int) Config::get('kuestion.documentos.chunk_caracteres', 3000);
        $overlap = (int) Config::get('kuestion.documentos.chunk_overlap', 300);

        $tokens = [];

        foreach ($unidades as $unidad) {
            $frases = preg_split('/\n+|(?<=[.!?…])\s+/u', $unidad->texto, -1, PREG_SPLIT_NO_EMPTY) ?: [];

            foreach ($frases as $frase) {
                $frase = trim($frase);

                if ($frase !== '') {
                    $tokens[] = ['texto' => $frase, 'pagina' => $unidad->pagina, 'heading' => $unidad->heading];
                }
            }
        }

        $chunks = [];
        $actuales = [];

        $cerrar = function () use (&$actuales, &$chunks): void {
            if ($actuales === []) {
                return;
            }

            $chunks[] = new Chunk(
                texto: implode("\n", array_column($actuales, 'texto')),
                paginaInicio: $actuales[0]['pagina'],
                paginaFin: end($actuales)['pagina'],
                heading: $actuales[0]['heading'],
                orden: count($chunks),
            );
        };

        foreach ($tokens as $i => $token) {
            $largoToken = mb_strlen($token['texto']);

            // Caso borde: párrafo sin cortes de frase más largo que el objetivo.
            if ($largoToken > $objetivo && $actuales === []) {
                foreach (mb_str_split($token['texto'], $objetivo) as $trozo) {
                    $actuales = [['texto' => $trozo, 'pagina' => $token['pagina'], 'heading' => $token['heading']]];
                    $cerrar();
                }

                $actuales = [];

                continue;
            }

            $largoActuales = array_sum(array_map(fn ($t) => mb_strlen($t['texto']) + 1, $actuales));

            if ($actuales !== [] && $largoActuales + $largoToken > $objetivo) {
                $cerrar();

                // El overlap no cruza límites de página ni de heading:
                // preserva la trazabilidad de origen por chunk (§1.6).
                $siguiente = $tokens[$i + 1] ?? null;
                $cambiaOrigen = $siguiente !== null
                    && ($siguiente['pagina'] !== $token['pagina'] || $siguiente['heading'] !== $token['heading']);

                if ($cambiaOrigen) {
                    $actuales = [];

                    continue;
                }

                // Overlap: reusar las últimas frases (cortadas en límite de frase).
                $reuso = [];

                for ($j = count($actuales) - 1; $j >= 0; $j--) {
                    array_unshift($reuso, $actuales[$j]);

                    if (array_sum(array_map(fn ($t) => mb_strlen($t['texto']) + 1, $reuso)) >= $overlap) {
                        break;
                    }
                }

                $actuales = $reuso;
            }

            $actuales[] = $token;
        }

        // Documento corto = 1 chunk (caso borde del plan).
        $cerrar();

        return $chunks;
    }
}
