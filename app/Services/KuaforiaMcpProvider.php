<?php

namespace App\Services;

use App\Contracts\StructuredSignalProviderInterface;
use App\Exceptions\KuaforiaMcpException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente JSON-RPC 2.0 del puente MCP de Kuaforia (2.3 — Bloque 8).
 *
 * Misma convención HTTP que KuaforiaService: Http::timeout(...)->withToken(...)->post(...).
 * Timeout corto (15 s): el enriquecimiento del job es best-effort y nunca debe
 * alargar ni bloquear la re-consulta periódica.
 *
 * El nombre de tool se resuelve desde config `services.kuaforia.mcp_tools`
 * (mapeo tool → método de la interfaz): un cambio de catálogo de Kuaforia se
 * ajusta en config, sin refactor.
 */
class KuaforiaMcpProvider implements StructuredSignalProviderInterface
{
    public function getWorkspaceHealth(string $workspaceId, ?array $credential = null): array
    {
        return $this->callTool(__FUNCTION__, ['workspace_id' => $workspaceId], $credential);
    }

    public function getDependencyHealthReport(string $workspaceId, ?array $credential = null): array
    {
        return $this->callTool(__FUNCTION__, ['workspace_id' => $workspaceId], $credential);
    }

    public function getCaseDetails(string $caseId, ?array $credential = null): array
    {
        return $this->callTool(__FUNCTION__, ['case_id' => $caseId], $credential);
    }

    /**
     * tools/call JSON-RPC 2.0.
     *
     * $credential: credencial del repositorio (E1 — Sistema de Conectores). Null →
     * fallback a la config global (`mcp_api_key` o `api_key`), para no romper usos
     * previos ni tests existentes.
     *
     * Los nombres de argumentos (workspace_id / case_id) siguen el catálogo actual
     * de Kuaforia; si el contrato real difiere, se ajusta acá (pendiente #2).
     */
    private function callTool(string $method, array $arguments, ?array $credential = null): array
    {
        $response = Http::timeout(15)
            ->withToken($this->apiKey($credential))
            ->post($this->mcpUrl(), [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => [
                    'name' => $this->toolNameFor($method),
                    'arguments' => $arguments,
                ],
            ]);

        if ($response->failed()) {
            Log::warning('Kuaforia MCP: tools/call falló', [
                'tool' => $this->toolNameFor($method),
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new KuaforiaMcpException('Kuaforia MCP respondió con error: '.$response->status());
        }

        $body = $response->json() ?? [];

        if (isset($body['error'])) {
            // Error de protocolo JSON-RPC (p.ej. method not found, -32601).
            throw new KuaforiaMcpException(
                'Kuaforia MCP devolvió error: '.($body['error']['message'] ?? 'desconocido')
            );
        }

        return $this->normalize($body);
    }

    /**
     * Normaliza result.content[].text (JSON string si aplica) al array documentado.
     */
    private function normalize(array $body): array
    {
        $result = $body['result'] ?? [];

        // isError=true en el resultado: la tool ejecutó pero falló.
        if (($result['isError'] ?? false) === true) {
            $message = $result['content'][0]['text'] ?? 'la tool devolvió isError';

            throw new KuaforiaMcpException('Kuaforia MCP: '.$message);
        }

        $text = '';
        foreach (($result['content'] ?? []) as $item) {
            if (isset($item['text']) && is_string($item['text'])) {
                $text .= ($text === '' ? '' : ' ').$item['text'];
            }
        }

        if ($text === '') {
            Log::warning('Kuaforia MCP: respuesta sin contenido de texto', ['body' => $body]);

            return [];
        }

        $decoded = json_decode($text, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        // Texto plano: se devuelve como array con la clave 'text'.
        return ['text' => $text];
    }

    /**
     * Resuelve el nombre de tool desde config (tool → método). Si el catálogo
     * cambia, se ajusta config sin tocar código.
     */
    private function toolNameFor(string $method): string
    {
        $tool = array_search($method, config('services.kuaforia.mcp_tools', []), true);

        if ($tool === false) {
            throw new KuaforiaMcpException("Sin tool MCP configurada para el método {$method}.");
        }

        return (string) $tool;
    }

    private function mcpUrl(): string
    {
        return rtrim((string) config('services.kuaforia.mcp_url'), '/');
    }

    private function apiKey(?array $credential = null): string
    {
        // E1 — la credencial del repositorio (si viene) tiene prioridad sobre la config global.
        if (is_array($credential) && isset($credential['api_key']) && is_string($credential['api_key']) && $credential['api_key'] !== '') {
            return $credential['api_key'];
        }

        $key = config('services.kuaforia.mcp_api_key') ?? config('services.kuaforia.api_key');

        if (! $key) {
            throw new KuaforiaMcpException('Kuaforia MCP sin API key configurada.');
        }

        return $key;
    }
}
