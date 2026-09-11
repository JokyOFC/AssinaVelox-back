<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Fase 2 §2.9 (C-CAN) — etapa do PIN do remetente.
|
| Depois do código do canal, quando o participante tem PIN, a sessão continua `pending_auth`
| e ganha um "portão": `channel_verified_at` (quando o código foi confirmado) e
| `pin_gate_digest` (SHA-256 de um token aleatório guardado só na sessão Laravel do
| navegador). A sessão só vira `authenticated` quando o PIN confere. Aditiva.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signing_sessions', function (Blueprint $table) {
            $table->timestamp('channel_verified_at')->nullable();
            $table->char('pin_gate_digest', 64)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('signing_sessions', function (Blueprint $table) {
            $table->dropIndex(['pin_gate_digest']);
            $table->dropColumn(['channel_verified_at', 'pin_gate_digest']);
        });
    }
};
