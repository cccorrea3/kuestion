<?php

namespace App\Services;

use App\Contracts\IdentityResolverInterface;
use App\Exceptions\KuaforiaException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Resolución de identidad para QuBeKa (Ola 1, Punto 1 — Fase 4).
 *
 * Contrato confirmado:
 *   GET {QUBKA_API_URL}/agent/me
 *   Authorization: Bearer {credential['api_token']}
 *
 *   Response: {"workspace_id": 123, "workspace_nombre": "...", ...}
 *   Errors: 401 (token inválida), 404 (workspace eliminado)
 *
 * En QuBeKa no hay "tenant" u "organización" superior — el workspace ES la
 * unidad más alta. Mapeo a ResolvedIdentity:
 *   - tenantSlug   = workspace_id (string) — usado por QuestionChecker para routing
 *   - tenantName   = workspace_nombre
 *   - workspaceId  = workspace_id (string)
 */
class QbkIdentityResolver implements IdentityResolverInterface
{
    public function resolveIdentity(array $credential): ResolvedIdentity
    {
        $apiToken = $credential['api_token'] ?? null;

        if (! is_string($apiToken) || $apiToken === '') {
            throw new KuaforiaException('Credencial de QuBeKa sin token de agente.');
        }

        $url = rtrim(config('services.qubeka.api_url'), '/').'/agent/me';

        $response = Http::timeout(30)
            ->withToken($apiToken)
            ->get($url);

        if ($response->failed()) {
            if ($response->status() === 401) {
                Log::warning('QuBeKa identity: token rechazado', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                throw new KuaforiaException('El token de QuBeKa es inválido o fue revocado.', 401);
            }

            if ($response->status() === 404) {
                throw new KuaforiaException('El workspace de QuBeKa fue eliminado.', 404);
            }

            Log::warning('QuBeKa identity: /agent/me falló', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new KuaforiaException('No se pudo conectar con QuBeKa. Intenta de nuevo en unos minutos.');
        }

        $body = $response->json() ?? [];

        $workspaceId = $body['workspace_id'] ?? null;
        $workspaceNombre = $body['workspace_nombre'] ?? null;

        if ($workspaceId === null) {
            throw new KuaforiaException('QuBeKa: /agent/me sin workspace_id.');
        }

        return new ResolvedIdentity(
            tenantSlug: (string) $workspaceId,
            tenantName: isset($workspaceNombre) ? (string) $workspaceNombre : null,
            workspaceId: (string) $workspaceId,
            raw: $body,
        );
    }
}
