<?php

namespace App\Services\DocumentProcessing;

use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\Element\Title;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use Smalot\PdfParser\Parser as PdfParser;

/**
 * Ola 3, Punto 1 — Fase A.2: extracción TXT/MD/PDF/DOCX (§1.2).
 *
 * TXT/MD: lectura directa. PDF: smalot/pdfparser con preservación de
 * saltos de página. DOCX: phpoffice/phpword con headings.
 *
 * Contrato de errores (§2): escaneado → mensaje "No OCR"; corrupto →
 * mensaje legible. Nunca una excepción cruda hacia la UI.
 */
final class DocumentExtractor
{
    /** @return array<int, DocumentUnit> */
    public function extract(string $contenido, string $extension): array
    {
        return match ($extension) {
            'txt' => $this->desdeTextoPlano($contenido),
            'md' => $this->desdeMarkdown($contenido),
            'pdf' => $this->desdePdf($contenido),
            'docx' => $this->desdeDocx($contenido),
            default => throw new \InvalidArgumentException("Formato no soportado: {$extension}"),
        };
    }

    /** @return array<int, DocumentUnit> */
    private function desdeTextoPlano(string $contenido): array
    {
        $texto = trim($contenido);

        if ($texto === '') {
            throw new \RuntimeException('El documento no contiene texto.');
        }

        return DocumentUnit::fromTextoPlano($texto);
    }

    /** @return array<int, DocumentUnit> */
    private function desdeMarkdown(string $contenido): array
    {
        // §2: sin convertir a HTML — MD se lee como texto directo.
        return $this->desdeTextoPlano($contenido);
    }

    /** @return array<int, DocumentUnit> */
    private function desdePdf(string $contenido): array
    {
        try {
            $documento = (new PdfParser)->parseContent($contenido);
            $paginas = $documento->getPages();
        } catch (\Throwable $e) {
            throw new \RuntimeException('No se pudo leer el PDF. Verificá que el archivo no esté dañado.', 0, $e);
        }

        if ($paginas === []) {
            throw new \RuntimeException('El PDF no contiene páginas legibles.');
        }

        $unidades = [];
        $caracteres = 0;

        foreach ($paginas as $pagina) {
            $texto = trim((string) $pagina->getText());

            if ($texto === '') {
                continue;
            }

            $caracteres += mb_strlen($texto);
            $unidades[] = new DocumentUnit($texto, count($unidades) + 1, null);
        }

        // A.4: PDF escaneado (solo imágenes) → error controlado, no OCR.
        if ($caracteres === 0) {
            throw new DocumentoEscaneadoException(
                'El PDF parece ser un documento escaneado (sin texto seleccionable). No es posible extraer su contenido.'
            );
        }

        return $unidades;
    }

    /** @return array<int, DocumentUnit> */
    private function desdeDocx(string $contenido): array
    {
        $temporal = tempnam(sys_get_temp_dir(), 'kq-docx-');

        try {
            file_put_contents($temporal, $contenido);

            try {
                $phpWord = WordIOFactory::load($temporal, 'Word2007');
            } catch (\Throwable $e) {
                throw new \RuntimeException('El archivo DOCX no es válido o está dañado.', 0, $e);
            }

            $secciones = $phpWord->getSections();

            if ($secciones === []) {
                throw new \RuntimeException('El documento DOCX no contiene secciones legibles.');
            }

            $unidades = [];
            $headingActual = null;
            $parrafos = [];

            $flush = function () use (&$parrafos, &$unidades, &$headingActual): void {
                $texto = trim(implode("\n", array_filter($parrafos, fn ($p) => $p !== '')));

                if ($texto !== '') {
                    $unidades[] = new DocumentUnit($texto, null, $headingActual);
                }

                $parrafos = [];
            };

            foreach ($secciones as $seccion) {
                foreach ($seccion->getElements() as $elemento) {
                    if ($elemento instanceof Title) {
                        // Heading: corta el párrafo anterior y etiqueta el siguiente.
                        $flush();
                        $headingActual = trim($elemento->getText());

                        continue;
                    }

                    if ($elemento instanceof TextRun) {
                        $parrafos[] = $this->textoDeRun($elemento);

                        continue;
                    }

                    if ($elemento instanceof Text) {
                        $parrafos[] = (string) $elemento->getText();
                    }
                }
            }

            $flush();

            if ($unidades === []) {
                throw new \RuntimeException('El documento DOCX no contiene texto.');
            }

            return $unidades;
        } finally {
            @unlink($temporal);
        }
    }

    /** TextRun::getText() devuelve elementos internos, no string — se aplana. */
    private function textoDeRun(TextRun $run): string
    {
        $partes = [];

        foreach ($run->getElements() as $interno) {
            if ($interno instanceof Text) {
                $partes[] = (string) $interno->getText();
            } elseif (method_exists($interno, 'getText') && is_string($interno->getText())) {
                $partes[] = $interno->getText();
            }
        }

        return implode('', $partes);
    }
}
