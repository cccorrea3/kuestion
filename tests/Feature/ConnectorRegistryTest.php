<?php

namespace Tests\Feature;

use App\Contracts\IdentityResolverInterface;
use App\Contracts\RagProviderInterface;
use App\Contracts\StructuredSignalProviderInterface;
use App\Services\ConnectorRegistry;
use App\Services\IdentityResolver;
use App\Services\KuaforiaMcpProvider;
use App\Services\KuaforiaService;
use Tests\TestCase;

class ConnectorRegistryTest extends TestCase
{
    public function test_kuaforia_registration_has_expected_ficha(): void
    {
        $kuaforia = config('kuestion.connectors.kuaforia');

        $this->assertSame('Kuaforia', $kuaforia['display_name']);
        $this->assertSame('api_key', $kuaforia['auth_fields'][0]['key']);
        $this->assertSame('API key', $kuaforia['auth_fields'][0]['label']);
    }

    public function test_registered_providers_implement_their_interfaces(): void
    {
        $kuaforia = config('kuestion.connectors.kuaforia');

        $this->assertTrue(is_subclass_of($kuaforia['rag_provider'], RagProviderInterface::class));
        $this->assertTrue(is_subclass_of($kuaforia['signal_provider'], StructuredSignalProviderInterface::class));

        $this->assertTrue(is_subclass_of($kuaforia['identity_resolver'], IdentityResolverInterface::class));
    }

    public function test_container_resolves_interfaces_from_registry(): void
    {
        $this->assertInstanceOf(IdentityResolver::class, app(IdentityResolverInterface::class));
        $this->assertInstanceOf(KuaforiaService::class, app(RagProviderInterface::class));
        $this->assertInstanceOf(KuaforiaMcpProvider::class, app(StructuredSignalProviderInterface::class));
    }

    public function test_registry_returns_ficha_and_resolves_classes(): void
    {
        $registry = app(ConnectorRegistry::class);

        $this->assertSame('Kuaforia', $registry->connector('kuaforia')['display_name']);
        $this->assertSame(IdentityResolver::class, $registry->classFor(IdentityResolverInterface::class));
        $this->assertSame(KuaforiaService::class, $registry->classFor(RagProviderInterface::class));
    }
}
