<?php

namespace App\Services\DocumentProcessing;

/**
 * Ola 3, Punto 1 — A.4/§2: PDF sin capa de texto (escaneado) → error
 * controlado "no OCR", nunca una excepción cruda.
 */
final class DocumentoEscaneadoException extends \RuntimeException {}
