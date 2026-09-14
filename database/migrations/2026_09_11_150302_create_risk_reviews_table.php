<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3 §3.7 — fila de revisão humana do antifraude (P3-RISK).
 *
 * Um caso cobre os sinais da organização com `id` em (`after_signal_id`, `through_signal_id`]
 * — `through_signal_id` é fixado na decisão; enquanto o caso está aberto, todo sinal novo da
 * organização entra nele. Assim `risk_signals` nunca precisa de UPDATE (append-only).
 *
 * `status`: open | watching | cleared | confirmed. `decision`: clear | watch | confirm.
 * `trigger`: signals (aberto pelo motor) | appeal (aberto por pedido de revisão, LGPD art. 20).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk_reviews', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('open');
            $table->string('trigger', 16)->default('signals');
            $table->unsignedBigInteger('after_signal_id')->default(0);
            $table->unsignedBigInteger('through_signal_id')->nullable();
            $table->string('status_before', 16)->nullable(); // estado da organização ao abrir
            $table->timestamp('opened_at');
            $table->timestamp('restricted_at')->nullable(); // restrição automática aplicada neste caso
            $table->timestamp('appeal_requested_at')->nullable();
            $table->foreignId('appeal_requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('appeal_message')->nullable();
            $table->string('decision', 16)->nullable();
            $table->text('decision_reason')->nullable();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'opened_at'], 'risk_reviews_status_opened_index');
            $table->index(['organization_id', 'status'], 'risk_reviews_org_status_index');
            $table->index(['decided_at'], 'risk_reviews_decided_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_reviews');
    }
};
