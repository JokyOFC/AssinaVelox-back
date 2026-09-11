<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Acesso por pasta (Fase 2, roadmap §2.14): cada linha concede `level` (view|manage) sobre
 * UMA pasta a exatamente um sujeito — uma função (role_id), um time (team_id) ou uma
 * membership (membership_id). "Exatamente um" é garantido pelo serviço
 * (App\Support\PermissionsFolderAccess), porque CHECK constraints não são portáveis
 * entre MySQL e SQLite no schema builder.
 *
 * `organization_id` é redundante com a pasta, de propósito: toda consulta de visibilidade
 * filtra por ele primeiro (isolamento entre organizações e índice).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('folder_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('folder_id')->constrained('folders')->cascadeOnDelete();
            $table->foreignId('role_id')->nullable()->constrained('roles')->cascadeOnDelete();
            $table->foreignId('team_id')->nullable()->constrained('teams')->cascadeOnDelete();
            $table->foreignId('membership_id')->nullable()->constrained('memberships')->cascadeOnDelete();
            $table->string('level', 16); // App\Enums\FolderAccessLevel: view | manage
            $table->foreignId('granted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'membership_id'], 'folder_permissions_org_membership_index');
            $table->index(['organization_id', 'role_id'], 'folder_permissions_org_role_index');
            $table->index(['organization_id', 'team_id'], 'folder_permissions_org_team_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('folder_permissions');
    }
};
