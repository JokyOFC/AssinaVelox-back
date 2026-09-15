<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3 §3.2 (F-ANCHOR) — uma busca de âncoras em UM documento (texto do PDF) ou uma
 * passagem de OCR nas páginas sem texto (`trigger = ocr`, com `parent_id`).
 * docs/fase-3/ancoras-e-ocr.md §3.
 *
 * `query` guarda o que foi PROCURADO (marcadores ligados e os textos literais do remetente ou
 * das regras do modelo), nunca o texto do documento. Enquanto uma busca está `pending` ou
 * `running` (e não passou do prazo de `field_anchors.stale_minutes`), o envelope não fica
 * pronto; uma busca `failed` nunca bloqueia o preparo manual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('anchor_scans', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('envelope_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            // Nulo enquanto o documento de um modelo HTML/DOCX ainda converte: a busca espera a
            // versão exibível e grava aqui qual versão procurou.
            $table->foreignId('document_version_id')->nullable()->constrained('document_versions')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('anchor_scans')->cascadeOnDelete();
            $table->foreignId('template_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            // manual | template | ocr
            $table->string('trigger', 16);
            // AnchorScanStatus: pending | running | done | failed
            $table->string('status', 16)->default('pending');
            $table->json('query');
            // tesseract | fake (só em testes) — só quando trigger = ocr
            $table->string('ocr_engine', 16)->nullable();
            $table->unsignedSmallInteger('pages_scanned')->default(0);
            $table->json('pages_without_text')->nullable();
            $table->unsignedSmallInteger('matches_count')->default(0);
            $table->unsignedSmallInteger('suggestions_count')->default(0);
            $table->boolean('truncated')->default(false);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('failure_code', 64)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['envelope_id', 'status'], 'anchor_scans_envelope_status_index');
            $table->index(['document_id', 'created_at'], 'anchor_scans_document_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('anchor_scans');
    }
};
