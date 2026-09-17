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
 * Ola 3, Punto 1.1 — Fase C (C.6): los cuatro caminos de "Aprobar seleccionados"
 * tras la evaluación de consecuencias (contrato v1.8 §2.6).
 *
 * 1. Sin consecuencias: promoción directa, sin paso intermedio (regresión #4).
 * 2. Con consecuencias + confirmación: approve con el payload EXACTO de la selección.
 * 3. Con consecuencias + cancelación: la selección queda intacta (regresión #5).
 * 4. Fallo de la evaluación: según P2 (no bloquear; aprobar igual / reintentar).
 *
 * Nota NB6 (limitación conocida): la evaluación solo cubre relaciones intra-chunk;
 * aquí eso es irrelevante porque QuBeKa está mockeado.
 */
class ReviewTrayAdvertenciaTest extends TestCase
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

    /** Consecuencia real (formato v1.8 §2.6, validado con curl en Fase A). */
    private function consecuencia(): array
    {
        return [
            'tipo' => 'nodo_huerfano',
            'nodos_afectados' => [
                ['id' => 'nodo-prop-002', 'texto' => 'El campo de teléfono causa fricción'],
                ['id' => 'nodo-prop-001', 'texto' => '¿Cuál es el proceso de onboarding?'],
            ],
            'descripcion' => '«El campo de teléfono causa fricción» (H) se aprobará sin su nodo padre «¿Cuál es el proceso de onboarding?» y quedará como raíz del grafo.',
        ];
    }

    /**
     * Fake secuencial de todo el ciclo: evaluar-subconjunto responde según
     * $consecuencias (0 = lista vacía); approve responde $accion; el resto
     * (detalle GET y listado) responde el ciclo normal de la bandeja.
     */
    private function fakeCiclo(
        int $sessionId,
        int $totalConsecuencias,
        string $accion = 'aprobada',
        bool $evaluacionFalla = false,
        int $codigoEvaluacion = 500,
    ): void {
        Http::fake(function ($request) use ($sessionId, $totalConsecuencias, $accion, $evaluacionFalla, $codigoEvaluacion) {
            $url = $request->url();

            if (str_contains($url, "/sesiones-analisis/{$sessionId}/evaluar-subconjunto")) {
                if ($evaluacionFalla) {
                    return Http::response(['success' => false], $codigoEvaluacion);
                }

                $consecuencias = [];

                for ($i = 0; $i < $totalConsecuencias; $i++) {
                    $consecuencias[] = $this->consecuencia();
                }

                return Http::response([
                    'success' => true,
                    'data' => [
                        'consecuencias' => $consecuencias,
                        'total' => $totalConsecuencias,
                        'subconjunto_evaluado' => count($request['nodos_aprobados'] ?? []),
                    ],
                ], 200);
            }

            if (str_contains($url, "/sesiones-analisis/{$sessionId}/approve")) {
                return Http::response(['success' => true, 'data' => ['session_id' => $sessionId, 'status' => $accion]], 200);
            }

            if (str_contains($url, "sesiones-analisis/{$sessionId}")
                && ! str_contains($url, '/approve') && ! str_contains($url, '/evaluar-subconjunto')) {
                return Http::response($this->detalleDocumento($sessionId), 200);
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
            'resumen' => 'Se propusieron 3 nodos.',
            'is_simple' => false,
            'pregunta_previa' => null,
        ];
    }

    private function detalleDocumento(int $sessionId): array
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
                    ['id' => 'nodo-prop-003', 'tipo' => 'N-K', 'texto' => 'El pago se procesa en 24h'],
                ],
                'contradicciones' => [],
                'resumen' => 'Se propusieron 3 nodos.',
            ],
        ];
    }

    private function abrirDocumento(int $sessionId)
    {
        return Livewire::test(ReviewTray::class)
            ->call('expandirDocumento', $sessionId);
    }

    /**
     * H7 (post-review): expandir otro documento limpia la advertencia/fallo de
     * evaluación pendiente — sin esto, el panel de A queda colgado sobre B y
     * confirmar aprobaría B con los ids congelados de A (422/404 engañoso).
     */
    public function test_expandir_otro_documento_limpia_la_advertencia_pendiente(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, '/evaluar-subconjunto')) {
                return Http::response([
                    'success' => true,
                    'data' => ['consecuencias' => [$this->consecuencia()], 'total' => 1, 'subconjunto_evaluado' => 3],
                ], 200);
            }

            if (str_contains($url, '/approve')) {
                return Http::response(['success' => true, 'data' => ['session_id' => 100, 'status' => 'aprobada']], 200);
            }

            if (str_contains($url, '/sesiones-analisis/')) {
                preg_match('/\/sesiones-analisis\/(\d+)/', $url, $m);

                return Http::response($this->detalleDocumento((int) $m[1]), 200);
            }

            return Http::response([
                'success' => true,
                'data' => [$this->itemDocumento(100)],
                'meta' => ['page' => 1, 'per_page' => 20, 'total' => 1, 'last_page' => 1],
            ], 200);
        });

        // Sesión 100 con advertencia pendiente (escenario del review).
        $component = $this->abrirDocumento(100)
            ->call('aprobarSeleccionados')
            ->assertSet('advertenciaPendiente', true)
            ->assertSet('docSessionId', 100);

        // Expandir la 200: la advertencia de la 100 debe desaparecer.
        $component->call('expandirDocumento', 200);
        $component
            ->assertSet('docExpandido', true)
            ->assertSet('advertenciaPendiente', false)
            ->assertSet('evaluacionFallida', false)
            ->assertSet('advertenciaAprobados', [])
            ->assertSet('advertenciaRechazados', []);

        // Nadie aprobó nada: ni la 100 ni la 200.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/approve'));
    }

    /** Camino 1 — sin consecuencias: promoción directa, sin paso intermedio. */
    public function test_sin_consecuencias_aprueba_directo_sin_advertencia(): void
    {
        $this->fakeCiclo(70, 0);

        $component = $this->abrirDocumento(70)
            ->call('aprobarSeleccionados');

        $component
            ->assertSet('advertenciaPendiente', false)
            ->assertSet('evaluacionFallida', false)
            ->assertSet('docExpandido', false); // éxito: panel cerrado

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/approve')
                && $request['nodos_aprobados'] === ['nodo-prop-001', 'nodo-prop-002', 'nodo-prop-003'];
        });
    }

    /** Camino 2 — con consecuencias + confirmación: approve con el subconjunto evaluado. */
    public function test_con_consecuencias_muestra_advertencia_y_confirma(): void
    {
        $this->fakeCiclo(71, 1);

        $component = $this->abrirDocumento(71)
            ->call('aprobarSeleccionados');

        // Se evaluó, la promoción NO se ejecutó todavía.
        $component->assertSet('advertenciaPendiente', true)
            ->assertSet('docExpandido', true);

        $this->assertCount(1, $component->get('advertenciaConsecuencias'));
        $this->assertSame(['nodo-prop-001', 'nodo-prop-002', 'nodo-prop-003'], $component->get('advertenciaAprobados'));

        Http::assertSent(fn ($request) => ! str_contains($request->url(), '/approve'));

        // Confirmar: approve con EXACTAMENTE la lista evaluada (C.2).
        $component->call('confirmarAprobacionConAdvertencia')
            ->assertSet('advertenciaPendiente', false)
            ->assertSet('docExpandido', false);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/approve')
                && $request['nodos_aprobados'] === ['nodo-prop-001', 'nodo-prop-002', 'nodo-prop-003'];
        });
    }

    /** Camino 3 — cancelación: la selección queda intacta (regresión #5). */
    public function test_volver_a_seleccion_conserva_checkboxes(): void
    {
        $this->fakeCiclo(72, 2);

        $component = $this->abrirDocumento(72);

        // Deseleccionar el nodo-prop-002 (índice 1) para luego verificar que sigue así.
        $component->call('alternarNodo', 1)
            ->call('aprobarSeleccionados')
            ->assertSet('advertenciaPendiente', true)
            ->call('volverASeleccion')
            ->assertSet('advertenciaPendiente', false)
            ->assertSet('advertenciaConsecuencias', [])
            ->assertSet('docExpandido', true);

        $nodos = $component->get('docNodos');

        $this->assertFalse($nodos[1]['seleccionado'], 'La deselección se conserva');
        $this->assertTrue($nodos[0]['seleccionado']);
        $this->assertSame(2, $component->get('cantidadSeleccionados'));
    }

    /** Camino 4 — fallo de la evaluación (P2): no bloquea, ofrece aprobar igual / reintentar. */
    public function test_fallo_evaluacion_no_bloquea_y_permite_aprobar_igual(): void
    {
        $this->fakeCiclo(73, 0, 'aprobada', true, 504);

        $component = $this->abrirDocumento(73)
            ->call('aprobarSeleccionados');

        $component->assertSet('evaluacionFallida', true)
            ->assertSet('advertenciaPendiente', false)
            ->assertSet('docExpandido', true);

        // "Aprobar igual" (C.2, mismo confirmar) con el subconjunto guardado.
        $component->call('confirmarAprobacionConAdvertencia')
            ->assertSet('evaluacionFallida', false)
            ->assertSet('docExpandido', false);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/approve'));
    }

    /** Camino 4b — reintento de la evaluación tras fallo (P2). */
    public function test_reintentar_evaluacion(): void
    {
        // Un solo fake secuencial (Http::fake acumula; el primero matchea):
        // la 1ª evaluación falla, la 2ª responde sin consecuencias.
        $llamadasEvaluacion = 0;

        Http::fake(function ($request) use (&$llamadasEvaluacion) {
            $url = $request->url();

            if (str_contains($url, '/evaluar-subconjunto')) {
                $llamadasEvaluacion++;

                if ($llamadasEvaluacion === 1) {
                    return Http::response(['success' => false], 503);
                }

                return Http::response([
                    'success' => true,
                    'data' => ['consecuencias' => [], 'total' => 0, 'subconjunto_evaluado' => 3],
                ], 200);
            }

            if (str_contains($url, '/approve')) {
                return Http::response(['success' => true, 'data' => ['session_id' => 74, 'status' => 'aprobada']], 200);
            }

            if (str_contains($url, 'sesiones-analisis/74')) {
                return Http::response($this->detalleDocumento(74), 200);
            }

            return Http::response([
                'success' => true,
                'data' => [$this->itemDocumento(74)],
                'meta' => ['page' => 1, 'per_page' => 20, 'total' => 1, 'last_page' => 1],
            ], 200);
        });

        $component = $this->abrirDocumento(74)
            ->call('aprobarSeleccionados')
            ->assertSet('evaluacionFallida', true);

        $component->call('reintentarEvaluacion')
            ->assertSet('evaluacionFallida', false)
            ->assertSet('docExpandido', false);

        $this->assertSame(2, $llamadasEvaluacion, 'La evaluación se llamó dos veces (fallo + reintento)');
        Http::assertSent(fn ($request) => str_contains($request->url(), '/approve'));
    }

    /** 422 de validación (ids ajenos): mensaje legible de QuBeKa, no estado de fallo P2. */
    public function test_error_422_muestra_mensaje_legible(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, '/evaluar-subconjunto')) {
                return Http::response([
                    'success' => false,
                    'errors' => ['message' => 'nodos_aprobados contiene nodos que no pertenecen a la sesión: xyz'],
                ], 422);
            }

            if (str_contains($url, 'sesiones-analisis/75') && ! str_contains($url, '/evaluar-subconjunto')) {
                return Http::response($this->detalleDocumento(75), 200);
            }

            return Http::response([
                'success' => true,
                'data' => [$this->itemDocumento(75)],
                'meta' => ['page' => 1, 'per_page' => 20, 'total' => 1, 'last_page' => 1],
            ], 200);
        });

        $component = $this->abrirDocumento(75)
            ->call('aprobarSeleccionados');

        $component->assertSet('evaluacionFallida', false)
            ->assertSet('advertenciaPendiente', false)
            ->assertSet('docExpandido', true);

        $this->assertStringContainsString('no pertenecen a la sesión', (string) $component->get('docError'));
    }

    /** C.5 — "aprobar todo" (ítem simple) y el resto de la bandeja: sin cambios. */
    public function test_aprobar_simple_sin_evaluacion(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, '/approve')) {
                return Http::response(['success' => true, 'data' => ['session_id' => 76, 'status' => 'aprobada']], 200);
            }

            return Http::response([
                'success' => true,
                'data' => [[
                    'session_id' => 76,
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
            ->call('aprobar', 76);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/approve'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/evaluar-subconjunto'));
    }

    /** Cancelar el documento limpia también el estado de advertencia. */
    public function test_colapsar_documento_limpia_advertencia(): void
    {
        $this->fakeCiclo(77, 1);

        $component = $this->abrirDocumento(77)
            ->call('aprobarSeleccionados')
            ->assertSet('advertenciaPendiente', true)
            ->call('colapsarDocumento')
            ->assertSet('advertenciaPendiente', false)
            ->assertSet('advertenciaConsecuencias', []);
    }

    /** D.5 — render: advertencia legible con la descripcion de QuBeKa y botones operativos. */
    public function test_render_advertencia_con_copy_y_botones(): void
    {
        $this->fakeCiclo(78, 1);

        $component = $this->abrirDocumento(78)
            ->call('aprobarSeleccionados')
            ->assertSet('advertenciaPendiente', true);

        $component
            ->assertSee('Revisa las consecuencias de esta selección')
            ->assertSee('Se promovería como raíz del grafo') // encabezado por tipo (D.2)
            ->assertSee('se aprobará sin su nodo padre')    // descripcion de QuBeKa tal cual
            ->assertSee('El campo de teléfono causa fricción') // nodo citado por texto
            ->assertSee('Confirmar aprobación')
            ->assertSee('Volver a la selección');
    }

    /** D.5 — render del aviso de fallo de evaluación (P2), distinto del de consecuencias. */
    public function test_render_aviso_fallo_evaluacion(): void
    {
        $this->fakeCiclo(79, 0, 'aprobada', true, 503);

        $component = $this->abrirDocumento(79)
            ->call('aprobarSeleccionados')
            ->assertSet('evaluacionFallida', true);

        $component
            ->assertSee('No pudimos verificar las consecuencias de esta selección')
            ->assertSee('Aprobar de todas formas')
            ->assertSee('Reintentar verificación')
            ->assertDontSee('Revisa las consecuencias de esta selección');
    }

    /** D.4 — flujo feliz: sin advertencia ni rastro del panel en la vista. */
    public function test_render_flujo_feliz_sin_panel(): void
    {
        $this->fakeCiclo(80, 0);

        $component = $this->abrirDocumento(80)
            ->call('aprobarSeleccionados')
            ->assertSet('advertenciaPendiente', false)
            ->assertSet('evaluacionFallida', false)
            ->assertSet('docExpandido', false);

        $component
            ->assertDontSee('Revisa las consecuencias de esta selección')
            ->assertDontSee('No pudimos verificar las consecuencias');
    }

    /** D.2 — encabezados legibles por cada tipo (unidad de vista vía método estático). */
    public function test_encabezados_por_tipo(): void
    {
        $this->assertSame('Se promovería como raíz del grafo', ReviewTray::encabezadoConsecuencia('nodo_huerfano'));
        $this->assertSame('El enlace no se recreará', ReviewTray::encabezadoConsecuencia('enlace_perdido'));
        $this->assertSame('Quedarían como nodos separados', ReviewTray::encabezadoConsecuencia('sugerencia_no_resuelta'));
        $this->assertSame('Consecuencia para el grafo', ReviewTray::encabezadoConsecuencia('desconocido'));
    }
}
