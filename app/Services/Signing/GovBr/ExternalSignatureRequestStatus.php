<?php

namespace App\Services\Signing\GovBr;

/**
 * `external_signature_requests.status` (Fase 3 §3.5, P3-GOV).
 *
 * | valor       | significa                                                                   |
 * |-------------|-----------------------------------------------------------------------------|
 * | `requested` | escolha registrada; nenhuma revisão reservada agora (ou a reserva venceu)  |
 * | `pending`   | revisão reservada: o participante baixa, assina fora e devolve até `expires_at` |
 * | `completed` | devolução aceita; a revisão assinada entrou na cadeia do documento           |
 * | `expired`   | o prazo total (`window_expires_at`) terminou sem devolução aceita            |
 * | `withdrawn` | o participante desistiu                                                      |
 * | `closed`    | o documento não recebe mais assinatura (concluído, cancelado, refeito)       |
 *
 * Uma reserva vencida volta a `requested` (com `failure_code = reservation_expired`): o
 * participante pode reservar de novo enquanto o prazo total estiver aberto.
 */
enum ExternalSignatureRequestStatus: string
{
    case Requested = 'requested';
    case Pending = 'pending';
    case Completed = 'completed';
    case Expired = 'expired';
    case Withdrawn = 'withdrawn';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Aguardando o documento ficar pronto para assinar no portal',
            self::Pending => 'Versão reservada: assine no portal e devolva o arquivo',
            self::Completed => 'Arquivo assinado recebido e conferido',
            self::Expired => 'Prazo encerrado sem o arquivo assinado',
            self::Withdrawn => 'Você desistiu de assinar no portal',
            self::Closed => 'O documento não está mais recebendo assinaturas',
        };
    }

    /**
     * Pedidos que a finalização precisa considerar (esperar ou contar).
     *
     * @return list<string>
     */
    public static function activeValues(): array
    {
        return [self::Requested->value, self::Pending->value, self::Completed->value];
    }

    /**
     * Pedidos pelos quais a finalização ainda espera.
     *
     * @return list<string>
     */
    public static function pendingValues(): array
    {
        return [self::Requested->value, self::Pending->value];
    }
}
