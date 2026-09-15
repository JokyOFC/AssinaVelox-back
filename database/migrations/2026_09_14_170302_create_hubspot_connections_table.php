<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3 §3.9 — G-CONN (docs/fase-3/conectores.md §5). Conexão OAuth de UMA organização com UMA
 * conta (portal) do HubSpot.
 *
 * Aditiva. `access_token` e `refresh_token` são gravados CIFRADOS (cast `encrypted` do model,
 * chave da APP_KEY) e nunca saem em `toArray()`, log, fila ou resposta. O refresh token é o
 * único token de terceiro persistente desta onda: a atualização do negócio/contato acontece
 * quando o envelope conclui, sem ninguém logado (decisão registrada no doc, §5.4).
 *
 * `portal_id` é UNIQUE: um portal pertence a uma organização só — é por ele que a ação de
 * workflow descobre a organização, então um portal em duas organizações quebraria o isolamento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hubspot_connections', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('portal_id')->unique();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->json('scopes')->nullable();
            // active | error (renovação recusada: precisa reconectar).
            $table->string('status', 20)->default('active');
            $table->string('last_error_code', 64)->nullable();
            $table->foreignId('connected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_refreshed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hubspot_connections');
    }
};
