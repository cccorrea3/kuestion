<?php

namespace App\Livewire;

use App\Exceptions\KuaforiaException;
use App\Models\DocumentUpload;
use App\Services\DocumentProcessing\ChunkerProvisional;
use App\Services\DocumentProcessing\DocumentExtractor;
use App\Services\DocumentProcessing\DocumentHash;
use App\Services\QbkContributionService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Ola 3, Punto 1 — Fase B: flujo de carga asíncrono de documentos.
 *
 * Estados de UI (B.5/B.7 — nunca "cargando" infinito):
 * - idle: selector de archivo + contexto opcional.
 * - subiendo: validación + extracción + envío (bloqueo del formulario).
 * - procesando: "Procesando documento... esto puede tomar unos minutos." + polling 5s.
 * - listo: "Listo. Se propusieron N nodos... [Revisar]" → bandeja.
 * - error: mensaje legible + cómo proceder (reintentar / ir a la bandeja).
 */
#[Layout('layouts::app')]
class UploadDocument extends Component
{
    use WithFileUploads;

    /** @var TemporaryUploadedFile|null */
    public $documento;

    public string $contexto = '';

    public string $status = 'idle';

    public ?string $error = null;

    /** Sugerencia específica para el aviso de duplicado (FB-8): puede continuar o cancelar. */
    public ?int $duplicadoUploadId = null;

    /** B.5 — carga en curso (polling y reintentos). */
    public ?int $uploadId = null;

    public int $chunksProcesados = 0;

    public int $chunksTotales = 0;

    public int $nodosPropuestos = 0;

    /** Obs. 3 review: true cuando QuBeKa no expone progreso real de chunks (estado indeterminado, sin valores inventados). */
    public bool $sinProgreso = false;

    public function getRepositoriesProperty()
    {
        return auth()->user()->repositories()
            ->where('status', 'active')
            ->orderByDesc('is_default')
            ->orderBy('created_at')
            ->get();
    }

    public function mount(): void
    {
        // Si quedó una carga en procesando de una sesión anterior (p. ej. cerró
        // la pestaña), retomarla en lugar de arrancar limpia (B.4).
        $pendiente = DocumentUpload::where('user_id', current_user_id())
            ->where('estado', DocumentUpload::ESTADO_PROCESANDO)
            ->latest()
            ->first();

        if ($pendiente) {
            $this->uploadId = $pendiente->id;
            $this->status = 'procesando';
        }
    }

    /** B.2 — aviso previo de duplicado (FB-8): no bloquea, pide confirmación. */
    public function updatedDocumento(): void
    {
        $this->reset(['error', 'duplicadoUploadId']);

        if (! $this->documento) {
            return;
        }

        $formatos = (array) Config::get('kuestion.documentos.formatos', []);
        $extension = strtolower($this->documento->getClientOriginalExtension() ?: '');

        if (! in_array($extension, $formatos, true)) {
            $this->documento = null;
            $this->error = 'Formato no soportado. Usá TXT, MD, PDF o DOCX.';

            return;
        }

        $maxBytes = (int) Config::get('kuestion.documentos.max_bytes');

        if ($this->documento->getSize() > $maxBytes) {
            $this->documento = null;
            $this->error = 'El archivo supera el límite de '.round($maxBytes / 1024 / 1024).' MB.';

            return;
        }
    }

