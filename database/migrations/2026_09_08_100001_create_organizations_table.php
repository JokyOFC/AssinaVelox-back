<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->string('name', 160);
            $table->string('legal_name', 200)->nullable();
            // CNPJ/CPF criptografado (cast "encrypted"); nunca indexado.
            $table->text('tax_id')->nullable();
            $table->string('timezone', 64)->default('America/Sao_Paulo');
            $table->string('locale', 10)->default('pt_BR');
            // default_expiration_days, otp_required, evidence_show_ip, refusal_policy, max_resends
            $table->json('settings')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
