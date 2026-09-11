<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modelos de documento — Fase 2, roadmap §2.1 (docs/fase-2/modelos.md).
 *
 * `templates` é o cadastro (nome, categoria, situação); o CONTEÚDO vive em
 * `template_versions`, que são IMUTÁVEIS: editar um modelo grava uma versão nova e move
 * `templates.current_version_id`. Envelopes gerados guardam a versão usada
 * (`template_usages`), então alterar o modelo depois nunca altera o que já foi gerado.
 *
 * Compatível com MySQL 8: sem ENUM de SQL (os valores são validados pelos enums PHP), sem
 * DEFAULT em JSON/TEXT e com nomes de índice explícitos e curtos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('templates', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('description', 500)->nullable();
            $table->string('category', 60)->nullable();
            $table->string('source_type', 16); // docx | html | pdf (App\Services\Templates\TemplateSourceType)
            $table->string('status', 16)->default('active'); // active | archived
            // FK para template_versions acrescentada depois da criação daquela tabela.
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status'], 'templates_org_status_index');
            $table->index(['organization_id', 'category'], 'templates_org_category_index');
        });

        Schema::create('template_versions', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('template_id')->constrained('templates')->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('source_type', 16);
            // Arquivo de origem (DOCX ou PDF) no disco privado `documents`; nulo para HTML.
            $table->string('storage_disk', 32)->nullable();
            $table->string('storage_path', 255)->nullable();
            $table->string('original_filename', 255)->nullable();
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->char('sha256', 64)->nullable();
            // Corpo HTML JÁ SANITIZADO (fonte html). Nunca é avaliado como Blade/PHP.
            $table->longText('html_body')->nullable();
            // Fonte pdf: páginas conhecidas pelo `pdftool inspect` (base da geometria dos campos).
            $table->unsignedSmallInteger('page_count')->nullable();
            $table->json('pages_meta')->nullable();
            // {"signing_order": "sequential|parallel"}
            $table->json('settings')->nullable();
            // sha256 da definição canônica: salvar sem mudança não cria versão nova.
            $table->char('definition_hash', 64);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['template_id', 'version_number'], 'template_versions_template_number_unique');
        });

        Schema::table('templates', function (Blueprint $table) {
            $table->foreign('current_version_id', 'templates_current_version_fk')
                ->references('id')->on('template_versions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('templates', function (Blueprint $table) {
            $table->dropForeign('templates_current_version_fk');
        });

        Schema::dropIfExists('template_versions');
        Schema::dropIfExists('templates');
    }
};
