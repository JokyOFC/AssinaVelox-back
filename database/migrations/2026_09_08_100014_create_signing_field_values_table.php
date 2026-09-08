<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signing_field_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('signing_field_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('recipient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('signature_acceptance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('envelope_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->text('value_text')->nullable();
            $table->boolean('value_bool')->nullable();
            $table->string('image_path', 512)->nullable(); // PNG normalizado
            $table->timestamp('created_at')->nullable();

            $table->index(['envelope_id', 'recipient_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signing_field_values');
    }
};
