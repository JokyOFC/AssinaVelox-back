<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Envios de formulário público (Fase 2 §2.2, C-FORM — docs/fase-2/formulario-publico.md).
 *
 *  - `payload`: nome, e-mail e valores digitados, CIFRADOS com a APP_KEY (cast
 *    `encrypted:array`). Existe só até o envelope nascer: depois da confirmação ele é
 *    apagado (os valores já estão no documento gerado). Envio não confirmado é removido
 *    inteiro pela limpeza quando o link vence.
 *  - `email_digest`: HMAC do e-mail normalizado, para limitar links pendentes por
 *    endereço sem guardar o e-mail em claro.
 *  - `confirmation_digest`: HMAC do token do link de confirmação. O token em si só existe
 *    no e-mail.
 *  - `status` ∈ pending_confirmation | processing | pending_review | sent | rejected | failed
 *    (string, não ENUM de SQL).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('public_form_submissions', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('public_form_id')->constrained('public_forms')->cascadeOnDelete();
            $table->string('status', 24);
            $table->text('payload')->nullable();
            $table->string('email_digest', 64);
            $table->string('confirmation_digest', 64)->nullable()->unique('pf_submissions_confirmation_unique');
            $table->timestamp('confirmation_expires_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('envelope_id')->nullable()->constrained('envelopes')->nullOnDelete();
            $table->string('failure_reason', 64)->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('privacy_notice_version', 40)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();

            $table->index(['public_form_id', 'status'], 'pf_submissions_form_status_index');
            $table->index(['public_form_id', 'confirmed_at'], 'pf_submissions_form_confirmed_index');
            $table->index(['public_form_id', 'email_digest'], 'pf_submissions_form_email_index');
            $table->index(['status', 'confirmation_expires_at'], 'pf_submissions_expiry_index');
            $table->index(['organization_id', 'status'], 'pf_submissions_org_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_form_submissions');
    }
};
