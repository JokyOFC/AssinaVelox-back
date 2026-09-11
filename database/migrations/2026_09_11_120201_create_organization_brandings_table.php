<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca da organização — Fase 2, roadmap §2.8 (docs/fase-2/branding.md).
 *
 * Uma linha por organização (UNIQUE). Sem linha, nada muda: e-mails, página pública e
 * evidências continuam exatamente como na Fase 1, mesmo com a flag `branding` ligada.
 *
 * - `logo_path`: caminho no disco privado `documents`, montado só com identificadores
 *   opacos (`orgs/{org_ulid}/branding/logo-{token}.png`). O arquivo é SEMPRE o PNG
 *   reprocessado com GD (sem metadados), nunca o enviado.
 * - `logo_token`: 40 hex aleatórios, trocados a cada envio de logo. É o único
 *   identificador na URL pública do logo (`marca/logo.png?v=`), igual para todos os
 *   destinatários — não identifica pessoa nem mensagem.
 * - Cores em `#RRGGBB`, já validadas por contraste (App\Services\Branding\ColorContrast).
 * - `reply_to_email`: Reply-To dos e-mails ao participante. `sender_email`: remetente
 *   próprio desejado — só é usado quando o domínio estiver verificado (contrato
 *   App\Services\Branding\Contracts\VerifiedSenderDomains); até lá é só registro.
 *
 * MySQL: sem ENUM, sem DEFAULT em TEXT/JSON, nomes de índice abaixo de 64 caracteres.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_brandings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('display_name', 80)->nullable();
            $table->char('primary_color', 7)->nullable();
            $table->char('accent_color', 7)->nullable();
            $table->string('logo_path', 255)->nullable();
            $table->char('logo_token', 40)->nullable();
            $table->unsignedSmallInteger('logo_width')->nullable();
            $table->unsignedSmallInteger('logo_height')->nullable();
            $table->unsignedInteger('logo_bytes')->nullable();
            $table->char('logo_sha256', 64)->nullable();
            $table->string('reply_to_email', 191)->nullable();
            $table->string('sender_email', 191)->nullable();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('organization_id', 'org_brandings_org_unique');
            $table->unique('logo_token', 'org_brandings_logo_token_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_brandings');
    }
};
