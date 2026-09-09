<?php

namespace App\Services\Signing;

use App\Enums\AuditEventType;
use App\Enums\RecipientStatus;
use Illuminate\Support\Carbon;

/**
 * Registro da **abertura detectada** do convite (ROUTES §3.2 passo 1).
 *
 * ## O que um GET pode e não pode fazer
 *
 * Um GET nunca consome o convite, nunca cria aceite e nunca invalida o link. A razão é
 * concreta: filtros de segurança de e-mail, pré-visualizadores de link e antivírus corporativos
 * abrem todas as URLs de uma mensagem, automaticamente, antes de a pessoa ver o e-mail. Se
 * abrir o link consumisse o convite, um scanner deixaria o signatário sem acesso; se criasse
 * qualquer registro de vontade, um robô teria "assinado".
 *
 * ## Por que "abertura detectada" e não "leitura"
 *
 * O que o servidor sabe é que **alguém** pediu esta URL. Não sabe se era a pessoa, se ela
 * estava na frente da tela, nem se leu qualquer linha do documento. Por isso o evento se chama
 * `invitation.opened`, o status vira `viewed` com o rótulo "Visualizou" e nenhuma tela ou
 * página de evidências afirma "leu". A prova de leitura não existe; a prova de aceite existe,
 * e é o aceite.
 *
 * O registro é feito **uma vez**: a primeira abertura. Recarregar a página cem vezes não
 * enche a trilha nem infla o contador do link.
 */
final class InvitationOpens
{
    public function record(SignerContext $context): void
    {
        if (! $context->isActive()) {
            return;
        }

        if ($context->recipient->status !== RecipientStatus::Notified) {
            return;
        }

        $now = Carbon::now();

        $context->recipient->transitionTo(RecipientStatus::Viewed);
        $context->recipient->save();

        // `use_count`/`last_used_at` medem aberturas, não consumo: o link continua válido.
        $context->link->forceFill([
            'last_used_at' => $now,
            'use_count' => (int) $context->link->use_count + 1,
        ])->save();

        SignerAudit::record($context->envelope, $context->recipient, AuditEventType::InvitationOpened, [
            'link_ulid' => $context->link->ulid,
            // Deixa explícito na própria trilha o que o evento significa.
            'meaning' => 'opened_detected_not_read',
        ]);
    }
}
