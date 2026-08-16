<?php

namespace Database\Seeders;

use App\Models\Repository;
use App\Models\User;
use App\Services\ConnectorRegistry;
use Illuminate\Database\Seeder;

/**
 * Usuario de prueba para el entorno de desarrollo (setup-dev.sh):
 * test@ispend.com / password123, con su repositorio default de Kuaforia.
 * Idempotente por email (misma convención que AdminUserSeeder).
 */
class DevUsersSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::updateOrCreate(
            ['email' => 'test@ispend.com'],
            [
                'name' => 'Usuario Test',
                'password' => 'password123',
                'team_dashboard_access' => 'readonly',
            ]
        );

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
