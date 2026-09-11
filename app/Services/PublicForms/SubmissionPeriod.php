<?php

namespace App\Services\PublicForms;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Janela do limite de envios do formulário. Janela MÓVEL (últimas 24 horas, 7 dias ou
 * 30 dias), não o dia do calendário: não há "virada" em que o limite inteiro volte de uma
 * vez, e o cálculo não depende de fuso.
 */
enum SubmissionPeriod: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';

    public function label(): string
    {
        return match ($this) {
            self::Day => 'por dia (últimas 24 horas)',
            self::Week => 'por semana (últimos 7 dias)',
            self::Month => 'por mês (últimos 30 dias)',
        };
    }

    public function windowStart(?CarbonInterface $now = null): CarbonInterface
    {
        $now = ($now ?? Carbon::now())->copy();

        return match ($this) {
            self::Day => $now->subDay(),
            self::Week => $now->subDays(7),
            self::Month => $now->subDays(30),
        };
    }
}
