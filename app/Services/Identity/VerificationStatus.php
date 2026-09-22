<?php

namespace App\Services\Identity;

use App\Integrations\Contracts\IdentityVerificationProvider;

/**
 * Estado de uma tentativa de verificação facial com documento (`identity_verifications.status`,
 * Fase 4 §4.1). É a lista do contrato do provedor ({@see IdentityVerificationProvider})
 * mais `queued`, que só existe do nosso lado: a linha foi gravada e o envio ao provedor está
 * na fila.
 *
 * Conclusivos ({@see self::isConclusive()}) nunca mudam: um webhook atrasado ou repetido não
 * vira uma reprovação em aprovação nem o contrário. `inconclusive` é falha técnica ou prazo
 * esgotado — não é resposta do provedor sobre as imagens, não consome tentativa e pode ser
 * refeita. Os rótulos dizem quem afirmou: "pelo provedor", nunca "pela plataforma".
 */
enum VerificationStatus: string
{
    case Queued = 'queued';
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Inconclusive = 'inconclusive';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Na fila para envio ao provedor',
            self::Pending => 'Em análise pelo provedor',
            self::Approved => 'Aprovada pelo provedor',
            self::Rejected => 'Reprovada pelo provedor',
            self::Inconclusive => 'Inconclusiva',
            self::Expired => 'Expirada no provedor',
        };
    }

    /** O provedor respondeu de vez (ou o prazo dele acabou): a linha não muda mais. */
    public function isConclusive(): bool
    {
        return in_array($this, [self::Approved, self::Rejected, self::Expired], true);
    }

    /** Enviada e ainda sem resposta: nenhuma outra tentativa pode ser aberta enquanto isso. */
    public function isInFlight(): bool
    {
        return in_array($this, [self::Queued, self::Pending], true);
    }

    /**
     * Consome uma das tentativas do participante? Só a inconclusiva não consome (T5: falha
     * técnica do provedor nunca pode custar ao participante).
     */
    public function countsAsAttempt(): bool
    {
        return $this !== self::Inconclusive;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Status que o provedor pode devolver (o contrato não conhece `queued`). Qualquer outra
     * coisa é lida como inconclusiva: nada que não se entenda vira aprovação (T5).
     */
    public static function fromProvider(mixed $status): self
    {
        $parsed = is_string($status) ? self::tryFrom(strtolower(trim($status))) : null;

        return $parsed === null || $parsed === self::Queued ? self::Inconclusive : $parsed;
    }
}
