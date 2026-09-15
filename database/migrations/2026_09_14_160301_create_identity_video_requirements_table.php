<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Fase 3 §3.3 (F-VIDEO) — o remetente exige de um participante um VÍDEO CURTO antes do aceite
 * (docs/fase-3/captura-de-video.md). Tabela própria, e não uma entrada nova em
 * `identity_capture_requirements.kinds`, para que a exigência de fotos (Fase 2 §2.10) continue
 * exatamente como é: nenhum código da foto lê esta tabela.
 *
 * `max_seconds` é a duração máxima pedida ao navegador e revalidada no servidor (nulo = padrão
 * de `assinavelox.capture_video.max_seconds`). A linha sai em cascata com o destinatário ou o
 * envelope. Só aditiva e compatível com MySQL 8 (sem ENUM, sem DEFAULT em JSON).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_video_requirements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('envelope_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipient_id')->unique('ivr_recipient_unique')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('max_seconds')->nullable();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['envelope_id'], 'ivr_envelope_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_video_requirements');
    }
};
