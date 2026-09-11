<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Funções (papéis) por organização — Fase 2, roadmap §2.14 (docs/fase-2/permissoes-e-times.md).
 *
 * `role_permissions` em linhas (e não uma coluna JSON): a unicidade (role_id, permission)
 * impede duplicatas no banco, a pergunta "quais funções concedem X" é uma consulta
 * indexada, e retirar uma permissão do catálogo é um DELETE simples — sem varrer JSON,
 * que no MySQL não se indexa sem colunas geradas.
 *
 * Papéis de sistema (`is_system`, `key` ∈ owner|admin|member) têm linha em `roles` para
 * servirem de alvo de acesso por pasta, mas suas permissões vêm do código
 * (App\Enums\Permission::systemGrants), nunca de `role_permissions`.
 *
 * `memberships.role_id` e `membership_invitations.role_id` apontam SÓ para funções
 * personalizadas; nulo significa "papel de sistema indicado por `role`".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('key', 32)->nullable(); // owner | admin | member (só papéis de sistema)
            $table->string('name', 80);
            $table->string('description', 255)->nullable();
            $table->boolean('is_system')->default(false);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'key']);
            $table->unique(['organization_id', 'name']);
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->string('permission', 64); // App\Enums\Permission
            $table->timestamp('created_at')->nullable();

            $table->unique(['role_id', 'permission']);
        });

        Schema::table('memberships', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable()->after('role')->constrained('roles')->nullOnDelete();
        });

        Schema::table('membership_invitations', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable()->after('role')->constrained('roles')->nullOnDelete();
            // Pastas concedidas no convite: [{"folder_id": int, "level": "view|manage"}].
            $table->json('folder_access')->nullable()->after('role_id');
        });
    }

    public function down(): void
    {
        Schema::table('membership_invitations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('role_id');
            $table->dropColumn('folder_access');
        });

        Schema::table('memberships', function (Blueprint $table) {
            $table->dropConstrainedForeignId('role_id');
        });

        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('roles');
    }
};
