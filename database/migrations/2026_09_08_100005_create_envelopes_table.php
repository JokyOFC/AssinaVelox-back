<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('envelopes', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            // Restrito: impede exclusão física acidental de uma organização com envelopes.
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('folder_id')->nullable()->constrained('folders')->nullOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            // Sequência por organização; exibido como AV-00001 (accessor display_code).
            $table->unsignedInteger('number');
            $table->string('title', 200);
            $table->text('message')->nullable();
            $table->string('status', 32)->default('draft'); // EnvelopeStatus
            $table->string('signing_order', 32)->default('sequential'); // SigningOrder
            $table->unsignedInteger('current_order')->default(1);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('refused_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            // FKs para document_versions são adicionadas na migration de documents (dependência circular).
            $table->unsignedBigInteger('sent_document_version_id')->nullable();
            $table->unsignedBigInteger('final_document_version_id')->nullable();
            // 12 caracteres base32 (sem 0/1/O/I) gerados no envio; exibido XXXX-XXXX-XXXX.
            $table->char('verification_code', 24)->nullable()->unique();
            $table->char('finalization_key', 26)->nullable();
            $table->string('terms_version', 32)->nullable();
            $table->json('settings')->nullable(); // otp_required, expiration_days
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'created_at']);
            $table->index(['organization_id', 'folder_id']);
            $table->index(['organization_id', 'created_by_user_id'], 'envelopes_org_creator_index');
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('envelopes');
    }
};
