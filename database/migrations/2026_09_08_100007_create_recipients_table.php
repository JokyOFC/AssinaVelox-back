<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipients', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('envelope_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('name', 160);
            $table->string('email');
            $table->string('phone', 32)->nullable();
            $table->string('role', 32)->default('signer'); // RecipientRole
            $table->unsignedInteger('order_index')->default(1);
            $table->string('status', 32)->default('pending'); // RecipientStatus
            $table->string('auth_method', 32)->default('email_otp'); // AuthMethod
            $table->timestamp('signed_at')->nullable();
            $table->timestamp('refused_at')->nullable();
            $table->text('refusal_reason')->nullable();
            $table->unsignedInteger('notification_count')->default(0);
            $table->timestamp('last_notified_at')->nullable();
            $table->timestamps();

            $table->unique(['envelope_id', 'email']);
            $table->index(['envelope_id', 'order_index']);
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipients');
    }
};
