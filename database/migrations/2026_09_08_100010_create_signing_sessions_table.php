<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signing_sessions', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('recipient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('envelope_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('access_link_id')->nullable()->constrained('recipient_access_links')->nullOnDelete();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->char('token_digest', 64)->unique();
            $table->string('status', 32)->default('pending_auth'); // SigningSessionStatus
            $table->char('authorization_token_digest', 64)->nullable();
            $table->timestamp('authorization_expires_at')->nullable();
            $table->char('snapshot_hash', 64)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('authenticated_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['recipient_id', 'status']);
            $table->index('authorization_token_digest');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signing_sessions');
    }
};
