<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3 §3.10 (P3-AFF) — lote de repasse. O sistema CALCULA, não paga: o lote é montado pela
 * operadora e marcado como pago MANUALMENTE (quem, quando, referência externa) depois que o
 * repasse foi feito fora da plataforma.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payout_batches', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->char('currency', 3);
            $table->string('status', 20)->index();
            $table->timestamp('cutoff_at');
            $table->bigInteger('total_cents')->default(0);
            $table->unsignedInteger('affiliates_count')->default(0);
            $table->unsignedInteger('entries_count')->default(0);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('paid_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('marked_paid_at')->nullable();
            $table->string('external_reference', 120)->nullable();
            $table->string('notes', 500)->nullable();
            $table->foreignId('canceled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_batches');
    }
};
