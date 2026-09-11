<?php

namespace App\Services\Envelopes\Reminders;

use App\Models\Organization;
use Carbon\CarbonInterface;

/**
 * Janela de horário em que lembretes podem sair, no FUSO DA ORGANIZAÇÃO.
 *
 * Padrão 8h–20h (`assinavelox.reminders.window_start_hour` / `window_end_hour`),
 * configurável por organização em `organizations.settings.reminders.window_start_hour` e
 * `window_end_hour`. O fim é exclusivo e aceita 24 (meia-noite): 8–20 permite 08:00 até
 * 19:59. Um lembrete que vence às 03:00 sai na primeira execução depois das 08:00.
 */
final readonly class ReminderWindow
{
    public const DEFAULT_START_HOUR = 8;

    public const DEFAULT_END_HOUR = 20;

    public function __construct(
        public int $startHour,
        public int $endHour,
        public string $timezone,
    ) {}

    public static function forOrganization(Organization $organization): self
    {
        $raw = $organization->setting('reminders');
        $raw = is_array($raw) ? $raw : [];

        $defaultStart = (int) config('assinavelox.reminders.window_start_hour', self::DEFAULT_START_HOUR);
        $defaultEnd = (int) config('assinavelox.reminders.window_end_hour', self::DEFAULT_END_HOUR);

        $start = is_numeric($raw['window_start_hour'] ?? null) ? (int) $raw['window_start_hour'] : $defaultStart;
        $end = is_numeric($raw['window_end_hour'] ?? null) ? (int) $raw['window_end_hour'] : $defaultEnd;

        $start = max(0, min(23, $start));
        $end = max(1, min(24, $end));

        if ($end <= $start) {
            [$start, $end] = [self::DEFAULT_START_HOUR, self::DEFAULT_END_HOUR];
        }

        $timezone = $organization->timezone !== '' ? $organization->timezone : Organization::DEFAULT_TIMEZONE;

        return new self($start, $end, $timezone);
    }

    public function contains(CarbonInterface $moment): bool
    {
        $hour = (int) $moment->copy()->setTimezone($this->timezone)->format('G');

        return $hour >= $this->startHour && $hour < $this->endHour;
    }

    /**
     * @return array{window_start_hour: int, window_end_hour: int}
     */
    public function toArray(): array
    {
        return [
            'window_start_hour' => $this->startHour,
            'window_end_hour' => $this->endHour,
        ];
    }
}
