<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Fase 2 §2.8 (C-CAN, classe B) — domínios de envio da organização.
|
| status ∈ pending | verified | failed (texto, sem ENUM SQL). `expected_records` guarda os
| registros DNS que o verificador pediu (JSON sem DEFAULT). `is_simulated` marca o que o
| simulador "verificou": um domínio simulado NUNCA vira remetente (SenderIdentity).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sender_domains', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('domain', 253);
            $table->string('status', 16)->default('pending'); // SenderDomain::STATUS_*
            $table->string('verification_token', 64);
            $table->json('expected_records')->nullable();
            $table->string('provider', 64);
            $table->string('provider_domain_id', 191)->nullable();
            $table->boolean('is_simulated')->default(false);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'domain'], 'sender_domains_org_domain_unique');
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sender_domains');
    }
};
