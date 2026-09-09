<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Checkpoints encadeados da trilha de auditoria (docs/seguranca-operacional.md §3).
 *
 * Cada linha é o RESUMO de um lote de `audit_events` já exportado para um arquivo no disco
 * privado. O `chain_sha256` do lote inclui o `chain_sha256` do lote anterior, então
 * reescrever um evento antigo obriga a reescrever todos os checkpoints posteriores — e
 * também os arquivos correspondentes, que ficam fora do banco.
 *
 * Honestidade sobre o alcance: esta tabela NÃO impede um administrador do banco de alterar
 * nada. Ela só torna a alteração DETECTÁVEL, e apenas se o arquivo (ou o hash do último
 * checkpoint) tiver sido copiado para fora do alcance de quem administra o banco.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_checkpoints', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();

            // Posição na cadeia (1, 2, 3, …). Um buraco aqui já é sinal de adulteração.
            $table->unsignedBigInteger('sequence')->unique();

            // Janela coberta (fechada à esquerda, aberta à direita) em `occurred_at`.
            $table->timestamp('period_start');
            $table->timestamp('period_end');

            // Faixa de ids e contagem efetivamente exportada.
            $table->unsignedBigInteger('first_event_id')->nullable();
            $table->unsignedBigInteger('last_event_id')->nullable();
            $table->unsignedInteger('event_count');

            // sha256 do conteúdo canônico do lote (linha a linha, na ordem exportada).
            $table->char('events_sha256', 64);
            // sha256 do arquivo gravado no disco (bytes exatos).
            $table->char('file_sha256', 64);
            // Elo anterior (null só no primeiro checkpoint).
            $table->char('previous_sha256', 64)->nullable();
            // sha256(previous_sha256 | events_sha256 | metadados do lote) — o elo desta linha.
            $table->char('chain_sha256', 64);

            $table->string('storage_disk', 32);
            $table->string('storage_path', 512);
            $table->unsignedBigInteger('size_bytes');

            // Quem gerou (usuário do SO / comando agendado) — informativo.
            $table->string('generated_by', 128)->nullable();

            $table->timestamp('created_at');

            $table->index(['period_start', 'period_end'], 'audit_checkpoints_period_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_checkpoints');
    }
};
