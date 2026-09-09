<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Momento em que os bytes do documento foram entregues A ESTA sessão de assinatura.
 *
 * A declaração que o signatário assina afirma "Li integralmente o documento […], cujo
 * conteúdo apresentado nesta tela corresponde ao resumo SHA-256 …". Até esta correção nada
 * no fluxo registrava que o documento chegou a ser apresentado: a trilha ia de
 * `invitation.opened` — que a própria página de evidências rotula "registra o acesso ao
 * link, não comprova leitura" — direto para `session.started` e `acceptance.recorded`, e o
 * `sign.complete` era aceito sem nenhuma passagem por `sign.document`.
 *
 * A marca fica na sessão (e não só na trilha) porque é sob ela que o aceite é autorizado:
 * a verificação no servidor é uma leitura de coluna, exata e barata. O evento
 * `document.presented` continua indo para `audit_events`, que é o que a página de
 * evidências mostra.
 *
 * O que este registro é: prova de que o servidor ENTREGOU o arquivo àquela sessão. O que
 * ele não é: prova de leitura. A interface usa o mesmo cuidado de linguagem do
 * "não comprova leitura".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signing_sessions', function (Blueprint $table): void {
            $table->timestamp('document_presented_at')->nullable()->after('authenticated_at');
        });
    }

    public function down(): void
    {
        Schema::table('signing_sessions', function (Blueprint $table): void {
            $table->dropColumn('document_presented_at');
        });
    }
};
