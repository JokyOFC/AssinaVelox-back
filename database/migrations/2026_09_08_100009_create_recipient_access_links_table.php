<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipient_access_links', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('recipient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('envelope_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            // Token: 32 bytes aleatórios em base64url; só o digest SHA-256 é gravado.
            $table->char('token_digest', 64)->unique();
            $table->string('purpose', 32)->default('signing'); // DeliveryPurpose-like: signing | download
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->unsignedInteger('use_count')->default(0);
            $table->timestamp('created_at')->nullable();

            $table->index(['recipient_id', 'purpose', 'revoked_at'], 'recipient_access_links_active_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipient_access_links');
    }
};
