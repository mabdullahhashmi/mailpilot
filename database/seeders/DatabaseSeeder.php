<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::firstOrCreate(
            ['email' => 'admin@mailpilot.io'],
            ['name' => 'Admin', 'password' => bcrypt('MailPilot@2026')]
        );

        $this->call(WarmupSeeder::class);
        $this->call(NicheTemplateSeeder::class);
    }
}
