<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3 §3.10 (P3-AFF) — afiliados. docs/fase-3/afiliados.md.
 *
 * `payout_details` é CIFRADO em repouso (cast `encrypted:array`, chave da APP_KEY) e nunca é
 * exibido por inteiro. Os IPs são guardados só como HMAC (`*_ip_hash`), para a regra de
 * autoindicação comparar sem reter o endereço. `user_id` fica nulo se a conta for excluída.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliates', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
            // Gerado na aprovação; único; nunca reaproveitado.
            $table->string('code', 16)->nullable()->unique();
            $table->unsignedInteger('commission_rate_bp');
            $table->string('status', 20)->index();
            $table->text('payout_details')->nullable();
            $table->string('terms_version', 40);
            $table->timestamp('terms_accepted_at');
            $table->char('application_ip_hash', 64)->nullable();
            $table->char('last_ip_hash', 64)->nullable();
            $table->timestamp('last_ip_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->string('status_reason', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliates');
    }
};
