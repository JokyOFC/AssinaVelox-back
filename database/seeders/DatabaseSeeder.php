<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * PlanSeeder é seguro em qualquer ambiente (idempotente, sem dados pessoais).
     * Admin da plataforma e organizações de demonstração só em local/testing.
     */
    public function run(): void
    {
        $this->call(PlanSeeder::class);

        if (app()->environment('local', 'testing')) {
            $this->call([
                PlatformAdminSeeder::class,
                DemoOrganizationSeeder::class,
            ]);
        }
    }
}
