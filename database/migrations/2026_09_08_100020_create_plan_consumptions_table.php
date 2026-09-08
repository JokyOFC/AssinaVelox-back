<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_consumptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('subscription_id')->constrained()->restrictOnDelete();
            // Nulo com nullOnDelete: o ledger sobrevive à exclusão física do envelope.
            $table->foreignId('envelope_id')->nullable()->constrained()->nullOnDelete();
            $table->string('idempotency_key', 80)->unique(); // envelope:{id}:send
            $table->unsignedInteger('quantity')->default(1);
            $table->string('status', 32)->default('reserved'); // PlanConsumptionStatus
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('committed_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->index(['subscription_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_consumptions');
    }
};
