<?php

namespace Tests\Feature;

use App\Exceptions\KuaforiaMcpException;
use App\Services\KuaforiaMcpProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KuaforiaMcpProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.kuaforia.mcp_api_key' => 'test-key',
            'services.kuaforia.mcp_url' => 'http://localhost:8080/api/v1/mcp',
        ]);
    }

    public function test_provider_sends_jsonrpc_tools_call_with_bearer_token(): void
    {
        Http::fake(function ($request) {
            $body = $request->data();

            $this->assertSame('http://localhost:8080/api/v1/mcp', $request->url());
            $this->assertSame('Bearer test-key', $request->header('Authorization')[0] ?? null);
            $this->assertSame('2.0', $body['jsonrpc']);
            $this->assertSame('tools/call', $body['method']);
            $this->assertSame('get_workspace_health', $body['params']['name']);
            $this->assertSame(['workspace_id' => 'ws-1'], $body['params']['arguments']);

            return Http::response([
                'jsonrpc' => '2.0',
                'id' => 1,
                'result' => [
                    'content' => [['type' => 'text', 'text' => '{"status":"healthy"}']],
                    'isError' => false,
                ],
            ]);
        });

        $result = app(KuaforiaMcpProvider::class)->getWorkspaceHealth('ws-1');

        $this->assertSame(['status' => 'healthy'], $result);
    }

    public function test_provider_maps_all_three_tools_from_config(): void
    {
        $toolNames = [];

        Http::fake(function ($request) use (&$toolNames) {
            $toolNames[] = $request->data()['params']['name'];

            return Http::response([
                'jsonrpc' => '2.0',
                'id' => 1,
                'result' => ['content' => [['type' => 'text', 'text' => '{}']], 'isError' => false],
            ]);
        });

        $provider = app(KuaforiaMcpProvider::class);
        $provider->getWorkspaceHealth('ws-1');
        $provider->getDependencyHealthReport('ws-1');
        $provider->getCaseDetails('case-1');

        $this->assertSame([
            'get_workspace_health',
            'get_dependency_health_report',
            'get_case',
        ], $toolNames);
    }

    public function test_provider_normalizes_plain_text_content(): void
    {
        Http::fake([
            '*/api/v1/mcp' => Http::response([
                'jsonrpc' => '2.0',
                'id' => 1,
                'result' => ['content' => [['type' => 'text', 'text' => 'salud ok']], 'isError' => false],
            ]),
        ]);

        $result = app(KuaforiaMcpProvider::class)->getWorkspaceHealth('ws-1');

        $this->assertSame(['text' => 'salud ok'], $result);
    }

    public function test_provider_throws_on_http_error(): void
    {
        Http::fake([
            '*/api/v1/mcp' => Http::response([], 500),
        ]);

        $this->expectException(KuaforiaMcpException::class);

        app(KuaforiaMcpProvider::class)->getWorkspaceHealth('ws-1');
    }

    public function test_provider_throws_on_jsonrpc_protocol_error(): void
    {
        Http::fake([
            '*/api/v1/mcp' => Http::response([
                'jsonrpc' => '2.0',
                'id' => 1,
                'error' => ['code' => -32601, 'message' => 'Method not found'],
            ]),
        ]);

        $this->expectException(KuaforiaMcpException::class);

        app(KuaforiaMcpProvider::class)->getWorkspaceHealth('ws-1');
    }

    public function test_provider_throws_when_tool_reports_is_error(): void
    {
        Http::fake([
            '*/api/v1/mcp' => Http::response([
                'jsonrpc' => '2.0',
                'id' => 1,
                'result' => [
                    'content' => [['type' => 'text', 'text' => 'workspace not found']],
                    'isError' => true,
                ],
            ]),
        ]);

        $this->expectException(KuaforiaMcpException::class);
        $this->expectExceptionMessage('workspace not found');

        app(KuaforiaMcpProvider::class)->getWorkspaceHealth('ws-1');
    }

    public function test_tool_name_resolves_from_config_change(): void
    {
        // Un cambio de catálogo (nombre de tool distinto) se resuelve solo con config.
        config([
            'services.kuaforia.mcp_tools' => [
                'get_workspace_health_v2' => 'getWorkspaceHealth',
                'get_dependency_health_report' => 'getDependencyHealthReport',
                'get_case' => 'getCaseDetails',
            ],
        ]);

        $name = null;

        Http::fake(function ($request) use (&$name) {
            $name = $request->data()['params']['name'];

            return Http::response([
                'jsonrpc' => '2.0',
                'id' => 1,
                'result' => ['content' => [['type' => 'text', 'text' => '{}']], 'isError' => false],
            ]);
        });

        app(KuaforiaMcpProvider::class)->getWorkspaceHealth('ws-1');

        $this->assertSame('get_workspace_health_v2', $name);
    }
}
