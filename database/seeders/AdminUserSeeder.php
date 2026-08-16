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

        // Idempotente por EMAIL (único real de negocio): el uuid puede venir de APP_USER_ID
        // (entornos con variable) o generarse; updateOrCreate por uuid duplicaría el admin
        // si la variable cambia entre corridas. Si el usuario ya existe se preserva su uuid.
        $existing = User::where('email', 'admin@kuestion.app')->first();

        $user = User::updateOrCreate(
            ['email' => 'admin@kuestion.app'],
            [
                'name' => 'Admin',
                'password' => 'password',
                'uuid' => $existing?->uuid ?? $uuid,
                // Acceso al panorama de equipo: el admin puede ver /team desde el menú.
                'team_dashboard_access' => 'readonly',
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