    /** B.1/B.2 — validar, extraer, chunkar y enviar. */
    public function submit(): void
    {
        $this->reset('error');

        if (! $this->documento) {
            $this->error = 'Seleccioná un archivo TXT, MD, PDF o DOCX.';

            return;
        }

        $repo = $this->repositories->first();

        if (! $repo) {
            $this->error = 'Conectá una fuente de conocimiento en Configuración para subir documentos.';

            return;
        }

        $this->status = 'subiendo';
        $this->duplicadoUploadId = null;

        try {
            // B.2 — validación de contenido (formato ya validado en updatedDocumento).
            $extension = strtolower($this->documento->getClientOriginalExtension() ?: '');
            $formatos = (array) Config::get('kuestion.documentos.formatos', []);

            if (! in_array($extension, $formatos, true)) {
                throw new \RuntimeException('Formato no soportado. Usá TXT, MD, PDF o DOCX.');
            }

            $contenido = $this->documento->get();
            $hash = DocumentHash::de(
                app(DocumentExtractor::class)->extract($contenido, $extension)
            );

            $previo = DocumentUpload::where('user_id', current_user_id())
                ->where('hash', $hash)
                ->where('estado', '!=', DocumentUpload::ESTADO_ERROR)
                ->orderByDesc('created_at')
                ->first();

            if ($previo && $previo->estado === DocumentUpload::ESTADO_PROCESANDO) {
                // Retomar la carga idéntica en curso en vez de duplicarla.
                $this->uploadId = $previo->id;
                $this->status = 'procesando';

                return;
            }

            if ($previo) {
                // FB-8: aviso antes de procesar; el usuario puede continuar o cancelar.
                $this->duplicadoUploadId = $previo->id;
                $this->status = 'idle';

                return;
            }

            $this->iniciarCarga($repo, $this->documento->getClientOriginalName() ?: 'documento', $extension, $contenido, $hash);
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();
            $this->status = 'error';
        } catch (\Throwable $e) {
            Log::warning('UploadDocument submit fallo', ['error' => $e->getMessage()]);
            $this->error = 'No se pudo leer el documento. Verificá que el archivo sea válido.';
            $this->status = 'error';
        }
    }

    /** FB-8: continuar a pesar del aviso de duplicado. */
    public function confirmarDuplicado(): void
    {
        if (! $this->documento || $this->duplicadoUploadId === null) {
            return;
        }

        $repo = $this->repositories->first();

        if (! $repo) {
            $this->error = 'No hay un repositorio conectado.';
            $this->status = 'error';

            return;
        }

        $this->duplicadoUploadId = null;
        $this->status = 'subiendo';

        try {
            $extension = strtolower($this->documento->getClientOriginalExtension() ?: '');
            $contenido = $this->documento->get();
            $hash = DocumentHash::de(
                app(DocumentExtractor::class)->extract($contenido, $extension)
            );

            $this->iniciarCarga($repo, $this->documento->getClientOriginalName() ?: 'documento', $extension, $contenido, $hash);
        } catch (\Throwable $e) {
            Log::warning('UploadDocument confirmarDuplicado fallo', ['error' => $e->getMessage()]);
            $this->error = 'No se pudo leer el documento. Verificá que el archivo sea válido.';
            $this->status = 'error';
        }
    }

    /** FB-8: cancelar tras el aviso de duplicado. */
    public function cancelarDuplicado(): void
    {
        $this->reset(['duplicadoUploadId', 'documento', 'contexto', 'error']);
        $this->status = 'idle';
    }

    /**
     * Hallazgo O3P1-1: continuar la carga previa desde el aviso de duplicado en
     * vez de crear otra sesión para el mismo documento. Aplica cuando la carga
     * anterior quedó en error con sesión ya creada en QuBeKa (p. ej. POST con
     * respuesta perdida): el polling resuelve el estado real de esa sesión.
     * Para duplicados en curso no hace falta: submit ya los retoma solo.
     */
    public function retomarDuplicado(): void
    {
        if ($this->duplicadoUploadId === null) {
            return;
        }

        $previo = DocumentUpload::where('user_id', current_user_id())->find($this->duplicadoUploadId);
        $this->reset(['duplicadoUploadId', 'documento', 'contexto', 'error']);

        if ($previo && $previo->estado === DocumentUpload::ESTADO_ERROR && $previo->qbk_session_id !== null) {
            $previo->update(['estado' => DocumentUpload::ESTADO_PROCESANDO, 'error' => null]);
            $this->uploadId = $previo->id;
            $this->status = 'procesando';

            return;
        }

        $this->status = 'idle';
    }

