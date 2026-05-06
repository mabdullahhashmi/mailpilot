<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class CreateAdminAndClientUsers extends Seeder
{
    public function run(): void
    {
        $now = now();

        // Create or update admin
        $adminEmail = env('DEV_ADMIN_EMAIL', 'admin@mailpilot.io');
        $adminPass = env('DEV_ADMIN_PASSWORD', 'ChangeMe123!');

        $adminId = DB::table('users')->updateOrInsert(
            ['email' => $adminEmail],
            ['name' => 'Admin', 'password' => Hash::make($adminPass), 'role' => 'admin', 'updated_at' => $now, 'created_at' => $now]
        );

        // Create a limited client user
        $clientEmail = env('DEV_CLIENT_EMAIL', 'client@example.com');
        $clientPass = env('DEV_CLIENT_PASSWORD', 'Client123!');

        DB::table('users')->updateOrInsert(
            ['email' => $clientEmail],
            ['name' => 'Client', 'password' => Hash::make($clientPass), 'role' => 'client', 'updated_at' => $now, 'created_at' => $now]
        );

        // Note: Assignments of existing resources to these users should be done intentionally in production.
    }
}
