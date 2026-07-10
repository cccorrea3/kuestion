<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $uuid = config('app.user_id');

        if (!$uuid) {
            $this->command->warn('APP_USER_ID no está configurado. Se salta AdminUserSeeder.');
            return;
        }

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
