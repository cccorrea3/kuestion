<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Ola 3, Punto 3 — A.2: cliente de preguntas sugeridas de QuBeKa.
 *
 * GET {QUBKA_API_URL}/suggestions?limit=5
 * Authorization: Bearer {credential['api_token']}
 * Response: {"success": true, "data": {"suggestions": [{"texto", "fuente", "nodo_origen_id"}]}}
 *
 * Robustez (spec §4): ningún fallo propaga excepción a la UI — la ausencia
 * de sugerencias es un estado válido, no un error para el usuario.
 */
class QbkSuggestionService
{
    /**
     * @return array{ok: bool, sugerencias: array<int, array{texto: string, fuente: ?string, nodo_origen_id: ?string}>}
     *
     * ok=false → fallo de transporte/HTTP (timeout, 4xx/5xx, JSON o sobre
     * inválido): el componente no lo cachea y la próxima carga reintenta.
     * ok=true con [] → QBK respondió vacío (workspace sin grafo suficiente).
     */
    public function sugerencias(?array $credential): array
    {
        $vacio = ['ok' => false, 'sugerencias' => []];

        $apiToken = $credential['api_token'] ?? null;

        if (! is_string($apiToken) || $apiToken === '') {
            return $vacio;
        }

        $url = rtrim(config('services.qubeka.api_url'), '/').'/suggestions';

        try {
            $response = Http::timeout(5)
                ->withToken($apiToken)
                ->get($url, ['limit' => 5]);
        } catch (\Throwable $e) {
            Log::warning('QuBeKa /suggestions no respondió', ['error' => $e->getMessage()]);

            return $vacio;
        }

        if ($response->failed()) {
            Log::warning('QuBeKa /suggestions falló', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return $vacio;
        }

        $body = $response->json();

        // Sobre real de la API (P1): {"success": true, "data": {...}}.
        $data = is_array($body) ? ($body['data'] ?? null) : null;
        $raw = is_array($data) ? ($data['suggestions'] ?? null) : null;

        if (! is_array($raw)) {
            Log::warning('QuBeKa /suggestions: sobre inesperado', ['body' => $body]);

            return $vacio;
        }

        // Mapeo tolerante (P2/D2): nodo_origen_id nullable nunca filtra una
        // sugerencia; fuente desconocida se conserva tal cual (sin clasificar).
        $mapeadas = array_values(array_filter(array_map(function ($item) {
            if (! is_array($item) || ! is_string($item['texto'] ?? null) || trim($item['texto']) === '') {
                return null;
            }

            return [
                'texto' => trim($item['texto']),
                'fuente' => $item['fuente'] ?? null,
                'nodo_origen_id' => $item['nodo_origen_id'] ?? null,
            ];
        }, $raw), fn ($s) => is_array($s)));

        return ['ok' => true, 'sugerencias' => $mapeadas];
    }

    /**
     * A.3 — Catálogo genérico local (D1): la primera impresión de un usuario
     * nuevo (sin grafo, sin documentos, sin preguntas). Universales y fijas
     * en v1 — ver plan A.3; el copy se valida con producto en el gate C.6.
     */
    public function genericas(): array
    {
        return array_values(array_map(
            fn (string $texto) => ['texto' => $texto, 'fuente' => null, 'nodo_origen_id' => null],
            config('kuestion.sugerencias_genericas', []),
        ));
    }
}
