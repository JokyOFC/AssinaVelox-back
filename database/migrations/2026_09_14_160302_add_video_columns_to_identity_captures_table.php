<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Fase 3 §3.3 (F-VIDEO) — `identity_captures.kind` ganha o valor `video` (a coluna já é texto
 * livre de 24 caracteres; nada muda nela). Estas colunas, todas NULÁVEIS, só são preenchidas
 * para vídeo; as fotos continuam gravando exatamente o que gravavam.
 *
 * - `container`: `webm` | `matroska` | `mp4`, lido da assinatura de bytes (EBML / caixa `ftyp`).
 * - `duration_ms`: duração lida do próprio arquivo quando o contêiner a declara (nulo quando não
 *   é legível — o WebM do MediaRecorder costuma não trazer). `declared_duration_ms`: a que o
 *   navegador informou (não verificada).
 * - `consented_at` / `consent_version`: o participante marcou o consentimento antes de ligar a
 *   câmera; a versão é o SHA-256 do texto exibido.
 *
 * O vídeo é guardado como veio (sem transcodificar), cifrado, no disco privado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('identity_captures', function (Blueprint $table): void {
            $table->string('container', 16)->nullable()->after('mime_type');
            $table->unsignedInteger('duration_ms')->nullable()->after('size_bytes');
            $table->unsignedInteger('declared_duration_ms')->nullable()->after('duration_ms');
            $table->timestamp('consented_at')->nullable()->after('source');
            $table->string('consent_version', 64)->nullable()->after('consented_at');
        });
    }

    public function down(): void
    {
        Schema::table('identity_captures', function (Blueprint $table): void {
            $table->dropColumn(['container', 'duration_ms', 'declared_duration_ms', 'consented_at', 'consent_version']);
        });
    }
};
