<?php

namespace App\Enums;

/**
 * `pending_external_signatures.status` — Fase 3 §3.4 (P3-EXT).
 *
 * | status       | significa                                                                   |
 * |--------------|-----------------------------------------------------------------------------|
 * | `pending`    | revisão reservada, digest entregue, aguardando a assinatura (TTL curto)     |
 * | `embedding`  | assinatura recebida; embutindo sob o lock do envelope (consumo único)       |
 * | `applied`    | revisão assinada gravada (document_version + participant_signature)         |
 * | `rejected`   | a assinatura recebida foi recusada (inválida, de outra revisão, outro cert.) |
 * | `expired`    | o prazo terminou antes da assinatura                                        |
 * | `superseded` | o mesmo participante preparou de novo o mesmo documento                     |
 * | `discarded`  | desistência, envelope encerrado ou base refeita                             |
 *
 * Só `pending` e `embedding` seguram a reserva do documento.
 */
enum PendingExternalSignatureStatus: string
{
    case Pending = 'pending';
    case Embedding = 'embedding';
    case Applied = 'applied';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Superseded = 'superseded';
    case Discarded = 'discarded';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Aguardando a assinatura no componente',
            self::Embedding => 'Assinatura recebida; incorporando ao documento',
            self::Applied => 'Assinatura incorporada ao documento',
            self::Rejected => 'Assinatura recusada',
            self::Expired => 'Prazo da preparação encerrado',
            self::Superseded => 'Substituída por uma nova preparação',
            self::Discarded => 'Preparação descartada',
        };
    }

    public function holdsReservation(): bool
    {
        return $this === self::Pending || $this === self::Embedding;
    }
}
