<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2, onda D — D-PAY (roadmap §2.20 `reconciliation_runs`). Uma execução da conciliação
 * (GET /v1/payments/search na janela de `date_last_updated`). A conciliação NUNCA corrige
 * pagamento: só registra divergência para revisão humana.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_runs', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->string('provider', 32);
            $table->string('environment', 32);
            $table->timestamp('window_start');
            $table->timestamp('window_end');
            $table->string('status', 16)->default('running'); // running | completed | partial | failed
            $table->string('trigger', 16); // schedule | admin
            $table->foreignId('triggered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('remote_count')->default(0);
            $table->unsignedInteger('matched_count')->default(0);
            $table->unsignedInteger('divergence_count')->default(0);
            $table->string('error', 191)->nullable();
            $table->char('correlation_id', 26)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_runs');
    }
};
