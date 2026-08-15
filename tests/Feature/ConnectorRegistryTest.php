<?php

namespace Tests\Feature;

use App\Contracts\RagProviderInterface;
use App\Contracts\StructuredSignalProviderInterface;
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

        // identity_resolver (clase dedicada de la Fase B) aún no existe: se verifica
        // que la clave esté declarada como FQCN string; el instanceof se valida en B5.
        $this->assertIsString($kuaforia['identity_resolver']);
    }
}
