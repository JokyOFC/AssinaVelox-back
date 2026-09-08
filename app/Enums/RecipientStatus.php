<?php

namespace App\Enums;

enum RecipientStatus: string
{
    case Pending = 'pending';
    case Notified = 'notified';
    case Viewed = 'viewed';
    case Signed = 'signed';
    case Refused = 'refused';
    case Expired = 'expired';
    case Canceled = 'canceled';

    /**
     * pending → notified → viewed → signed | refused; qualquer não-terminal → canceled | expired.
     *
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Notified, self::Canceled, self::Expired],
            self::Notified => [self::Viewed, self::Canceled, self::Expired],
            self::Viewed => [self::Signed, self::Refused, self::Canceled, self::Expired],
            self::Signed, self::Refused, self::Expired, self::Canceled => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    public function isPendingSignature(): bool
    {
        return in_array($this, [self::Pending, self::Notified, self::Viewed], true);
    }

    /**
     * Rótulo do BADGE (ROUTES_AND_PAGES §6.2 / DESIGN_SYSTEM §5.2): todo signatário
     * ainda não assinado é "Pendente" (âmbar); o detalhe vai em note().
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending, self::Notified, self::Viewed => 'Pendente',
            self::Signed => 'Assinado',
            self::Refused => 'Recusado',
            self::Expired => 'Expirado',
            self::Canceled => 'Cancelado',
        };
    }

    /**
     * Nota curta exibida abaixo do badge (ROUTES_AND_PAGES §6.2). Espelha
     * `recipientStatusNotes` em resources/js/lib/labels.ts.
     */
    public function note(): string
    {
        return match ($this) {
            self::Pending => 'Aguarda a vez',
            self::Notified => 'Enviado · não visualizou',
            self::Viewed => 'Visualizou',
            self::Signed => 'Assinado',
            self::Refused => 'Recusou',
            self::Expired => 'Prazo encerrado',
            self::Canceled => 'Documento cancelado',
        };
    }

    /**
     * @return 'neutral'|'warning'|'info'|'success'|'danger'
     */
    public function badgeVariant(): string
    {
        return match ($this) {
            self::Pending, self::Notified, self::Viewed => 'warning',
            self::Signed => 'success',
            self::Refused => 'danger',
            self::Expired, self::Canceled => 'neutral',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
