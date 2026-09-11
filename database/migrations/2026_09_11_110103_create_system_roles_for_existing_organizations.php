<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Migração de DADOS: cria os três papéis de sistema (Proprietário, Administrador,
 * Operador) para cada organização já existente — inclusive as que estão na carência de
 * exclusão (soft delete). Idempotente: não duplica se rodar de novo.
 *
 * Nada muda para as organizações existentes: `memberships.role` continua sendo a fonte do
 * papel de sistema (role_id fica nulo) e as permissões desses papéis vêm do código
 * (App\Enums\Permission::systemGrants), idênticas às da Fase 1. As linhas criadas aqui
 * só servem de alvo para "acesso por pasta" e para a listagem de funções.
 *
 * Textos fixos aqui (e não lidos de App\Support) para que a migration continue
 * reproduzível mesmo que o código mude depois.
 */
return new class extends Migration
{
    /** @var array<string, array{name: string, description: string}> */
    private const SYSTEM_ROLES = [
        'owner' => ['name' => 'Proprietário', 'description' => 'Acesso total, inclusive cobrança, exclusão da conta e gestão de proprietários.'],
        'admin' => ['name' => 'Administrador', 'description' => 'Gerencia usuários (exceto proprietários), pastas, todos os documentos e configurações.'],
        'member' => ['name' => 'Operador', 'description' => 'Cria e gerencia os próprios documentos; vê apenas os próprios e os das pastas liberadas.'],
    ];

    public function up(): void
    {
        DB::table('organizations')->select('id')->orderBy('id')->chunkById(200, function ($organizations): void {
            $now = Carbon::now();

            foreach ($organizations as $organization) {
                $existing = DB::table('roles')
                    ->where('organization_id', $organization->id)
                    ->where('is_system', true)
                    ->pluck('key')
                    ->all();

                $rows = [];

                foreach (self::SYSTEM_ROLES as $key => $definition) {
                    if (in_array($key, $existing, true)) {
                        continue;
                    }

                    // Uma função personalizada homônima (improvável: a tabela é nova) não pode
                    // barrar a migração — o papel de sistema recebe um sufixo.
                    $name = $definition['name'];
                    $taken = DB::table('roles')
                        ->where('organization_id', $organization->id)
                        ->where('name', $name)
                        ->exists();

                    $rows[] = [
                        'ulid' => (string) Str::ulid(),
                        'organization_id' => $organization->id,
                        'key' => $key,
                        'name' => $taken ? $name.' (sistema)' : $name,
                        'description' => $definition['description'],
                        'is_system' => true,
                        'created_by_user_id' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($rows !== []) {
                    DB::table('roles')->insert($rows);
                }
            }
        });
    }

    public function down(): void
    {
        // Apaga só os papéis de sistema; funções personalizadas saem com a tabela
        // (migration 110100).
        DB::table('roles')->where('is_system', true)->delete();
    }
};
