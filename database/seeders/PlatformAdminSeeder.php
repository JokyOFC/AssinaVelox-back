<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Usuário interno da plataforma para desenvolvimento. Nunca roda fora de local/testing.
 */
class PlatformAdminSeeder extends Seeder
{
    public const EMAIL = 'admin@assinavelox.local';

    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            $this->command->warn('PlatformAdminSeeder ignorado: só roda em local/testing.');

            return;
        }

        $admin = User::query()->firstOrNew(['email' => self::EMAIL]);

        // forceFill: is_platform_admin nunca é mass-assignable.
        $admin->forceFill([
            'name' => 'Administração AssinaVelox',
            'password' => 'password',
            'email_verified_at' => now(),
            'is_platform_admin' => true,
            'locale' => 'pt_BR',
            'timezone' => 'America/Sao_Paulo',
            'terms_accepted_at' => now(),
            'terms_version' => '2026-09',
        ])->save();
    }
}
