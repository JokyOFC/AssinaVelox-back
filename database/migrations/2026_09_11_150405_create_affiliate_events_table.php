<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3 §3.10 (P3-AFF) — trilha append-only do programa de afiliados: candidatura, aprovação,
 * alteração de taxa (antes → depois, motivo, quem), revisão humana de indicação barrada, lotes
 * (criado, pago, cancelado, exportado). Sem `updated_at`: nada aqui é alterado.
 * Payload mínimo — nunca dados de repasse, e-mail de indicado ou IP de indicado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliate_events', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('affiliate_id')->nullable()->constrained('affiliates')->nullOnDelete();
            $table->foreignId('referral_id')->nullable()->constrained('referrals')->nullOnDelete();
            $table->foreignId('payout_batch_id')->nullable()->constrained('payout_batches')->nullOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 60)->index();
            $table->json('payload')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->nullable();

            $table->index(['affiliate_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_events');
    }
};
