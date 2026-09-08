<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificate_references', function (Blueprint $table) {
            $table->id();
            // Nulo = certificado da operadora (AssinaVelox). Por organização é extension point (Fase 2).
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('kind', 32)->default('company_a1'); // CertificateKind
            $table->string('environment', 32)->default('test'); // CertificateEnvironment
            // Nome da variável de ambiente/arquivo; nunca o segredo em si.
            $table->string('secret_ref', 191);
            $table->string('subject', 255)->nullable();
            $table->string('issuer', 255)->nullable();
            $table->string('serial_number', 128)->nullable();
            $table->char('fingerprint_sha256', 64)->nullable();
            $table->timestamp('not_before')->nullable();
            $table->timestamp('not_after')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();

            $table->index(['organization_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificate_references');
    }
};
