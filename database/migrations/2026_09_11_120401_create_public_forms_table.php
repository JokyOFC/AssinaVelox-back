<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Formulário público que gera envelope a partir de um modelo (Fase 2 §2.2, C-FORM —
 * docs/fase-2/formulario-publico.md).
 *
 *  - `public_token`: identificador IMPREVISÍVEL da URL pública (40 caracteres aleatórios,
 *    ~238 bits). O `ulid` continua sendo o identificador das rotas internas — ele carrega o
 *    instante de criação e não deve circular fora da organização.
 *  - `template_version_id`: a versão do modelo contra a qual a configuração foi salva. Se o
 *    modelo ganhar uma versão nova, o formulário fica indisponível até alguém revisá-lo.
 *  - `schema` (JSON): variáveis preenchidas pelo público, valores fixos, papel ocupado por
 *    quem preenche e participantes fixos dos demais papéis.
 *  - `settings` (JSON): limite de envios por período e título do documento gerado.
 *  - `status` ∈ draft | active | paused | revoked; `destination` ∈ auto_send | review
 *    (string, não ENUM de SQL — regra da onda).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('public_forms', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('template_id')->constrained('templates')->cascadeOnDelete();
            $table->foreignId('template_version_id')->constrained('template_versions')->cascadeOnDelete();
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('public_token', 64)->unique('public_forms_public_token_unique');
            $table->string('title', 160);
            $table->text('instructions')->nullable();
            $table->string('status', 16);
            $table->string('destination', 16);
            $table->json('schema')->nullable();
            $table->json('settings')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status'], 'public_forms_org_status_index');
            $table->index(['template_id'], 'public_forms_template_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_forms');
    }
};
