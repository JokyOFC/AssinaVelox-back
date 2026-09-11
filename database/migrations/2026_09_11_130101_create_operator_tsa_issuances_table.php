<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * K-TSA (docs/fase-2/carimbo-e-dossie.md §2.3) — livro de emissões da TSA da operadora.
 *
 * O número de série do carimbo (`TSTInfo.serialNumber`) é `serial_offset + id`: o `id`
 * autoincremental é a sequência. Duas emissões simultâneas recebem ids distintos por
 * garantia do banco (InnoDB/SQLite AUTOINCREMENT), e uma linha NUNCA é apagada — nem a de
 * uma emissão que falhou —, então um serial jamais volta a ser usado. `serial` é UNIQUE
 * como segunda barreira.
 *
 * Só metadados públicos: resumo carimbado, horário, política, impressão digital do
 * certificado. Nenhuma chave, senha ou token de acesso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operator_tsa_issuances', function (Blueprint $table): void {
            $table->id();
            $table->string('serial', 64)->unique();
            // http | dossier_manifest | signature | provider
            $table->string('purpose', 32);
            // reserved | granted | rejected | failed
            $table->string('status', 16)->default('reserved');
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->string('hash_algorithm', 10)->nullable();
            $table->string('imprint', 128)->nullable();
            $table->string('policy_oid', 128)->nullable();
            $table->dateTime('gen_time', 3)->nullable();
            $table->string('token_sha256', 64)->nullable();
            $table->string('fail_info', 64)->nullable();
            $table->string('tsa_cert_fingerprint', 64)->nullable();
            $table->string('environment', 16)->default('test');
            $table->string('correlation_id', 64)->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operator_tsa_issuances');
    }
};
