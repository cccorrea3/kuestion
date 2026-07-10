<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $uuid = env('APP_USER_ID') ?? (string) \Illuminate\Support\Str::uuid();

        User::updateOrCreate(
            ['uuid' => $uuid],
            [
                'name' => 'Admin',
                'email' => 'admin@kuestion.app',
                'password' => 'password',
                'tenant_slug' => 'ispend',
            ]
        );
    }
}
