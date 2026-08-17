<?php

namespace App\Services;

use App\Contracts\IdentityResolverInterface;
use App\Exceptions\KuaforiaMcpException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Resolución de identidad de un repositorio vía el puente MCP de Kuaforia
 * (Sistema de Conectores RAG — Fase B).
 *
 * Decisión A1: la identidad es 100% vía MCP con la tool `get_client_context`
 * (la vía REST `/api/validate-api-key` quedó descartada y eliminada — G4).
 *
 * Contrato P3 (confirmado por Ingeniería de Kuaforia):
 * - Endpoint: POST /api/v1/mcp (landlord, sin subdominio), JSON-RPC tools/call,
 *   tool name `get_client_context`.
 * - Respuesta exitosa: result.content[0].text es un STRING JSON (doble decode).
 * - Errores: HTTP 401 con JSON PLANO (rompe el sobre JSON-RPC):
 *   {"success":false,"error":"Invalid or expired API key"}.
 * - No existe el caso "key sin tenant" (constraint NOT NULL del lado de Kuaforia).
 * - Forma de la respuesta (hallazgo de pruebas E2E): la implementación REAL de
 *   Kuaforia devuelve `tenant`, `default_workspace`, etc. al NIVEL RAÍZ del string
 *   JSON (sin wrapper `data`): {"success": true, "tenant": {"slug", "name"},
 *   "default_workspace": {id, name, slug}, ...}. El contrato P3 original (y el mock
 *   local) los documentan bajo `data` (data.tenant, data.default_workspace).
 *   El parseo acepta AMBAS formas (ver normalizeData()).
 * - workspace_id: G7 — Kuaforia extendió el contrato: default_workspace {id, name, slug}.
 *   Se usa default_workspace.id (fallback defensivo workspace_id por si alguna
 *   versión intermedia del contrato lo trajera en esa posición).
 */
class IdentityResolver implements IdentityResolverInterface
{
    public function resolveIdentity(array $credential): ResolvedIdentity
    {
        $apiKey = $credential['api_key'] ?? null;

        if (! is_string($apiKey) || $apiKey === '') {
            throw new KuaforiaMcpException('Credencial sin API key para resolver la identidad.');
        }

        $response = Http::timeout(30)
            ->withToken($apiKey)
            ->post($this->mcpUrl(), [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'get_client_context',
                    'arguments' => [],
                ],
            ]);

        if ($response->failed()) {
            // P3: el 401 rompe el sobre JSON-RPC — viene JSON plano, no error JSON-RPC.
            if ($response->status() === 401) {
                Log::warning('Kuaforia MCP: get_client_context rechazó la API key', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                throw new KuaforiaMcpException('La API key de Kuaforia es inválida o fue revocada.', 401);
            }

            Log::warning('Kuaforia MCP: get_client_context falló', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new KuaforiaMcpException('No se pudo conectar con Kuaforia. Intenta de nuevo en unos minutos.');
        }

        $body = $response->json() ?? [];

        if (isset($body['error'])) {
            // Error de protocolo JSON-RPC (p.ej. method not found, -32601).
            throw new KuaforiaMcpException(
                'Kuaforia MCP devolvió error: '.($body['error']['message'] ?? 'desconocido')
            );
        }

        $text = '';
        foreach (($body['result']['content'] ?? []) as $item) {
            if (isset($item['text']) && is_string($item['text'])) {
                $text .= ($text === '' ? '' : ' ').$item['text'];
            }
        }

        if ($text === '') {
            throw new KuaforiaMcpException('Kuaforia MCP: get_client_context sin contenido.');
        }

        // P3: content[0].text es un STRING JSON (doble decode del sobre JSON-RPC).
        $decoded = json_decode($text, true);

        if (! is_array($decoded)) {
            throw new KuaforiaMcpException('Kuaforia MCP: respuesta de get_client_context inválida.');
        }

        if (($decoded['success'] ?? false) !== true) {
            throw new KuaforiaMcpException(
                'Kuaforia MCP: '.($decoded['error'] ?? 'get_client_context sin éxito.')
            );
        }

        // Normalización de forma: el contrato P3 (y el mock local) envuelven los
        // campos bajo `data`; la implementación real de Kuaforia los devuelve al
        // nivel raíz del string JSON (verificado en pruebas E2E). Se aceptan ambas.
        $data = $decoded['data'] ?? $decoded;
        $tenant = $data['tenant'] ?? [];

        if (! isset($tenant['slug']) || $tenant['slug'] === '') {
            throw new KuaforiaMcpException('No se pudo resolver la organización para esta API key.');
        }

        // G7 — default_workspace.id es la fuente primaria del workspace por defecto.
        $workspace = $data['default_workspace'] ?? [];
        $workspaceId = $workspace['id'] ?? $data['workspace_id'] ?? null;

        return new ResolvedIdentity(
            tenantSlug: (string) $tenant['slug'],
            tenantName: isset($tenant['name']) ? (string) $tenant['name'] : null,
            workspaceId: ($workspaceId !== null && $workspaceId !== '') ? (string) $workspaceId : null,
            raw: $data,
        );
    }

    private function mcpUrl(): string
    {
        return rtrim((string) config('services.kuaforia.mcp_url'), '/');
    }
}
