<?php

namespace App\Services\Risk;

use App\Models\Organization;
use App\Services\Plans\Exceptions\SendingBlockedException;

/**
 * Única ação automática do antifraude: organização `restricted` não ENVIA novos envelopes
 * (roadmap §3.7). Chamado por App\Services\Envelopes\Sending\SendEnvelope dentro da transação
 * do envio, sob o lock do envelope — portanto vale para todos os caminhos que enviam: botão
 * "Enviar", API `POST /envelopes/{id}/send`, envio agendado e formulário público.
 *
 * Por que no serviço e não num middleware: um middleware só cobriria as rotas web; o envio
 * agendado (fila) e o formulário público não passam por rota da organização. O erro é
 * SendingBlockedException, que todos esses caminhos já tratam (flash, 409 `sending-blocked`,
 * cancelamento do agendamento com aviso ao remetente).
 *
 * NÃO bloqueia: leitura, download, assinatura e aceite de envelopes já enviados, reenvio e
 * lembretes de envelopes em andamento, criação e edição de rascunhos.
 */
final class SendingRestriction
{
    public const ERROR_CODE = 'risk_restricted';

    public function assertCanSend(int $organizationId): void
    {
        if (! RiskFeature::enabled()) {
            return;
        }

        $status = Organization::withTrashed()->whereKey($organizationId)->value('risk_status');

        if (RiskStatus::fromStored($status) === RiskStatus::Restricted) {
            throw new SendingBlockedException(self::ERROR_CODE, self::message());
        }
    }

    public static function message(): string
    {
        return 'O envio de novos documentos desta conta está suspenso até uma revisão de segurança da equipe AssinaVelox. '
            .'Documentos já enviados continuam disponíveis para leitura, assinatura e download. '
            .'Para saber o motivo e pedir a revisão, abra a página “Revisão de segurança da conta”, no aviso no topo do app, '
            .'ou escreva para '.config('assinavelox.support_email', 'suporte@assinavelox.com.br').'.';
    }
}
