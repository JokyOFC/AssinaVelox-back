<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qual versão de modelo gerou cada envelope (docs/fase-2/modelos.md §5).
 *
 * Tabela à parte (e não uma coluna em `envelopes`) para não mexer no modelo do envelope:
 * o vínculo é só de rastreabilidade — o envelope gerado é uma CÓPIA independente, e
 * nenhuma regra do envelope depende do modelo. Os valores preenchidos NÃO são guardados
 * aqui (já estão no documento gerado; repeti-los só multiplicaria dado pessoal).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('template_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('template_id')->constrained('templates')->cascadeOnDelete();
            $table->foreignId('template_version_id')->constrained('template_versions')->cascadeOnDelete();
            $table->foreignId('envelope_id')->unique()->constrained('envelopes')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['template_id', 'created_at'], 'template_usages_template_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('template_usages');
    }
};
