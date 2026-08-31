<?php

namespace Tests\Feature;

use App\Exceptions\KuaforiaException;
use App\Services\QbkIdentityResolver;
use App\Services\ResolvedIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class QbkIdentityResolverTest extends TestCase
{
    use RefreshDatabase;

    private QbkIdentityResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new QbkIdentityResolver;

        config(['services.qubeka.api_url' => 'http://mock-qubeka.test/api/v1']);
    }

    public function test_resolve_identity_returns_workspace_data(): void
    {
        Http::fake([
            '*' => Http::response([
                'workspace_id' => 42,
                'workspace_nombre' => 'Investigación Jurídica',
                'user_id' => 1,
                'user_nombre' => 'Juan Pérez',
                'agente_nombre' => 'Kuestion Connector',
                'scopes' => ['api:read'],
            ]),
        ]);

        $identity = $this->resolver->resolveIdentity(['api_token' => 'qbk_test_token']);

        $this->assertInstanceOf(ResolvedIdentity::class, $identity);
        $this->assertSame('42', $identity->tenantSlug);
        $this->assertSame('Investigación Jurídica', $identity->tenantName);
        $this->assertSame('42', $identity->workspaceId);
        $this->assertArrayHasKey('workspace_id', $identity->raw);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/agent/me')
                && $request->method() === 'GET'
                && $request->header('Authorization')[0] === 'Bearer qbk_test_token';
        });
    }

    public function test_resolve_identity_throws_on_401(): void
    {
        Http::fake([
            '*' => Http::response(['error' => 'Unauthorized'], 401),
        ]);

        $this->expectException(KuaforiaException::class);
        $this->expectExceptionCode(401);

        $this->resolver->resolveIdentity(['api_token' => 'invalid_token']);
    }

    public function test_resolve_identity_throws_on_404(): void
    {
        Http::fake([
            '*' => Http::response(['error' => 'Not found'], 404),
        ]);

        $this->expectException(KuaforiaException::class);
        $this->expectExceptionCode(404);
        $this->expectExceptionMessage('workspace');

        $this->resolver->resolveIdentity(['api_token' => 'valid_token']);
    }

    public function test_resolve_identity_throws_on_500(): void
    {
        Http::fake([
            '*' => Http::response(['error' => 'Internal error'], 500),
        ]);

        $this->expectException(KuaforiaException::class);
        $this->expectExceptionMessage('No se pudo conectar');

        $this->resolver->resolveIdentity(['api_token' => 'valid_token']);
    }

    public function test_resolve_identity_throws_when_token_missing(): void
    {
        $this->expectException(KuaforiaException::class);
        $this->expectExceptionMessage('sin token de agente');

        $this->resolver->resolveIdentity([]);
    }

    public function test_resolve_identity_throws_when_token_empty(): void
    {
        $this->expectException(KuaforiaException::class);
        $this->expectExceptionMessage('sin token de agente');

        $this->resolver->resolveIdentity(['api_token' => '']);
    }

    public function test_resolve_identity_throws_when_workspace_id_missing(): void
    {
        Http::fake([
            '*' => Http::response([
                'workspace_nombre' => 'Test',
                'user_id' => 1,
            ]),
        ]);

        $this->expectException(KuaforiaException::class);
        $this->expectExceptionMessage('sin workspace_id');

        $this->resolver->resolveIdentity(['api_token' => 'valid_token']);
    }

    public function test_resolve_identity_maps_workspace_id_as_tenant_slug(): void
    {
        Http::fake([
            '*' => Http::response([
                'workspace_id' => 99,
                'workspace_nombre' => 'Mi Workspace',
                'user_id' => 1,
                'user_nombre' => 'Test',
                'scopes' => ['api:read'],
            ]),
        ]);

        $identity = $this->resolver->resolveIdentity(['api_token' => 'token']);

        // En QuBeKa, el workspace_id ES el tenantSlug (no hay tenant superior).
        $this->assertSame('99', $identity->tenantSlug);
        $this->assertSame('Mi Workspace', $identity->tenantName);
        $this->assertSame('99', $identity->workspaceId);
    }
}
