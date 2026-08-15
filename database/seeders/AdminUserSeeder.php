<?php

namespace Database\Seeders;

use App\Models\Repository;
use App\Models\User;
use App\Services\ConnectorRegistry;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $uuid = env('APP_USER_ID') ?? (string) Str::uuid();

        $user = User::updateOrCreate(
            ['uuid' => $uuid],
            [
                'name' => 'Admin',
                'email' => 'admin@kuestion.app',
                'password' => 'password',
            ]
        );

        // G2 (B5) — el admin queda listo para usar la app: repositorio default con su
        // key de Kuaforia (si está configurada). Idempotente: no duplica al re-seedear.
        $user->repositories()->firstOrCreate(
            ['connector_type' => 'kuaforia'],
            [
                'name' => Repository::defaultName(
                    app(ConnectorRegistry::class)->connector('kuaforia')['display_name'],
                    'Ispend',
                ),
                'credential' => ['api_key' => env('KUAFORIA_API_KEY', '')],
                'resolved_tenant_slug' => 'ispend',
                'resolved_tenant_name' => 'Ispend',
                'status' => 'active',
                'is_default' => true,
            ]
        );
    }
}