    /**
     * Extracción + chunking + envío con reintentos (B.3/B.6) + registro local.
     * Los chunks viven solo en memoria durante el envío (§4: se descartan tras clasificar).
     */
    private function iniciarCarga(object $repo, string $nombre, string $extension, string $contenido, string $hash): void
    {
        // A.2/A.3 — extracción y chunking (errores legibles por contrato de A).
        $unidades = app(DocumentExtractor::class)->extract($contenido, $extension);
        $chunks = app(ChunkerProvisional::class)->chunk($unidades);

        if ($chunks === []) {
            throw new \RuntimeException('El documento no contiene texto para analizar.');
        }

        // A.5 — límite de páginas (FA-6) aplicado también en el flujo real.
        // Obs. 4 review: DOCX no aporta páginas (pagina=null), el límite es efectivo
        // solo para PDF; en DOCX la protección práctica es max_bytes. Decisión
        // O3P1-2 documentada en el cierre — no se inventa un conteo para DOCX.
        $maxPaginas = (int) Config::get('kuestion.documentos.max_paginas', 100);
        $paginas = count(array_filter(array_map(fn ($u) => $u->pagina, $unidades)));

        if ($paginas > $maxPaginas) {
            throw new \RuntimeException("El documento supera el máximo de {$maxPaginas} páginas permitidas (límite aplicado a PDF).");
        }

        // QuBeKa limita los chunks por documento (MAX_CHUNKS=120, verificado contra
        // AnalisisService). Rechazamos temprano con mensaje claro en vez de un 422 genérico.
        $maxChunks = (int) Config::get('kuestion.documentos.max_chunks', 120);

        if (count($chunks) > $maxChunks) {
            throw new \RuntimeException("El documento es muy extenso y excede el límite de {$maxChunks} bloques de análisis. Dividilo en partes.");
        }

        $upload = DocumentUpload::create([
            'user_id' => current_user_id(),
            'repository_id' => $repo->id,
            'nombre' => $nombre,
            'hash' => $hash,
            'estado' => DocumentUpload::ESTADO_PROCESANDO,
            'chunks_totales' => count($chunks),
            'contexto' => mb_strimwidth($this->contexto, 0, 500) ?: null,
        ]);

        $this->uploadId = $upload->id;

        // B.3/B.6 — envío con reintentos (3, backoff exponencial) ante error de red.
        $intentos = (int) Config::get('kuestion.documentos.upload_intentos', 3);
        $backoffBase = (int) Config::get('kuestion.documentos.upload_backoff_base', 2);
        $payloadChunks = array_map(fn ($c, $i) => [
            'chunk_id' => 'chunk-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
            'texto' => $c->texto,
            'pagina_origen' => $c->paginaInicio,
            'orden' => $i + 1,
        ], $chunks, array_keys($chunks));

        $ultimoError = null;

        for ($intento = 1; $intento <= $intentos; $intento++) {
            try {
                $service = app(QbkContributionService::class);
                $result = $service->contributeDocument(
                    documentoNombre: $nombre,
                    chunks: $payloadChunks,
                    contexto: $upload->contexto,
                    credential: $repo->credential,
                    hashDocumento: $hash,
                );

                $upload->update([
                    'qbk_session_id' => $result['session_id'] ?: null,
                    'estado' => DocumentUpload::ESTADO_PROCESANDO,
                    'intentos' => $intento,
                    'error' => null,
                ]);

                $this->status = 'procesando';

                return;
            } catch (KuaforiaException $e) {
                $ultimoError = $e;

                // Reintento solo ante error de red/timeout (B.6); los errores de
                // credencial (401/403) no se reintentan: el mensaje es la acción.
                if (! in_array($e->getCode(), [504, 0], true)) {
                    break;
                }

                if ($intento < $intentos) {
                    sleep((int) ($backoffBase ** $intento));
                }
            }
        }

        // B.7 — fallo visible con la carga retenida para reintentar (FB-9).
        $upload->update([
            'estado' => DocumentUpload::ESTADO_ERROR,
            'error' => $ultimoError?->getMessage() ?? 'Error desconocido al enviar el documento.',
            'intentos' => $intentos,
        ]);

        throw $ultimoError ?? new \RuntimeException('No se pudo enviar el documento a QuBeKa.');
    }

