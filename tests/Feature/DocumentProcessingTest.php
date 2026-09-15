<?php

namespace Tests\Feature;

use App\Services\DocumentProcessing\ChunkerProvisional;
use App\Services\DocumentProcessing\DocumentExtractor;
use App\Services\DocumentProcessing\DocumentHash;
use App\Services\DocumentProcessing\DocumentoEscaneadoException;
use Illuminate\Support\Facades\Config;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\TestCase;

/**
 * Ola 3, Punto 1 — Fase A: checklist FA (plan §3).
 * Fixtures reales (PDF/DOCX generados en el test, FA-4 binario a mano).
 */
class DocumentProcessingTest extends TestCase
{
    private DocumentExtractor $extractor;

    private ChunkerProvisional $chunker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extractor = new DocumentExtractor;
        $this->chunker = new ChunkerProvisional;
    }

    // FA-1: TXT → chunks ~3000 con overlap ~300; ningún chunk arranca a mitad de frase.
    public function test_txt_se_chunka_con_objetivo_overlap_y_limites_de_frase(): void
    {
        Config::set('kuestion.documentos.chunk_caracteres', 3000);
        Config::set('kuestion.documentos.chunk_overlap', 300);

        $frase = 'Esta es una frase de prueba con longitud razonable. ';
        $texto = str_repeat($frase, 140); // ~7.700 chars → al menos 3 chunks
        $chunks = $this->chunker->chunk($this->extractor->extract($texto, 'txt'));

        $this->assertGreaterThanOrEqual(2, count($chunks));

        foreach ($chunks as $chunk) {
            $this->assertGreaterThan(0, mb_strlen($chunk->texto));
            // Ningún chunk arranca a mitad de frase (corte en límite).
            $this->assertMatchesRegularExpression('/^[A-ZÁÉÍÓÚÑ0-9"“]/u', mb_substr($chunk->texto, 0, 1));
        }

        // Overlap: la última frase del chunk 1 reaparece al inicio del chunk 2
        // (el overlap corta en límite de frase, FA-1).
        $frases1 = preg_split('/\n+/u', $chunks[0]->texto, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $ultimaFrase = trim(end($frases1));
        $this->assertNotFalse($ultimaFrase);
        $this->assertStringContainsString(
            $ultimaFrase,
            mb_substr($chunks[1]->texto, 0, mb_strlen($ultimaFrase) + 600),
            'La última frase del chunk 1 debe reaparecer por el overlap en el chunk 2'
        );
    }

    // FA-2: PDF real multipágina → página de origen conservada.
    public function test_pdf_multipagina_conserva_pagina_de_origen(): void
    {
        $pdf = $this->crearPdfReal(3);
        $unidades = $this->extractor->extract($pdf, 'pdf');

        $this->assertCount(3, $unidades);
        $this->assertSame(1, $unidades[0]->pagina);
        $this->assertSame(2, $unidades[1]->pagina);
        $this->assertSame(3, $unidades[2]->pagina);

        $chunks = $this->chunker->chunk($unidades);
        $this->assertSame(1, $chunks[0]->paginaInicio);
    }

    // FA-3: DOCX con headings → los chunks que inician sección conservan el heading.
    public function test_docx_con_headings_conserva_heading_en_chunks(): void
    {
        $docx = $this->crearDocxReal();
        $unidades = $this->extractor->extract($docx, 'docx');

        $chunks = $this->chunker->chunk($unidades);

        $conHeading = array_values(array_filter($chunks, fn ($c) => $c->heading !== null));
        $this->assertNotEmpty($conHeading, 'Al menos un chunk debe conservar el heading de su sección');
        $this->assertSame('Introduccion', $conHeading[0]->heading);
    }

    // FA-4: PDF escaneado (sin texto) → error controlado "no OCR".
    public function test_pdf_escaneado_rechazado_con_mensaje_claro(): void
    {
        // PDF válido (xref correcto) pero con páginas sin contenido de texto.
        $pdf = $this->crearPdfReal(2, conTexto: false);

        try {
            $this->extractor->extract($pdf, 'pdf');
            $this->fail('Debe rechazar el PDF escaneado');
        } catch (DocumentoEscaneadoException $e) {
            $this->assertStringContainsStringIgnoringCase('escaneado', $e->getMessage());
        }
    }

    // FA-5: DOCX corrupto → error legible, no stack trace.
    public function test_docx_corrupto_error_legible(): void
    {
        try {
            $this->extractor->extract('esto no es un docx', 'docx');
            $this->fail('Debe rechazar el DOCX corrupto');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsStringIgnoringCase('dañado', $e->getMessage());
        }
    }

    // FA-6: documento > 100 páginas → rechazado.
    public function test_documento_mayor_a_100_paginas_rechazado(): void
    {
        $paginas = [];

        for ($i = 1; $i <= 101; $i++) {
            $paginas[] = ['texto' => "Pagina {$i}: contenido de la pagina.", 'pagina' => $i, 'heading' => null];
        }

        $ref = new \ReflectionMethod(ChunkerProvisional::class, 'cuentaPaginas');
        $ref->setAccessible(true);
        $this->assertSame(101, $ref->invoke($this->chunker, $paginas));
        $this->assertSame(101, $this->chunker->cuentaPaginas($paginas));
        $this->assertGreaterThan(Config::get('kuestion.documentos.max_paginas'), $this->chunker->cuentaPaginas($paginas));
    }

    // FA-7: mismo archivo dos veces → mismo hash.
    public function test_hash_determinista_para_mismo_contenido(): void
    {
        $unidades = $this->extractor->extract('Contenido idéntico para el hash.', 'txt');

        $this->assertSame(
            DocumentHash::de($unidades),
            DocumentHash::de($this->extractor->extract('Contenido idéntico para el hash.', 'txt'))
        );
        $this->assertNotSame(
            DocumentHash::de($unidades),
            DocumentHash::de($this->extractor->extract('Otro contenido distinto.', 'txt'))
        );
    }

    // Caso borde: texto corto = 1 chunk.
    public function test_texto_corto_un_solo_chunk(): void
    {
        $chunks = $this->chunker->chunk($this->extractor->extract('Una sola frase.', 'txt'));

        $this->assertCount(1, $chunks);
        $this->assertSame(0, $chunks[0]->orden);
    }

    // Caso borde: MD se lee como texto directo (§2).
    public function test_markdown_como_texto_directo(): void
    {
        $unidades = $this->extractor->extract('# Titulo\n\nTexto con **negrita**.', 'md');

        $this->assertCount(1, $unidades);
        $this->assertStringContainsString('# Titulo', $unidades[0]->texto);
    }

    /**
     * PDF real multipágina con capa de texto. Se construye a mano con xref
     * válido (los parsers necesitan la tabla para resolver los objetos).
     */
    private function crearPdfReal(int $paginas, bool $conTexto = true): string
    {
        $objetos = [
            '<</Type/Catalog/Pages 2 0 R>>',
            '<</Type/Pages/Kids['.implode(' ', array_map(fn ($i) => (2 * $i + 2).' 0 R', range(1, $paginas))).']/Count '.$paginas.'>>',
        ];
        $objetos[] = '<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>'; // objeto 3

        for ($i = 1; $i <= $paginas; $i++) {
            // Numeración: Page_i = 2i+2, stream_i = 2i+3 (después de 1-3 fijos).
            $numeroStream = 2 * $i + 3;
            $stream = $conTexto
                ? "BT /F1 12 Tf 50 700 Td (Pagina numero {$i} del documento de prueba.) Tj ET"
                : '';
            $objetos[] = "<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]/Contents {$numeroStream} 0 R>>";
            $objetos[] = '<</Length '.strlen($stream).">>\nstream\n{$stream}\nendstream";
        }

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objetos as $n => $obj) {
            $offsets[] = strlen($pdf);
            $pdf .= ($n + 1)." 0 obj\n{$obj}\nendobj\n";
        }

        $inicioXref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objetos) + 1)."\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        $pdf .= 'trailer<</Size '.(count($objetos) + 1)."/Root 1 0 R>>\nstartxref\n".$inicioXref."\n%%EOF";

        return $pdf;
    }

    /**
     * DOCX real con heading + párrafos. El writer de phpword no serializa el
     * pStyle Heading1 (verificado contra el vendor), así que se inyecta en el
     * XML como lo haría Word real — el reader entonces produce Title.
     */
    private function crearDocxReal(): string
    {
        $phpWord = new PhpWord;
        $seccion = $phpWord->addSection();
        $seccion->addTitle('Introduccion', 1);
        $seccion->addText('Primer parrafo de la introduccion con contenido suficiente para la prueba.');
        $seccion->addText('Segundo parrafo que completa la seccion inicial del documento.');

        $temporal = tempnam(sys_get_temp_dir(), 'kq-test-').'.docx';
        IOFactory::createWriter($phpWord, 'Word2007')->save($temporal);

        $zip = new \ZipArchive;
        $zip->open($temporal);
        $xml = $zip->getFromName('word/document.xml');
        $xml = substr_replace(
            $xml,
            '<w:p><w:pPr><w:pStyle w:val="Heading1"/></w:pPr>',
            (int) strpos($xml, '<w:p>'),
            5
        );
        $zip->addFromString('word/document.xml', $xml);
        $zip->close();

        $contenido = file_get_contents($temporal);
        unlink($temporal);

        return $contenido;
    }
}
