<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3 §3.2 (F-ANCHOR) — campo SUGERIDO por âncora ou OCR, à espera da revisão do remetente.
 * docs/fase-3/ancoras-e-ocr.md §5.
 *
 * Uma sugestão nunca é um `signing_field`: só vira campo quando o remetente a confirma no
 * editor (e o campo passa pela mesma validação de `FieldSync`). Enquanto houver sugestão
 * `pending` na versão exibível corrente, o envelope não fica pronto.
 *
 * Geometria no mesmo sistema de `signing_fields` (frações [0,1] do CropBox exibido, origem no
 * canto superior esquerdo, rotação considerada). `role_hint` é o identificador do marcador
 * (`[a-z0-9_-]`, até 40) — nunca texto livre do documento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('field_suggestions', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('envelope_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_version_id')->constrained('document_versions')->cascadeOnDelete();
            $table->foreignId('anchor_scan_id')->constrained('anchor_scans')->cascadeOnDelete();
            $table->foreignId('field_anchor_rule_id')->nullable()->constrained('field_anchor_rules')->nullOnDelete();
            $table->foreignId('recipient_id')->nullable()->constrained()->nullOnDelete();
            // marker | rule | literal
            $table->string('source', 16);
            // text | ocr
            $table->string('via', 8);
            $table->string('type', 16);
            $table->unsignedSmallInteger('page');
            $table->decimal('x', 9, 6);
            $table->decimal('y', 9, 6);
            $table->decimal('width', 9, 6);
            $table->decimal('height', 9, 6);
            $table->boolean('required')->default(true);
            $table->string('label', 120)->nullable();
            $table->string('role_hint', 40)->nullable();
            $table->decimal('confidence', 5, 2)->nullable();
            // SuggestionStatus: pending | accepted | discarded | superseded
            $table->string('status', 16)->default('pending');
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['envelope_id', 'status'], 'field_suggestions_envelope_status_index');
            $table->index(['document_version_id', 'status'], 'field_suggestions_version_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('field_suggestions');
    }
};
