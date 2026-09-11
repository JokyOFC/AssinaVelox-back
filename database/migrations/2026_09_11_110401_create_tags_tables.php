<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Etiquetas (tags) de envelopes — Fase 2, roadmap §2.14 (docs/fase-2/tags-relatorios-e-logs.md).
 *
 * `name_key` é o nome normalizado (minúsculas, espaços colapsados): a unicidade por
 * organização vale sem diferenciar maiúsculas, em MySQL e em SQLite. `envelope_tag` carrega
 * `organization_id` para que toda consulta filtre a organização sem depender de join.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 40);
            $table->string('name_key', 40);
            $table->string('color', 16);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'name_key'], 'tags_org_name_key_unique');
        });

        Schema::create('envelope_tag', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('envelope_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('tags')->cascadeOnDelete();
            $table->foreignId('added_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['envelope_id', 'tag_id'], 'envelope_tag_envelope_tag_unique');
            $table->index(['tag_id', 'envelope_id'], 'envelope_tag_tag_envelope_index');
            $table->index(['organization_id', 'tag_id'], 'envelope_tag_org_tag_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('envelope_tag');
        Schema::dropIfExists('tags');
    }
};
