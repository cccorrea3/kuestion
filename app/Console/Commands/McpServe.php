<?php

namespace App\Console\Commands;

use App\Models\AgentToken;
use App\Services\Mcp\McpServer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * MCP Server propio de Kuestion (9.3 — Bloque 9): transporte stdio estándar de MCP.
 *
 * Lee JSON-RPC 2.0 newline-delimited de stdin y escribe las respuestas en stdout.
 * La autenticación usa el token de agente (kqt_...) validado contra `agente_tokens`
 * con Hash::check; el scoping por user_id lo aplica McpServer.
 *
 * bcrypt no permite buscar por hash: se itera sobre los tokens vigentes (no
 * expirados), suficiente para una tabla de pocos agentes. El Hash::check se paga
 * una vez por sesión; en cada mensaje se re-valida existencia y expiración.
 */
class McpServe extends Command
{
    protected $signature = 'mcp:serve {--token= : Token de agente (kqt_...) o env KUESTION_AGENT_TOKEN}';

    protected $description = 'Sirve el protocolo MCP por stdio (Claude Code y otros clientes)';

    public function handle(): int
    {
        $plainToken = $this->option('token') ?: getenv('KUESTION_AGENT_TOKEN');

        if (! $plainToken) {
            $this->error('Token requerido: pasa --token= o define KUESTION_AGENT_TOKEN.');

            return self::FAILURE;
        }

        $sessionToken = null;

        while (($line = fgets(STDIN)) !== false) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $message = json_decode($line, true);

            if (! is_array($message)) {
                $this->writeJson(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error']]);

                continue;
            }

            // Autenticación: Hash::check una sola vez por sesión; vigencia por mensaje.
            $sessionToken ??= $this->resolveAgentToken($plainToken);
            $current = $sessionToken ? AgentToken::find($sessionToken->id) : null;

            if (! $current || $current->isExpired()) {
                $this->writeJson($this->authError($message['id'] ?? null));

                continue;
            }

            $current->update(['last_used_at' => now()]);

            $response = (new McpServer($current))->handleMessage($message);

            if ($response !== null) {
                $this->writeJson($response);
            }
        }

        return self::SUCCESS;
    }

    private function resolveAgentToken(string $plainToken): ?AgentToken
    {
        $candidates = AgentToken::query()
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->get();

        foreach ($candidates as $token) {
            if (Hash::check($plainToken, $token->token_hash)) {
                return $token;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function writeJson(array $data): void
    {
        fwrite(STDOUT, json_encode($data).PHP_EOL);
    }

    /**
     * @return array<string, mixed>
     */
    private function authError(int|string|null $id): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => ['code' => -32001, 'message' => 'Token de agente inválido o expirado'],
        ];
    }
}
