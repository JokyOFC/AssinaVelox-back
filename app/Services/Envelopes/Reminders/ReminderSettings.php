<?php

namespace App\Services\Envelopes\Reminders;

use App\Models\Envelope;
use App\Models\Organization;

/**
 * Cadência dos lembretes automáticos: `{enabled, first_after_days, interval_days, max_count}`.
 *
 * Vive em dois lugares, com o mesmo formato:
 *
 *  - `organizations.settings.reminders` — o PADRÃO da organização (Configurações › Padrões
 *    de assinatura). Ali a chave também guarda a janela de horário (ver ReminderWindow);
 *  - `envelopes.settings.reminders` — a cadência DESTE envelope. É gravada pelo wizard
 *    (`PUT documentos/{envelope}/lembretes`) ou, se o wizard não disse nada, copiada do
 *    padrão da organização no momento do envio (SendEnvelope). Mudar o padrão depois não
 *    altera envelopes já enviados.
 *
 * Todo valor lido passa por `clamp()`: um JSON adulterado no banco nunca produz "lembrete a
 * cada 0 dias" ou "999 lembretes".
 */
final readonly class ReminderSettings
{
    /** @var array<string, array{min: int, max: int}> */
    public const LIMITS = [
        'first_after_days' => ['min' => 1, 'max' => 30],
        'interval_days' => ['min' => 1, 'max' => 30],
        'max_count' => ['min' => 1, 'max' => 10],
    ];

    /** @var array{enabled: bool, first_after_days: int, interval_days: int, max_count: int} */
    public const DEFAULTS = [
        'enabled' => false,
        'first_after_days' => 2,
        'interval_days' => 2,
        'max_count' => 3,
    ];

    public function __construct(
        public bool $enabled,
        public int $firstAfterDays,
        public int $intervalDays,
        public int $maxCount,
    ) {}

    public static function defaults(): self
    {
        return self::fromArray(null);
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function fromArray(?array $data): self
    {
        $data ??= [];

        return new self(
            filter_var($data['enabled'] ?? self::DEFAULTS['enabled'], FILTER_VALIDATE_BOOLEAN),
            self::clamp('first_after_days', $data['first_after_days'] ?? self::DEFAULTS['first_after_days']),
            self::clamp('interval_days', $data['interval_days'] ?? self::DEFAULTS['interval_days']),
            self::clamp('max_count', $data['max_count'] ?? self::DEFAULTS['max_count']),
        );
    }

    public static function forOrganization(Organization $organization): self
    {
        $raw = $organization->setting('reminders');

        return self::fromArray(is_array($raw) ? $raw : null);
    }

    /**
     * Cadência gravada no envelope. `null` = o envelope não tem lembretes (foi enviado com a
     * flag desligada ou antes da Fase 2).
     */
    public static function forEnvelope(Envelope $envelope): ?self
    {
        $raw = $envelope->setting('reminders');

        return is_array($raw) ? self::fromArray($raw) : null;
    }

    /**
     * Dias de espera antes do próximo lembrete, contados do último contato com o destinatário.
     */
    public function delayDaysFor(int $alreadySent): int
    {
        return $alreadySent === 0 ? $this->firstAfterDays : $this->intervalDays;
    }

    /**
     * Texto curto para a interface ("A cada 2 dias · até 3 lembretes").
     */
    public function summary(): string
    {
        if (! $this->enabled) {
            return 'Desativados';
        }

        $interval = $this->intervalDays === 1 ? 'A cada 1 dia' : "A cada {$this->intervalDays} dias";
        $max = $this->maxCount === 1 ? 'até 1 lembrete' : "até {$this->maxCount} lembretes";

        return "{$interval} · {$max}";
    }

    /**
     * @return array{enabled: bool, first_after_days: int, interval_days: int, max_count: int}
     */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'first_after_days' => $this->firstAfterDays,
            'interval_days' => $this->intervalDays,
            'max_count' => $this->maxCount,
        ];
    }

    /**
     * Regras de validação compartilhadas (Configurações e endpoint por envelope).
     *
     * @return array<string, list<string>>
     */
    public static function rules(string $prefix = ''): array
    {
        $rules = [$prefix.'enabled' => ['required', 'boolean']];

        foreach (self::LIMITS as $key => $limit) {
            $rules[$prefix.$key] = ['required', 'integer', 'min:'.$limit['min'], 'max:'.$limit['max']];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public static function attributes(string $prefix = ''): array
    {
        return [
            $prefix.'enabled' => 'lembretes automáticos',
            $prefix.'first_after_days' => 'primeiro lembrete após',
            $prefix.'interval_days' => 'intervalo entre lembretes',
            $prefix.'max_count' => 'máximo de lembretes',
        ];
    }

    private static function clamp(string $key, mixed $value): int
    {
        $limit = self::LIMITS[$key];
        $int = is_numeric($value) ? (int) $value : self::DEFAULTS[$key];

        return max($limit['min'], min($limit['max'], $int));
    }
}
