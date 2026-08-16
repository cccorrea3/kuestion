<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            AdminUserSeeder::class,
            DevUsersSeeder::class, // usuario de prueba del entorno de desarrollo
        ]);
    }
}
