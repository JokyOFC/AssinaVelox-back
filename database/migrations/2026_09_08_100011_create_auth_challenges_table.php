<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auth_challenges', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('signing_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('envelope_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('channel', 32)->default('email'); // DeliveryChannel
            // HMAC-SHA256 (segredo derivado da APP_KEY) sobre "ulid|code"; o código nunca é gravado.
            $table->char('code_hash', 64);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('max_attempts')->default(5);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->foreignId('delivery_attempt_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['signing_session_id', 'consumed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_challenges');
    }
};
