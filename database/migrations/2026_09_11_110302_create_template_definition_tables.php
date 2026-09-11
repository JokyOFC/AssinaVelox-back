<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Definição de uma versão de modelo (docs/fase-2/modelos.md): variáveis tipadas, papéis
 * (participantes nomeados) e campos pré-posicionados por papel. Tudo pertence a UMA
 * versão e é imutável junto com ela.
 *
 * `template_fields` usa exatamente a geometria de `signing_fields` (frações [0,1] da página
 * exibida, origem superior esquerda — docs/campos-e-geometria.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('template_variables', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('template_version_id')->constrained('template_versions')->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('key', 64); // [a-z][a-z0-9_]* — marcador {{key}} (HTML) ou ${key} (DOCX)
            $table->string('label', 120);
            $table->string('type', 16); // App\Services\Templates\VariableType
            $table->boolean('required')->default(false);
            $table->string('help_text', 255)->nullable();
            $table->text('default_value')->nullable();
            $table->json('options')->nullable(); // limites, casas decimais, opções do select
            $table->unsignedSmallInteger('position')->default(0);

            $table->unique(['template_version_id', 'key'], 'template_variables_version_key_unique');
        });

        Schema::create('template_roles', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('template_version_id')->constrained('template_versions')->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 40); // "Locatário", "Testemunha 1" → recipients.role_label
            $table->string('participant_role', 16)->default('signer'); // App\Enums\RecipientRole
            $table->unsignedSmallInteger('position')->default(0);

            $table->unique(['template_version_id', 'name'], 'template_roles_version_name_unique');
        });

        Schema::create('template_fields', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('template_version_id')->constrained('template_versions')->cascadeOnDelete();
            $table->foreignId('template_role_id')->constrained('template_roles')->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16); // App\Enums\FieldType
            $table->unsignedSmallInteger('page');
            $table->decimal('x', 9, 6);
            $table->decimal('y', 9, 6);
            $table->decimal('width', 9, 6);
            $table->decimal('height', 9, 6);
            $table->string('box_type', 16);
            $table->decimal('page_width_pt', 10, 3)->nullable();
            $table->decimal('page_height_pt', 10, 3)->nullable();
            $table->smallInteger('page_rotation')->default(0);
            $table->boolean('required')->default(true);
            $table->string('label', 120)->nullable();
            $table->json('options')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->index(['template_version_id', 'page'], 'template_fields_version_page_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('template_fields');
        Schema::dropIfExists('template_roles');
        Schema::dropIfExists('template_variables');
    }
};
