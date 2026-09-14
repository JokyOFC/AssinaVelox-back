<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3 §3.10 (P3-AFF) — indicação de uma organização por um afiliado.
 *
 * `organization_id` é UNIQUE: uma organização é atribuída UMA única vez, a quem chegou primeiro
 * (roadmap §3.10). Uma indicação barrada (autoindicação) também ocupa a vaga, para que a
 * organização não possa ser "reindicada" por outro link depois. `expires_at` é o fim do período
 * em que os pagamentos da organização geram comissão (nulo = sem prazo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referrals', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('affiliate_id')->constrained('affiliates')->cascadeOnDelete();
            $table->foreignId('organization_id')->nullable()->unique()->constrained('organizations')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 20);
            $table->string('status', 20);
            $table->json('block_reasons')->nullable();
            $table->char('signup_ip_hash', 64)->nullable()->index();
            $table->timestamp('clicked_at')->nullable();
            $table->timestamp('attributed_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('review_requested_at')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->timestamps();

            $table->index(['affiliate_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referrals');
    }
};
