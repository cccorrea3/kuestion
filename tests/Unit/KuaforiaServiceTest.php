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
}
