<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->string('status', 32)->default('pending'); // SubscriptionStatus
            $table->timestamp('started_at')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->unsignedInteger('envelopes_used')->default(0);
            $table->unsignedInteger('envelopes_reserved')->default(0);
            $table->string('provider', 32)->nullable(); // mercadopago | null (free/manual)
            $table->timestamps();

            // Uma ativa por organização: garantido por transação + lock no serviço
            // (MySQL não tem índice parcial).
            $table->index(['organization_id', 'status']);
            $table->index(['status', 'current_period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