    /** B.5 — polling cada 5s contra el detalle existente (decisión cerrada §3.3). */
    public function pollProgreso(): void
    {
        if ($this->status !== 'procesando' || $this->uploadId === null) {
            return;
        }

        $upload = DocumentUpload::find($this->uploadId);

        if (! $upload || $upload->user_id !== current_user_id()) {
            $this->status = 'idle';
            $this->uploadId = null;

            return;
        }

        // B.7/§4: timeout de análisis — 10 min sin llegar a un estado final
        // → se marca fallida y se retiene para reintentar.
        if ($upload->qbk_session_id !== null) {
            $vencido = (int) Config::get('kuestion.documentos.timeout_segundos', 600);

            if ($upload->created_at && $upload->created_at->lt(now()->subSeconds($vencido))) {
                $upload->update([
                    'estado' => DocumentUpload::ESTADO_ERROR,
                    'error' => 'El análisis del documento superó el tiempo máximo y fue cancelado. Reintentá o consultá el estado en QuBeKa.',
                ]);
                $this->error = $upload->error;
                $this->status = 'error';

                return;
            }
        }

        if ($upload->estado === DocumentUpload::ESTADO_LISTO) {
            $this->nodosPropuestos = $upload->nodos_propuestos;
            $this->chunksTotales = $upload->chunks_totales;
            $this->sinProgreso = false;
            $this->status = 'listo';

            return;
        }

        if ($upload->estado === DocumentUpload::ESTADO_ERROR) {
            $this->error = $upload->error ?? 'El análisis del documento falló.';
            $this->status = 'error';

            return;
        }

        if ($upload->qbk_session_id === null) {
            return;
        }

        $repo = $this->repositories->firstWhere('id', $upload->repository_id)
            ?? $this->repositories->first();

        if (! $repo) {
            return;
        }

        try {
            $service = app(QbkContributionService::class);
            $detalle = $service->getSession($upload->qbk_session_id, $repo->credential);

            // §3.3: progreso si viene; si el endpoint aún no lo expone, no se inventa.
            if ($detalle['chunks_procesados'] !== null) {
                $upload->chunks_procesados = (int) $detalle['chunks_procesados'];
            }

            if ($detalle['chunks_totales'] !== null) {
                $upload->chunks_totales = (int) $detalle['chunks_totales'];
            }

            $upload->save();
            $this->chunksProcesados = $upload->chunks_procesados;
            $this->chunksTotales = $upload->chunks_totales;
            // Obs. 3 review: si QuBeKa no trajo progreso, mostrar estado indeterminado.
            $this->sinProgreso = $upload->chunks_totales === 0;

            $estadosFinales = ['lista_para_revision', 'pendiente_revision', 'aprobada', 'promocionada', 'rechazada', 'error'];

            if (in_array($detalle['status'], $estadosFinales, true)) {
                $nodos = count($detalle['nodes'] ?? []);

                // Obs. 3 review: chunks_* guardan progreso real o quedan en 0 —
                // los nodos viven solo en nodos_propuestos, nunca se mezclan.
                $upload->update([
                    'estado' => $detalle['status'] === 'error' ? DocumentUpload::ESTADO_ERROR : DocumentUpload::ESTADO_LISTO,
                    'error' => $detalle['status'] === 'error' ? 'El análisis del documento falló en QuBeKa.' : null,
                    'nodos_propuestos' => $nodos,
                    'chunks_procesados' => $upload->chunks_procesados,
                    'chunks_totales' => $upload->chunks_totales,
                ]);

                if ($detalle['status'] === 'error') {
                    $this->error = $upload->error;
                    $this->status = 'error';
                } else {
                    $this->nodosPropuestos = $nodos;
                    $this->status = 'listo';
                }
            }
        } catch (KuaforiaException $e) {
            if ($e->getCode() === 401) {
                $repo->update(['status' => 'invalid', 'last_used_at' => now()]);
                $this->error = $e->getMessage();
                $this->status = 'error';
            }
            // Otros fallos transitorios del polling: mantener el estado (el
            // siguiente ciclo reintenta); el timeout de 10 min cubre el caso extremo.
        } catch (\Throwable $e) {
            Log::warning('UploadDocument poll error', ['upload_id' => $this->uploadId, 'error' => $e->getMessage()]);
        }
    }

    /** B.6/B.7 — reintentar una carga que falló (retenida en document_uploads). */
    public function reintentar(): void
    {
        if ($this->uploadId === null) {
            return;
        }

        $upload = DocumentUpload::where('user_id', current_user_id())->find($this->uploadId);

        if (! $upload) {
            return;
        }

        $upload->update(['estado' => DocumentUpload::ESTADO_PROCESANDO, 'error' => null]);
        $this->error = null;
        $this->status = 'procesando';
        $this->pollProgreso();
    }

    /** B.7 — volver al formulario limpio desde cualquier estado. */
    public function resetForm(): void
    {
        $this->reset(['documento', 'contexto', 'error', 'uploadId', 'duplicadoUploadId', 'chunksProcesados', 'chunksTotales', 'nodosPropuestos']);
        $this->status = 'idle';
    }

    /** FB-5: destino del botón [Revisar]. */
    public function irARevisar(): void
    {
        $this->redirectRoute('reviews.index');
    }

    public function render()
    {
        return view('livewire.upload-document');
    }
}
