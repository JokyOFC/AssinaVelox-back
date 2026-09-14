<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * P3-LTV (roadmap §3.6, docs/fase-3/longo-prazo.md). Estado TÉCNICO interno de longo prazo do
 * arquivo final. Não é o perfil anunciado: `signature_profile` continua como está (T2).
 * Aditiva: registros existentes ficam `not_applicable`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('verification_records', function (Blueprint $table) {
            // LtvStatus: not_applicable | b_t | b_lt | b_lta
            $table->string('ltv_status', 16)->default('not_applicable');
            // genTime do carimbo mais recente (de assinatura ou de documento).
            $table->timestamp('ltv_last_timestamp_at')->nullable();
            // DSS com certificados e CRL/OCSP embutidos e cobrindo as cadeias.
            $table->boolean('ltv_revocation_embedded')->default(false);
            // Vencimento do certificado da TSA do último carimbo de arquivamento.
            $table->timestamp('ltv_archive_expires_at')->nullable();
            // Próximo re-carimbo (vencimento − margem). Consultado pelo agendador.
            $table->timestamp('ltv_next_refresh_at')->nullable();
            $table->timestamp('ltv_checked_at')->nullable();

            $table->index(['ltv_status', 'ltv_next_refresh_at'], 'verification_records_ltv_refresh_index');
        });
    }

    public function down(): void
    {
        Schema::table('verification_records', function (Blueprint $table) {
            $table->dropIndex('verification_records_ltv_refresh_index');
            $table->dropColumn([
                'ltv_status',
                'ltv_last_timestamp_at',
                'ltv_revocation_embedded',
                'ltv_archive_expires_at',
                'ltv_next_refresh_at',
                'ltv_checked_at',
            ]);
        });
    }
};
