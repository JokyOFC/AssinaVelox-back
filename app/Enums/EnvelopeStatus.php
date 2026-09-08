<?php

namespace App\Enums;

enum EnvelopeStatus: string
{
    case Draft = 'draft';
    case Preparing = 'preparing';
    case Ready = 'ready';
    case InProgress = 'in_progress';
    case Finalizing = 'finalizing';
    case Completed = 'completed';
    case Refused = 'refused';
    case Expired = 'expired';
    case Canceled = 'canceled';

    /**
     * Transições permitidas (docs/arquitetura.md §3.2).
     *
     * Divergência documentada: `draft → ready` também é permitida porque a prontidão é
     * "recomputada a cada alteração" — um envelope que voltou a `draft` por edição precisa
     * poder voltar a `ready` sem reprocessar o documento.
     *
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Preparing, self::Ready, self::Canceled],
            self::Preparing => [self::Ready, self::Draft, self::Canceled],
            self::Ready => [self::Draft, self::InProgress, self::Canceled],
            self::InProgress => [self::Finalizing, self::Refused, self::Expired, self::Canceled],
            self::Finalizing => [self::Completed],
            self::Completed, self::Refused, self::Expired, self::Canceled => [],
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

    public function isDraftLike(): bool
    {
        return in_array($this, [self::Draft, self::Preparing, self::Ready], true);
    }

    public function isCancelable(): bool
    {
        return $this->canTransitionTo(self::Canceled);
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft, self::Ready => 'Rascunho',
            self::Preparing => 'Rascunho · processando',
            self::InProgress => 'Aguardando',
            self::Finalizing => 'Em andamento · finalizando',
            self::Completed => 'Assinado',
            self::Refused => 'Recusado',
            self::Expired => 'Expirado',
            self::Canceled => 'Cancelado',
        };
    }

    /**
     * Rótulo considerando o progresso: `in_progress` com pelo menos um aceite é "Em andamento".
     */
    public function labelWithProgress(int $signedCount): string
    {
        if ($this === self::InProgress && $signedCount > 0) {
            return 'Em andamento';
        }

        return $this->label();
    }

    /**
     * @return 'neutral'|'warning'|'info'|'success'|'danger'
     */
    public function badgeVariant(int $signedCount = 0): string
    {
        return match ($this) {
            self::Draft, self::Preparing, self::Ready, self::Expired, self::Canceled => 'neutral',
            self::InProgress => $signedCount > 0 ? 'info' : 'warning',
            self::Finalizing => 'info',
            self::Completed => 'success',
            self::Refused => 'danger',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return array<int, self>
     */
    public static function terminal(): array
    {
        return array_values(array_filter(self::cases(), fn (self $status) => $status->isTerminal()));
    }
}
