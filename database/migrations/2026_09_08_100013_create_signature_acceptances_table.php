<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signature_acceptances', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            // UNIQUE: um único aceite por destinatário (impede duplicidade sob concorrência).
            $table->foreignId('recipient_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('envelope_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_version_id')->constrained()->cascadeOnDelete();
            // Nulos com nullOnDelete: a eventual limpeza de sessões/desafios não pode destruir o aceite.
            $table->foreignId('signing_session_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('auth_challenge_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->timestamp('accepted_at'); // UTC do servidor
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('auth_method', 32); // AuthMethod
            $table->string('terms_version', 32)->nullable();
            $table->text('consent_statement');
            $table->char('document_sha256', 64); // bytes da versão apresentada
            $table->json('fields_snapshot')->nullable(); // campos apresentados e valores
            $table->string('signature_kind', 32)->nullable(); // SignatureKind
            $table->string('signature_image_path', 512)->nullable();
            $table->string('typed_name', 160)->nullable();
            $table->string('typed_font', 64)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['envelope_id', 'accepted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signature_acceptances');
    }
};
