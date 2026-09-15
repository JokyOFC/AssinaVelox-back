<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3 §3.2 (F-ANCHOR) — regras de âncora por modelo. docs/fase-3/ancoras-e-ocr.md §4.
 *
 * Uma regra diz: "onde o documento gerado tiver este TEXTO LITERAL, sugira um campo deste tipo
 * para o participante nesta posição da lista do modelo, com este deslocamento e tamanho".
 * `pattern` é texto literal (busca com espaços, maiúsculas e acentos normalizados) — nunca
 * expressão regular. `role_position` é a posição 1..N do participante na versão ATUAL do
 * modelo (os papéis são imutáveis por versão; a posição sobrevive a uma versão nova).
 *
 * As regras só SUGEREM campos: toda sugestão passa pela revisão do remetente no editor antes
 * de o envelope ficar pronto. Tabela aditiva; com a flag `field_anchors` desligada fica vazia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('field_anchor_rules', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('template_id')->constrained()->cascadeOnDelete();
            $table->string('pattern', 160);
            // FieldType: signature | initials | name | date | text | checkbox
            $table->string('field_type', 16);
            $table->unsignedTinyInteger('role_position')->nullable();
            $table->string('role_name', 120)->nullable();
            // AnchorPlacement: below | right | above | over
            $table->string('placement', 8)->default('below');
            $table->decimal('offset_x_pt', 8, 2)->default(0);
            $table->decimal('offset_y_pt', 8, 2)->default(0);
            $table->decimal('width_pt', 8, 2)->nullable();
            $table->decimal('height_pt', 8, 2)->nullable();
            $table->boolean('required')->default(true);
            // all | first
            $table->string('occurrence', 8)->default('all');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['template_id', 'sort_order'], 'field_anchor_rules_template_sort_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('field_anchor_rules');
    }
};
