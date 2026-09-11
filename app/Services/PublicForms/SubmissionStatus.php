<?php

namespace App\Services\PublicForms;

/**
 * Ciclo de um envio (docs/fase-2/formulario-publico.md §5).
 *
 *   pending_confirmation ──link confirmado──▶ processing ──▶ pending_review ──aprovar──▶ sent
 *            │                                   │                 │
 *            │ (link vence: a limpeza apaga)     ├──auto_send──▶ sent
 *            ▼                                   └──falha──▶ failed        └──recusar──▶ rejected
 *        (removido)
 *
 * Nenhum envelope e nenhum consumo de cota existem antes de `processing`.
 */
enum SubmissionStatus: string
{
    case PendingConfirmation = 'pending_confirmation';
    case Processing = 'processing';
    case PendingReview = 'pending_review';
    case Sent = 'sent';
    case Rejected = 'rejected';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::PendingConfirmation => 'Aguardando confirmação do e-mail',
            self::Processing => 'Gerando documento',
            self::PendingReview => 'Aguardando revisão',
            self::Sent => 'Enviado para assinatura',
            self::Rejected => 'Recusado pela equipe',
            self::Failed => 'Falha ao gerar o documento',
        };
    }
}
