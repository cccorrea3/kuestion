<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class RepositoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            // user_id apunta a users.uuid (FK por uuid, no por id numérico).
            'user_id' => fn () => User::factory()->create()->uuid,
            'connector_type' => 'kuaforia',
            'name' => 'Kuaforia - Ispend',
            'credential' => ['api_key' => 'kfr_test_'.fake()->regexify('[a-z0-9]{24}')],
            'resolved_tenant_slug' => 'ispend',
            'resolved_tenant_name' => 'Ispend',
            'status' => 'active',
            'is_default' => true,
        ];
    }
}
