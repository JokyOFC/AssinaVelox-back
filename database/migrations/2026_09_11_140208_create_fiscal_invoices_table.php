<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2, onda D — D-PAY (roadmap §2.21 `fiscal_invoices`, classe B). Uma NFS-e por pagamento
 * (`payment_id` UNIQUE, idempotência por pagamento). `status`: pending | issued | canceled |
 * failed | simulated. Com o simulador, a linha é `simulated` e NUNCA tem número, código de
 * verificação, PDF ou XML. `fiscal_profiles` não foi criada: o tomador sai de
 * `billing.profile.update` (organizations.legal_name/tax_id + settings.billing_profile).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_invoices', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->unique()->constrained()->restrictOnDelete();
            $table->string('provider', 32);
            $table->string('status', 16)->default('pending');
            $table->string('external_id', 191)->nullable();
            $table->string('number', 64)->nullable();
            $table->string('series', 16)->nullable();
            $table->string('verification_code', 64)->nullable();
            $table->string('idempotency_key', 64)->unique();
            $table->string('pdf_path', 500)->nullable();
            $table->string('xml_path', 500)->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->string('error', 191)->nullable();
            $table->char('correlation_id', 26)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_invoices');
    }
};
