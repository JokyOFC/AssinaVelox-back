<?php

namespace App\Services\Risk;

/**
 * Decisão humana sobre um caso (`risk_reviews.decision`). Sempre com motivo e autor.
 */
enum RiskDecision: string
{
    case Clear = 'clear';
    case Watch = 'watch';
    case Confirm = 'confirm';

    public function label(): string
    {
        return match ($this) {
            self::Clear => 'Liberar',
            self::Watch => 'Manter em observação',
            self::Confirm => 'Confirmar restrição',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Clear => 'A organização volta ao estado normal e pode enviar documentos.',
            self::Watch => 'O envio continua liberado, mas a organização segue em observação.',
            self::Confirm => 'O envio de novos documentos continua suspenso. Documentos já enviados não mudam.',
        };
    }

    public function resultingStatus(): RiskStatus
    {
        return match ($this) {
            self::Clear => RiskStatus::Normal,
            self::Watch => RiskStatus::Watch,
            self::Confirm => RiskStatus::Restricted,
        };
    }

    public function reviewStatus(): RiskReviewStatus
    {
        return match ($this) {
            self::Clear => RiskReviewStatus::Cleared,
            self::Watch => RiskReviewStatus::Watching,
            self::Confirm => RiskReviewStatus::Confirmed,
        };
    }

    /**
     * @return list<array{value: string, label: string, description: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $decision): array => [
            'value' => $decision->value,
            'label' => $decision->label(),
            'description' => $decision->description(),
        ], self::cases());
    }
}
