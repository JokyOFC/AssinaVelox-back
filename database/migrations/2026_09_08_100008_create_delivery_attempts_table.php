<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_attempts', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('envelope_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('recipient_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 32); // DeliveryChannel
            $table->string('provider', 64);
            $table->string('purpose', 32); // DeliveryPurpose
            $table->string('to_address');
            $table->string('status', 32)->default('queued'); // DeliveryStatus — "sent" != "delivered"
            $table->string('provider_message_id')->nullable();
            $table->text('error_message')->nullable();
            $table->char('correlation_id', 26)->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['envelope_id', 'created_at']);
            $table->index(['recipient_id', 'purpose']);
            $table->index('provider_message_id');
            $table->index('correlation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_attempts');
    }
};
