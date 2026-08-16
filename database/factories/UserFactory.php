<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            // G2 (Sistema de Conectores RAG): users ya no tiene tenant_slug — la
            // conexión vive en `repositories` (RepositoryFactory la crea por default).
            // Default de acceso: la navegación del header expone el panorama de equipo
            // (los tests que lo restringen lo setean explícito a 'none').
            'team_dashboard_access' => 'readonly',
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
