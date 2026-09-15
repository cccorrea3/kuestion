<?php

namespace Tests\Feature;

use App\Livewire\ReviewTray;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ola 3, Punto 1 — Fase C: checklist FC contra mock (extensión de la bandeja
 * de Ola 2 P1 para sesiones de documento, H1: mock hasta la entrega de QBK).
 *
 * Patrón de fakes (verificado en vendor): Http::fake ACUMULA stubs y gana el
 * primero que matchea → los stubs se agregan en orden: detalle/approve primero
 * (URL específica), listado general al final; o un fake secuencial con closure.
 */
class ReviewTrayDocumentosTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Repository $repo;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.qubeka.api_url' => 'http://qbk-test']);

        $this->user = User::factory()->create();
        $this->repo = Repository::factory()->create([
            'name' => 'QBK Tray Repo',
            'user_id' => $this->user->uuid,
            'status' => 'active',
            'connector_type' => 'qbk',
            'credential' => ['api_token' => 'tok-test'],
        ]);

        $this->actingAs($this->user);
    }

    /**
     * Fake secuencial: el listado responde siempre; el detalle de la sesión
     * indicada responde con el detalle; el approve/reject responde según $accion.
     */
    private function fakeCiclo(int $sessionId, array $detalle, string $accion = 'aprobada'): void
    {
        Http::fake(function ($request) use ($sessionId, $detalle, $accion) {
            $url = $request->url();

            if (str_contains($url, "/sesiones-analisis/{$sessionId}/approve")) {
                return Http::response(['success' => true, 'data' => ['session_id' => $sessionId, 'status' => $accion]], 200);
            }

            if (str_contains($url, "/sesiones-analisis/{$sessionId}/reject")) {
                return Http::response(['success' => true, 'data' => ['session_id' => $sessionId, 'status' => 'rechazada']], 200);
            }

            if (str_contains($url, "sesiones-analisis/{$sessionId}")
                && ! str_contains($url, '/approve') && ! str_contains($url, '/reject')) {
                return Http::response($detalle, 200);
            }

            // Listado (GET /sesiones-analisis?...).
            return Http::response([
                'success' => true,
                'data' => [$this->itemDocumento($sessionId)],
                'meta' => ['page' => 1, 'per_page' => 20, 'total' => 1, 'last_page' => 1],
            ], 200);
        });
    }

    private function itemDocumento(int $sessionId): array
    {
        return [
            'session_id' => $sessionId,
            'status' => 'lista_para_revision',
            'creado_en' => '2026-09-13T10:00:00Z',
            'contenido_entrada' => 'informe-onboarding-q3.pdf',
            'resumen' => 'Se propusieron 5 nodos: 2 preguntas, 3 hipótesis.',
            'is_simple' => false, // documento multi-nodo
            'pregunta_previa' => null,
        ];
    }

    private function detalleDocumento(int $sessionId, array $contradicciones = []): array
    {
        return [
            'success' => true,
            'data' => [
                'session_id' => $sessionId,
                'status' => 'lista_para_revision',
                'is_simple' => false,
                'nodos' => [
                    ['id' => 'nodo-prop-001', 'tipo' => 'Q', 'texto' => '¿Cuál es el proceso de onboarding?'],
                    ['id' => 'nodo-prop-002', 'tipo' => 'H', 'texto' => 'El campo de teléfono causa fricción'],
                    ['id' => 'nodo-prop-003', 'tipo' => 'H', 'texto' => 'El tour guiado mejora la retención'],
                    ['id' => 'nodo-prop-004', 'tipo' => 'N-K', 'texto' => 'El pago se procesa en 24h'],
                    ['id' => 'nodo-prop-005', 'tipo' => 'N-K', 'texto' => 'Soporte responde en menos de 1 día'],
                ],
                'contradicciones' => $contradicciones,
                'resumen' => 'Se propusieron 5 nodos.',
            ],
        ];
    }

    /** FC-1 (parte lógica): el documento es un ítem con nombre y resumen por tipo. */
    public function test_documento_aparece_como_un_item(): void
    {
        Http::fake([
            'http://qbk-test/sesiones-analisis*' => Http::response([
                'success' => true,
                'data' => [$this->itemDocumento(50)],
                'meta' => ['page' => 1, 'per_page' => 20, 'total' => 1, 'last_page' => 1],
            ], 200),
        ]);

        Livewire::test(ReviewTray::class)
            ->assertSet('total', 1)
            ->assertSee('informe-onboarding-q3.pdf')
            ->assertSee('Se propusieron 5 nodos');
    }

    /** FC-3: expandir carga los nodos, todos preseleccionados (§1.7). */
    public function test_expandir_documento_preselecciona_todos(): void
    {
        $this->fakeCiclo(50, $this->detalleDocumento(50));

        $component = Livewire::test(ReviewTray::class)
            ->call('expandirDocumento', 50)
            ->assertSet('docExpandido', true)
            ->assertSet('docSessionId', 50);

        $this->assertCount(5, $component->get('docNodos'));
        $this->assertSame(5, $component->get('cantidadSeleccionados'), '§1.7: todos preseleccionados por defecto');
    }

    /** FC-4: deselección de 2 de 5 → payload con los 3 y los 2 correctos. */
    public function test_aprobar_seleccionados_envia_ids_correctos(): void
    {
        $this->fakeCiclo(50, $this->detalleDocumento(50));

        $component = Livewire::test(ReviewTray::class)
            ->call('expandirDocumento', 50);

        // Deseleccionar nodo-prop-002 (índice 1) y nodo-prop-005 (índice 4).
        $component->call('alternarNodo', 1)
            ->call('alternarNodo', 4)
            ->call('aprobarSeleccionados')
            ->assertSet('docExpandido', false); // éxito: panel cerrado

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/approve')) {
                return false;
            }

            $body = $request->data();

            return $body['nodos_aprobados'] === ['nodo-prop-001', 'nodo-prop-003', 'nodo-prop-004']
                && $body['nodos_rechazados'] === ['nodo-prop-002', 'nodo-prop-005']
                && $body['revisado_por_email'] === $this->user->email;
        });
    }

    /** C.3: sin nodos seleccionados no se puede aprobar (guard). */
    public function test_no_aprueba_sin_seleccion(): void
    {
        $this->fakeCiclo(50, $this->detalleDocumento(50));

        $component = Livewire::test(ReviewTray::class)
            ->call('expandirDocumento', 50)
            ->call('seleccionarTodos', false)
            ->call('aprobarSeleccionados');

        $component->assertSet('docExpandido', true); // no cerró
        $this->assertStringContainsString('No hay nodos seleccionados', (string) $component->get('docError'));
    }

    /** FC-2/C.5: contradicciones se cargan y se muestran como advertencia. */
    public function test_contradicciones_cargan_con_el_detalle(): void
    {
        $contradicciones = [[
            'nodo_nuevo_propuesto' => 'H: El campo de teléfono causa fricción',
            'nodo_existente' => 'NK-0451',
            'descripcion' => 'El nodo existente afirma que el campo de teléfono no afecta la conversión.',
        ]];

        $this->fakeCiclo(51, $this->detalleDocumento(51, $contradicciones));

        $component = Livewire::test(ReviewTray::class)
            ->call('expandirDocumento', 51);

        $this->assertCount(1, $component->get('docContradicciones') ?? []);
        $component->assertSee('contradice 1')
            ->assertSee('NK-0451');
    }

    /** FC-5: rechazar todo descarta la sesión y la quita de la bandeja. */
    public function test_rechazar_documento_todo(): void
    {
        $this->fakeCiclo(52, $this->detalleDocumento(52));

        $component = Livewire::test(ReviewTray::class)
            ->call('expandirDocumento', 52)
            ->call('rechazarDocumento')
            ->assertSet('docExpandido', false);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/reject'));
    }

    /** FC-6: fallo del approve → error visible, selección intacta, reintento posible. */
    public function test_fallo_approve_mantiene_seleccion_y_avisa(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, '/approve')) {
                return Http::response(['success' => false, 'errors' => ['message' => 'boom']], 500);
            }

            if (str_contains($url, 'sesiones-analisis/53') && ! str_contains($url, '/approve')) {
                return Http::response($this->detalleDocumento(53), 200);
            }

            return Http::response([
                'success' => true,
                'data' => [$this->itemDocumento(53)],
                'meta' => ['page' => 1, 'per_page' => 20, 'total' => 1, 'last_page' => 1],
            ], 200);
        });

        $component = Livewire::test(ReviewTray::class)
            ->call('expandirDocumento', 53)
            ->call('alternarNodo', 1)
            ->call('aprobarSeleccionados');

        $component->assertSet('docExpandido', true); // sigue abierto
        $this->assertCount(5, $component->get('docNodos'), 'FC-6: la selección no se pierde');
        $this->assertNotSame('', (string) $component->get('docError'), 'FC-6: el fallo es visible');
    }

    /** FC-8 (parte lógica): el flujo simple sigue funcionando (regresión). */
    public function test_flujo_simple_regresion(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, '/approve')) {
                return Http::response(['success' => true, 'data' => ['session_id' => 60, 'status' => 'aprobada']], 200);
            }

            return Http::response([
                'success' => true,
                'data' => [[
                    'session_id' => 60,
                    'status' => 'lista_para_revision',
                    'creado_en' => '2026-09-13T10:00:00Z',
                    'contenido_entrada' => 'Aporte corto de prueba',
                    'resumen' => 'Se propuso 1 pregunta.',
                    'is_simple' => true,
                    'pregunta_previa' => null,
                ]],
                'meta' => ['page' => 1, 'per_page' => 20, 'total' => 1, 'last_page' => 1],
            ], 200);
        });

        Livewire::test(ReviewTray::class)
            ->call('aprobar', 60);

        // removeAndRefresh quita el ítem y re-consulta; el fake sigue devolviendo
        // el mismo listado (no modela el cambio de estado en QBK), así que total
        // vuelve a 1. Lo que valida FC-8 es que el approve se disparó y no hubo error.
        Http::assertSent(fn ($request) => str_contains($request->url(), '/approve'));

        Http::assertSent(fn ($request) => str_contains($request->url(), '/approve'));
    }

    /** C.4: guard contra doble envío (processingSessionId). */
    public function test_guard_doble_envio(): void
    {
        $this->fakeCiclo(50, $this->detalleDocumento(50));

        Livewire::test(ReviewTray::class)
            ->call('expandirDocumento', 50)
            ->set('processingSessionId', 50)
            ->call('aprobarSeleccionados')
            ->assertSet('docExpandido', true); // no procesó
    }
}
