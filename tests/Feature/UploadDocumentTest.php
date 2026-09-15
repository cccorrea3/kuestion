<?php

namespace Tests\Feature;

use App\Livewire\UploadDocument;
use App\Models\DocumentUpload;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ola 3, Punto 1 — Fase B: checklist FB contra mock del contrato propuesto
 * (H1: QuBeKa no tiene /contribute/document todavía; validación real en Fase D).
 */
class UploadDocumentTest extends TestCase
{
    private User $user;

    private Repository $repo;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.qubeka.api_url' => 'http://qbk-test']);
        config(['kuestion.documentos.upload_backoff_base' => 0]);

        $this->user = User::factory()->create();
        $this->repo = Repository::factory()->create([
            'name' => 'QBK Upload Repo',
            'user_id' => $this->user->uuid,
            'status' => 'active',
            'connector_type' => 'qbk',
            'credential' => ['api_token' => 'tok-test'],
        ]);

        $this->actingAs($this->user);
        Storage::fake('local');
    }

    private function txtFile(string $contenido = 'Contenido del documento de prueba.'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('prueba.txt', $contenido);
    }

    /** FB-1/FB-2 (parte automatizable): el formulario muestra selector + contexto opcional. */
    public function test_formulario_muestra_selector_y_contexto(): void
    {
        Livewire::test(UploadDocument::class)
            ->assertSee('Subir documento')
            ->assertSee('type="file"', false)
            ->assertSee('¿De qué trata este documento?')
            ->assertSee('accept=".txt,.md,.pdf,.docx"', false);
    }

    /** FB-2/FB-3: archivo válido → envío → estado procesando. */
    public function test_archivo_valido_envia_y_queda_procesando(): void
    {
        Http::fake([
            'http://qbk-test/contribute/document' => Http::response([
                'success' => true,
                'data' => [
                    'session_id' => 77,
                    'status' => 'processing',
                    'documento_nombre' => 'prueba.txt',
                    'chunks_recibidos' => 2,
                ],
            ], 200),
        ]);

        Livewire::withQueryParams([])
            ->test(UploadDocument::class)
            ->set('documento', $this->txtFile())
            ->set('contexto', 'Un documento de prueba')
            ->call('submit')
            ->assertSet('status', 'procesando')
            ->assertSet('error', null);

        $upload = DocumentUpload::where('user_id', $this->user->uuid)->first();
        $this->assertNotNull($upload, 'La carga debe registrarse en document_uploads (B.4)');
        $this->assertSame(DocumentUpload::ESTADO_PROCESANDO, $upload->estado);
        $this->assertSame(77, $upload->qbk_session_id);
        $this->assertSame('prueba.txt', $upload->nombre);

        // FB-4: el payload respeta el contrato propuesto §3.1 (pagina_origen
        // puede ser null en TXT — se valida la clave, no el valor).
        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->url() === 'http://qbk-test/contribute/document'
                && $body['documento_nombre'] === 'prueba.txt'
                && $body['origen'] === 'kuestion'
                && array_key_exists('chunk_id', $body['chunks'][0])
                && array_key_exists('pagina_origen', $body['chunks'][0])
                && array_key_exists('orden', $body['chunks'][0])
                && $body['contexto_opcional'] === 'Un documento de prueba';
        });
    }

    /** FB-1 (parte lógica): formato no soportado → error visible temprano. */
    public function test_formato_no_soportado_error_claro(): void
    {
        Livewire::test(UploadDocument::class)
            ->set('documento', UploadedFile::fake()->createWithContent('imagen.png', 'binario'))
            ->assertSet('error', 'Formato no soportado. Usá TXT, MD, PDF o DOCX.')
            ->assertSet('documento', null);
    }

    /** B.2: archivo muy grande → error visible temprano. */
    public function test_archivo_muy_grande_error_claro(): void
    {
        config(['kuestion.documentos.max_bytes' => 1024]);

        $component = Livewire::test(UploadDocument::class)
            ->set('documento', $this->txtFile(str_repeat('x', 3000)));

        $component->assertSet('documento', null)
            ->assertSet('status', 'idle');

        $this->assertStringContainsString('MB', (string) $component->get('error'));
    }

    /** FB-8: duplicado por hash → aviso antes de procesar; continuar o cancelar. */
    public function test_duplicado_avisa_y_permite_continuar(): void
    {
        DocumentUpload::create([
            'user_id' => $this->user->uuid,
            'repository_id' => $this->repo->id,
            'nombre' => 'prueba.txt',
            'hash' => hash('sha256', 'Contenido del documento de prueba.'),
            'estado' => DocumentUpload::ESTADO_LISTO,
        ]);

        Http::fake([
            'http://qbk-test/contribute/document' => Http::response([
                'success' => true,
                'data' => ['session_id' => 78, 'status' => 'processing', 'chunks_recibidos' => 1],
            ], 200),
        ]);

        $component = Livewire::test(UploadDocument::class)
            ->set('documento', $this->txtFile())
            ->call('submit');

        $component->assertSet('status', 'idle');
        $this->assertNotNull($component->get('duplicadoUploadId'), 'Debe avisar duplicado antes de procesar');

        // Continuar a pesar del aviso.
        $component->call('confirmarDuplicado')->assertSet('status', 'procesando');

        // Y cancelar vuelve al formulario limpio.
        $component2 = Livewire::test(UploadDocument::class)
            ->set('documento', $this->txtFile())
            ->call('submit');
        $component2->call('cancelarDuplicado')
            ->assertSet('status', 'idle')
            ->assertSet('duplicadoUploadId', null);
    }

    /** FB-4: polling con progreso → al terminar, listo con N nodos y [Revisar]. */
    public function test_polling_progreso_y_resultado_final(): void
    {
        $upload = DocumentUpload::create([
            'user_id' => $this->user->uuid,
            'repository_id' => $this->repo->id,
            'nombre' => 'doc.txt',
            'hash' => 'h-'.uniqid(),
            'estado' => DocumentUpload::ESTADO_PROCESANDO,
            'qbk_session_id' => 90,
            'chunks_totales' => 4,
        ]);

        // Un solo fake: el 1er poll responde en progreso, el 2º en finalizado
        // (Http::fake encadenado ACUMULA stubs — el primero gana; verificado en vendor).
        $polls = 0;
        Http::fake(function () use (&$polls) {
            $polls++;

            if ($polls === 1) {
                return Http::response([
                    'success' => true,
                    'data' => [
                        'session_id' => 90,
                        'status' => 'procesando',
                        'chunks_procesados' => 2,
                        'chunks_totales' => 4,
                        'nodos' => [],
                    ],
                ], 200);
            }

            return Http::response([
                'success' => true,
                'data' => [
                    'session_id' => 90,
                    'status' => 'lista_para_revision',
                    'nodos' => [['id' => 'n1'], ['id' => 'n2'], ['id' => 'n3']],
                ],
            ], 200);
        });

        Livewire::test(UploadDocument::class)
            ->set('uploadId', $upload->id)
            ->set('status', 'procesando')
            ->call('pollProgreso')
            ->assertSet('status', 'procesando');

        $this->assertSame(2, $upload->fresh()->chunks_procesados, 'FB-4: el progreso se refleja en document_uploads');

        Livewire::test(UploadDocument::class)
            ->set('uploadId', $upload->id)
            ->set('status', 'procesando')
            ->call('pollProgreso')
            ->assertSet('status', 'listo')
            ->assertSet('nodosPropuestos', 3);

        $this->assertSame(DocumentUpload::ESTADO_LISTO, $upload->fresh()->estado);
    }

    /** FB-9: QBK caído → error visible y carga retenida para reintentar. */
    public function test_qbk_caido_error_visible_y_carga_retenida(): void
    {
        Http::fake(fn ($request) => throw new ConnectionException('conn refused'));

        Livewire::test(UploadDocument::class)
            ->set('documento', $this->txtFile())
            ->call('submit')
            ->assertSet('status', 'error');

        $upload = DocumentUpload::where('user_id', $this->user->uuid)->first();
        $this->assertNotNull($upload);
        $this->assertSame(DocumentUpload::ESTADO_ERROR, $upload->estado, 'FB-9: la carga se retiene para reintentar');
        $this->assertStringContainsString('QuBeKa', $upload->error ?? '');
    }

    /** B.6: error de red → reintentos automáticos (3) antes de fallar. */
    public function test_reintentos_ante_error_de_red(): void
    {
        $llamadas = 0;
        Http::fake(function ($request) use (&$llamadas) {
            $llamadas++;

            throw new ConnectionException('timeout');
        });

        Livewire::test(UploadDocument::class)
            ->set('documento', $this->txtFile())
            ->call('submit')
            ->assertSet('status', 'error');

        $this->assertSame(3, $llamadas, 'B.6: 3 intentos con backoff antes de fallar');
    }

    /** B.7: sesión fallida en QuBeKa → error visible con la carga en error. */
    public function test_sesion_error_en_qbk_visible(): void
    {
        $upload = DocumentUpload::create([
            'user_id' => $this->user->uuid,
            'repository_id' => $this->repo->id,
            'nombre' => 'doc.txt',
            'hash' => 'h-'.uniqid(),
            'estado' => DocumentUpload::ESTADO_PROCESANDO,
            'qbk_session_id' => 91,
        ]);

        Http::fake([
            'http://qbk-test/sesiones-analisis/91' => Http::response([
                'success' => true,
                'data' => ['session_id' => 91, 'status' => 'error', 'nodos' => []],
            ], 200),
        ]);

        Livewire::test(UploadDocument::class)
            ->set('uploadId', $upload->id)
            ->set('status', 'procesando')
            ->call('pollProgreso')
            ->assertSet('status', 'error');

        $this->assertSame(DocumentUpload::ESTADO_ERROR, $upload->fresh()->estado);
        $this->assertNotEmpty($upload->fresh()->error);
    }

    /** B.5: retomar una carga en procesando de una sesión anterior. */
    public function test_retoma_carga_pendiente_de_sesion_anterior(): void
    {
        $upload = DocumentUpload::create([
            'user_id' => $this->user->uuid,
            'repository_id' => $this->repo->id,
            'nombre' => 'viejo.txt',
            'hash' => 'h-viejo',
            'estado' => DocumentUpload::ESTADO_PROCESANDO,
            'qbk_session_id' => 55,
        ]);

        Livewire::test(UploadDocument::class)
            ->assertSet('status', 'procesando')
            ->assertSet('uploadId', $upload->id);
    }
}
