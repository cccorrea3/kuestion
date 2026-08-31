<?php

namespace App\Services;

use App\Contracts\IdentityResolverInterface;
use App\Contracts\RagProviderInterface;
use App\Contracts\StructuredSignalProviderInterface;
use RuntimeException;

/**
 * Registro de conectores RAG (Sistema de Conectores RAG — Fase B).
 *
 * Lee config/kuestion.connectors.php: dado un connector_type devuelve la ficha
 * (display_name, auth_fields, clases) y resuelve qué clase implementa cada
 * interfaz. Es la única pieza de "infraestructura" nueva del sistema de conectores;
 * el resto es mover el origen de los datos (decisión cerrada §9 — diseñar para N,
 * construir para 1: hoy solo Kuaforia está registrado).
 */
class ConnectorRegistry
{
    /**
     * Ficha completa de un conector registrado.
     *
     * @return array{display_name: string, auth_fields: array<int, array<string, string>>, identity_resolver: class-string, rag_provider: class-string, signal_provider: class-string}
     */
    public function connector(string $type): array
    {
        $connector = config("kuestion.connectors.{$type}");

        if (! is_array($connector)) {
            throw new RuntimeException("Conector no registrado: {$type}.");
        }

        return $connector;
    }

    /**
     * Primera clase registrada que implementa la interfaz dada (default: Kuaforia).
     * Se usa como fallback para bindings singleton en AppServiceProvider.
     *
     * @param  class-string  $interface
     * @return class-string
     */
    public function classFor(string $interface): string
    {
        $candidates = [
            IdentityResolverInterface::class => 'identity_resolver',
            RagProviderInterface::class => 'rag_provider',
            StructuredSignalProviderInterface::class => 'signal_provider',
        ];

        $key = $candidates[$interface] ?? null;

        if ($key === null) {
            throw new RuntimeException("Interfaz no mapeada a un conector: {$interface}.");
        }

        foreach (config('kuestion.connectors', []) as $connector) {
            $class = is_array($connector) ? ($connector[$key] ?? null) : null;

            if (is_string($class) && is_subclass_of($class, $interface)) {
                return $class;
            }
        }

        throw new RuntimeException("Ningún conector registrado implementa {$interface}.");
    }

    /**
     * Resuelve una instancia del RagProviderInterface para un connector_type dado.
     * Las instancias se cachean por clase (singleton por conector).
     */
    public function ragProviderFor(string $connectorType): RagProviderInterface
    {
        $class = $this->connector($connectorType)['rag_provider'];

        return app($class);
    }

    /**
     * Resuelve una instancia del IdentityResolverInterface para un connector_type dado.
     */
    public function identityResolverFor(string $connectorType): IdentityResolverInterface
    {
        $class = $this->connector($connectorType)['identity_resolver'];

        return app($class);
    }

    /**
     * Resuelve una instancia del StructuredSignalProviderInterface para un connector_type.
     * Devuelve null si el conector no tiene signal_provider registrado.
     */
    public function signalProviderFor(string $connectorType): ?StructuredSignalProviderInterface
    {
        $class = $this->connector($connectorType)['signal_provider'] ?? null;

        if (! is_string($class)) {
            return null;
        }

        return app($class);
    }
}
