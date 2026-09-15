<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Fase 3 §3.3 (F-FLOW) — colunas aditivas em `recipients`, todas nulas por padrão (com as
 * flags desligadas nada é gravado nelas).
 *
 * - `signing_step_index`: etapa do participante quando o envelope usa etapas.
 * - `status_reason`: motivo curto do estado (`step_skipped` para quem ficou numa etapa pulada,
 *   `delegated` para quem delegou). O status continua sendo a máquina de RecipientStatus.
 * - `delegated_from_recipient_id`: o delegado aponta para quem delegou. Sem FK de propósito:
 *   é autorreferência na mesma tabela e a trilha completa (com FKs) está em `delegations`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipients', function (Blueprint $table): void {
            $table->unsignedSmallInteger('signing_step_index')->nullable();
            $table->string('status_reason', 32)->nullable();
            $table->unsignedBigInteger('delegated_from_recipient_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('recipients', function (Blueprint $table): void {
            $table->dropIndex(['delegated_from_recipient_id']);
            $table->dropColumn(['signing_step_index', 'status_reason', 'delegated_from_recipient_id']);
        });
    }
};
