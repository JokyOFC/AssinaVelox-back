<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signing_fields', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('envelope_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('type', 32); // FieldType
            $table->unsignedInteger('page'); // 1-based
            // Geometria normalizada [0,1] em relação ao box exibido; origem no canto superior esquerdo,
            // já considerando a rotação da página.
            $table->decimal('x', 9, 6);
            $table->decimal('y', 9, 6);
            $table->decimal('width', 9, 6);
            $table->decimal('height', 9, 6);
            $table->string('box_type', 32)->default('cropbox'); // FieldBoxType
            $table->decimal('page_width_pt', 9, 3)->nullable();
            $table->decimal('page_height_pt', 9, 3)->nullable();
            $table->unsignedSmallInteger('page_rotation')->default(0);
            $table->boolean('required')->default(true);
            $table->string('label', 120)->nullable();
            $table->json('options')->nullable(); // font_size, date_format, default
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['envelope_id', 'page']);
            $table->index(['recipient_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signing_fields');
    }
};
