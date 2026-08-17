<?php

namespace Tests\Unit;

use App\Exceptions\KuaforiaException;
use App\Services\KuaforiaService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KuaforiaServiceTest extends TestCase
{
    public function test_consult_builds_url_with_tenant_slug(): void
    {
        Http::fake(function ($request) {
            $this->assertStringContainsString('/api/consult/test-tenant', $request->url());

            return Http::response(['answer' => 'ok', 'confidence' => 90, 'sources' => []]);
        });

        $response = app(KuaforiaService::class)->consult('test?', tenantSlug: 'test-tenant');

        $this->assertEquals('ok', $response->answerText);
        $this->assertEquals(90, $response->confidence);
    }

    public function test_consult_throws_without_tenant(): void
    {
        $this->expectException(KuaforiaException::class);
        $this->expectExceptionMessage('No se pudo resolver el tenant');

        app(KuaforiaService::class)->consult('test?');
    }

    public function test_resolve_tenant_from_api_key_delegates_to_identity_resolver(): void
    {
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
                                // G7 — el wrapper de compatibilidad también pasa el workspace.
                                'default_workspace' => ['id' => 'ws-ispend', 'name' => 'WS', 'slug' => 'ispend'],
                            ],
                        ]),
                    ]],
                    'isError' => false,
                ],
            ]),
        ]);

        $resolved = app(KuaforiaService::class)->resolveTenantFromApiKey('kfr_test_abc');

        $this->assertSame('ispend', $resolved['tenant_slug']);
        $this->assertSame('ws-ispend', $resolved['workspace_id']);
    }

    public function test_resolve_tenant_from_api_key_converts_401_to_kuaforia_exception(): void
    {
        Http::fake([
            '*/api/v1/mcp' => Http::response(['success' => false, 'error' => 'Invalid or expired API key'], 401),
        ]);

        $this->expectException(KuaforiaException::class);
        $this->expectExceptionMessage('inválida o fue revocada');

        app(KuaforiaService::class)->resolveTenantFromApiKey('kfr_invalida');
    }
}
