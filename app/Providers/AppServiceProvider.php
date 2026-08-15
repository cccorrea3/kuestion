<?php

namespace App\Providers;

use App\Contracts\IdentityResolverInterface;
use App\Contracts\RagProviderInterface;
use App\Contracts\StructuredSignalProviderInterface;
use App\Services\ConnectorRegistry;
use App\Services\KuaforiaService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // B5 — Sistema de Conectores RAG: los bindings dejan de ser hardcodeados y
        // leen del registro de conectores (config/kuestion.connectors.php). Con un
        // solo conector registrado, cada interfaz resuelve la clase de Kuaforia.
        $this->app->singleton(ConnectorRegistry::class);

        foreach ([
            IdentityResolverInterface::class,
            RagProviderInterface::class,
            StructuredSignalProviderInterface::class,
        ] as $interface) {
            $this->app->singleton($interface, fn ($app) => $app->make(
                $app->make(ConnectorRegistry::class)->classFor($interface)
            ));
        }

        // La clase concreta se mantiene como singleton: Register/Settings y los tests
        // del job la piden por clase (resolveTenantFromApiKey queda fuera de la interfaz).
        $this->app->singleton(KuaforiaService::class);
    }

    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(100)->by($request->ip());
        });
    }
}
