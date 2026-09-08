<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_webhook_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32);
            $table->string('event_fingerprint', 191); // type + data.id + action
            $table->string('topic', 64)->nullable();
            $table->string('action', 64)->nullable();
            $table->json('payload')->nullable();
            $table->text('signature_header')->nullable();
            $table->boolean('signature_valid')->default(false);
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->string('processing_status', 32)->default('received'); // WebhookProcessingStatus
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'event_fingerprint']);
            $table->index(['processing_status', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_receipts');
    }
};
