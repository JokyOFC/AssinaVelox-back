<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2, onda D — D-PAY (roadmap §2.20 `reconciliation_items`). Uma divergência encontrada
 * numa execução: status, valor, moeda ou pagamento do provedor sem par local. Valores em
 * centavos com a moeda de cada lado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reconciliation_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider_payment_id', 64)->nullable();
            $table->string('external_reference', 64)->nullable();
            $table->string('divergence', 32); // status_mismatch | amount_mismatch | currency_mismatch | missing_local
            $table->string('local_status', 32)->nullable();
            $table->string('provider_status', 32)->nullable();
            $table->unsignedInteger('local_amount_cents')->nullable();
            $table->unsignedInteger('provider_amount_cents')->nullable();
            $table->char('local_currency', 3)->nullable();
            $table->char('provider_currency', 3)->nullable();
            $table->string('note', 191)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('resolution_note', 500)->nullable();
            $table->timestamps();

            $table->index(['resolved_at', 'divergence']);
            $table->index('provider_payment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_items');
    }
};
