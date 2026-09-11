<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Fase 2 §2.9 (C-CAN) — recibos do webhook de status de SMS/WhatsApp.
|
| Só é gravado recibo de aviso com assinatura VÁLIDA. A idempotência é da coluna:
| UNIQUE(provider, event_fingerprint), com fingerprint = SHA-256 do id do evento (ou de
| message_id|status|occurred_at). O payload guardado é minimizado (sem telefone nem texto).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_status_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 16); // DeliveryChannel
            $table->string('provider', 64);
            $table->char('event_fingerprint', 64);
            $table->string('provider_message_id', 191)->nullable();
            $table->string('reported_status', 32)->nullable(); // DeliveryStatus
            $table->foreignId('delivery_attempt_id')->nullable()->constrained()->nullOnDelete();
            $table->json('payload')->nullable();
            $table->boolean('signature_valid')->default(false);
            $table->boolean('is_simulated')->default(false);
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->string('processing_status', 32)->default('received'); // WebhookProcessingStatus
            $table->string('error', 255)->nullable();
            $table->timestamps();

            $table->unique(['provider', 'event_fingerprint'], 'channel_status_receipts_fingerprint_unique');
            $table->index('provider_message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_status_receipts');
    }
};
