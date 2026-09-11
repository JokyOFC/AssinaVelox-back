<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * K-TSA (roadmap §2.13, docs/fase-2/carimbo-e-dossie.md §3) — carimbos do tempo guardados.
 *
 * `tsa_kind` ∈ operator | commercial | icp_brasil | simulated. Regra T3: `icp_brasil` só pode
 * ser gravado por um provedor de ACT credenciada configurado de verdade — hoje nenhum; o
 * serviço que grava (App\Services\Timestamp\TimestampTokens) recusa o valor.
 *
 * O token (DER) fica no disco privado (`storage_path`), nunca no banco. `envelope_id` e
 * `dossier_export_id` são opcionais: um carimbo pode ser do manifesto de um dossiê, de uma
 * assinatura (B-T, atrás de flag) ou de uma emissão avulsa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timestamp_tokens', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('envelope_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('dossier_export_id')->nullable()->index();
            $table->unsignedBigInteger('document_version_id')->nullable()->index();
            $table->unsignedBigInteger('operator_tsa_issuance_id')->nullable()->index();
            // dossier_manifest | signature | other
            $table->string('purpose', 32);
            $table->string('tsa_kind', 16);
            $table->string('provider', 48);
            $table->string('environment', 16)->default('test');
            $table->string('status', 16)->default('granted');
            $table->string('hash_algorithm', 10);
            $table->string('imprint', 128);
            $table->string('serial', 64);
            $table->dateTime('gen_time', 3);
            $table->string('policy_oid', 128)->nullable();
            $table->string('tsa_subject', 255)->nullable();
            $table->string('tsa_cert_fingerprint', 64)->nullable();
            $table->unsignedInteger('accuracy_ms')->nullable();
            $table->string('token_sha256', 64);
            $table->string('storage_disk', 32)->nullable();
            $table->string('storage_path', 255)->nullable();
            $table->json('verification')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['tsa_cert_fingerprint', 'serial'], 'timestamp_tokens_tsa_serial_unique');
            $table->index(['envelope_id', 'purpose']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timestamp_tokens');
    }
};
