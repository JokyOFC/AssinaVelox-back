<?php

namespace App\Services\Risk;

/**
 * Estado de risco da organização (`organizations.risk_status`, roadmap §3.7).
 *
 * Só `restricted` tem efeito, e o único efeito é suspender o ENVIO de novos envelopes. Aceites,
 * evidências, leitura, download e assinatura de envelopes já enviados nunca são tocados.
 * Valores estáveis — gravados em banco; nunca renomeie um caso.
 */
enum RiskStatus: string
{
    case Normal = 'normal';
    case Watch = 'watch';
    case Restricted = 'restricted';

    public function label(): string
    {
        return match ($this) {
            self::Normal => 'Normal',
            self::Watch => 'Em observação',
            self::Restricted => 'Envio suspenso',
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::Normal => 0,
            self::Watch => 1,
            self::Restricted => 2,
        };
    }

    public static function fromStored(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::Normal) : self::Normal;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $status): array => ['value' => $status->value, 'label' => $status->label()], self::cases());
    }
}
