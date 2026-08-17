<?php

namespace Tests\Feature;

use App\Exceptions\KuaforiaMcpException;
use App\Services\IdentityResolver;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IdentityResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.kuaforia.mcp_url' => 'http://localhost:8080/api/v1/mcp',
        ]);
    }

    public function test_resolve_identity_sends_jsonrpc_tools_call_with_credential_key(): void
    {
        Http::fake(function ($request) {
            $body = $request->data();

            $this->assertSame('http://localhost:8080/api/v1/mcp', $request->url());
            $this->assertSame('Bearer kfr_test_abc', $request->header('Authorization')[0] ?? null);
            $this->assertSame('2.0', $body['jsonrpc']);
            $this->assertSame('tools/call', $body['method']);
            $this->assertSame('get_client_context', $body['params']['name']);

            return Http::response([
                'jsonrpc' => '2.0',
                'id' => 1,
                'result' => [
                    'content' => [[
                        'type' => 'text',
                        'text' => json_encode([
                            'success' => true,
                            'data' => [
                                'tenant' => ['slug' => 'ispend', 'name' => 'Ispend'],
                                'scopes' => ['questions:read'],
                                // G7 — contrato extendido: default_workspace {id, name, slug}.
                                'default_workspace' => [
                                    'id' => 'ws-ispend',
                                    'name' => 'Workspace Ispend',
                                    'slug' => 'ispend',
                                ],
                            ],
                        ]),
                    ]],
                    'isError' => false,
                ],
            ]);
        });

        $identity = app(IdentityResolver::class)->resolveIdentity(['api_key' => 'kfr_test_abc']);

        $this->assertSame('ispend', $identity->tenantSlug);
        $this->assertSame('Ispend', $identity->tenantName);
        // G7 — el workspace por defecto sale de data.default_workspace.id.
        $this->assertSame('ws-ispend', $identity->workspaceId);
    }

    public function test_resolve_identity_workspace_is_null_without_default_workspace(): void
    {
        // Backward compat: un Kuaforia anterior a la extensión (o un tenant sin
        // workspace) no trae default_workspace → workspaceId null (no rompe nada).
        Http::fake([
            '*/api/v1/mcp' => Http::response([
                'jsonrpc' => '2.0',
                'id' => 1,
                'result' => [
                    'content' => [[
                        'type' => 'text',
                        'text' => json_encode([
                            'success' => true,
                            'data' => [
                                'tenant' => ['slug' => 'ispend', 'name' => 'Ispend'],
                            ],
                        ]),
                    ]],
                    'isError' => false,
                ],
            ]),
        ]);

        $identity = app(IdentityResolver::class)->resolveIdentity(['api_key' => 'kfr_test_abc']);

        $this->assertSame('ispend', $identity->tenantSlug);
        $this->assertNull($identity->workspaceId);
    }

    public function test_resolve_identity_handles_flat_401_json(): void
    {
        Http::fake([
            '*/api/v1/mcp' => Http::response(['success' => false, 'error' => 'Invalid or expired API key'], 401),
        ]);

        try {
            app(IdentityResolver::class)->resolveIdentity(['api_key' => 'kfr_invalida']);
            $this->fail('Debería lanzar KuaforiaMcpException con 401.');
        } catch (KuaforiaMcpException $e) {
            $this->assertSame(401, $e->getCode());
            $this->assertStringContainsString('inválida o fue revocada', $e->getMessage());
        }
    }

    public function test_resolve_identity_throws_without_tenant_in_response(): void
    {
        Http::fake([
            '*/api/v1/mcp' => Http::response([
                'jsonrpc' => '2.0',
                'id' => 1,
                'result' => [
                    'content' => [['type' => 'text', 'text' => json_encode(['success' => true, 'data' => []])]],
                    'isError' => false,
                ],
            ]),
        ]);

        $this->expectException(KuaforiaMcpException::class);
        $this->expectExceptionMessage('No se pudo resolver la organización');

        app(IdentityResolver::class)->resolveIdentity(['api_key' => 'kfr_test']);
    }

    public function test_resolve_identity_throws_on_jsonrpc_protocol_error(): void
    {
        Http::fake([
            '*/api/v1/mcp' => Http::response([
                'jsonrpc' => '2.0',
                'id' => 1,
                'error' => ['code' => -32601, 'message' => 'Method not found'],
            ]),
        ]);

        $this->expectException(KuaforiaMcpException::class);
        $this->expectExceptionMessage('Method not found');

        app(IdentityResolver::class)->resolveIdentity(['api_key' => 'kfr_test']);
    }

    public function test_resolve_identity_requires_api_key_in_credential(): void
    {
        $this->expectException(KuaforiaMcpException::class);
        $this->expectExceptionMessage('sin API key');

        app(IdentityResolver::class)->resolveIdentity([]);
    }
}
