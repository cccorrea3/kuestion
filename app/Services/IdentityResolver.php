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
 * (la vía REST `/api/validate-api-key` quedó descartada; se elimina en Fase G).
 *
 * Contrato P3 (confirmado por Ingeniería de Kuaforia):
 * - Endpoint: POST /api/v1/mcp (landlord, sin subdominio), JSON-RPC tools/call,
 *   tool name `get_client_context`.
 * - Respuesta exitosa: result.content[0].text es un STRING JSON con
 *   {"success": true, "data": {"tenant": {"slug", "name"}, ...}} → usar data.tenant.*.
 * - Errores: HTTP 401 con JSON PLANO (rompe el sobre JSON-RPC):
 *   {"success":false,"error":"Invalid or expired API key"}.
 * - No existe el caso "key sin tenant" (constraint NOT NULL del lado de Kuaforia).
 * - workspace_id: hoy NO viene en la respuesta (P2) → ResolvedIdentity->workspaceId = null.
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

        $data = $decoded['data'] ?? [];
        $tenant = $data['tenant'] ?? [];

        if (! isset($tenant['slug']) || $tenant['slug'] === '') {
            throw new KuaforiaMcpException('No se pudo resolver la organización para esta API key.');
        }

        return new ResolvedIdentity(
            tenantSlug: (string) $tenant['slug'],
            tenantName: isset($tenant['name']) ? (string) $tenant['name'] : null,
            // P2: workspace_id no viene hoy en la respuesta → null (fallback workspace_map).
            workspaceId: isset($data['workspace_id']) ? (string) $data['workspace_id'] : null,
            raw: $data,
        );
    }

    private function mcpUrl(): string
    {
        return rtrim((string) config('services.kuaforia.mcp_url'), '/');
    }
}
