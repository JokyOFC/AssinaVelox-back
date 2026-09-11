<?php

namespace App\Services\Envelopes\Reminders;

use App\Models\Envelope;
use App\Models\Recipient;
use App\Services\Envelopes\Sending\ScheduledSend;
use App\Support\Timezones;

/**
 * Props de lembretes e envio agendado para as páginas do envelope (wizard, detalhe).
 *
 * CONTRATO para o front (docs/fase-2/lembretes-e-agendamento.md §7). Quem monta a página
 * (EnvelopeController::edit/show) mescla `reminders` com o retorno de `forEnvelope()`.
 */
class ReminderProps
{
    public function __construct(
        private readonly RemindersFeature $feature,
        private readonly ReminderLog $log,
    ) {}

    /**
     * @return array{
     *     available: bool,
     *     settings: array{enabled: bool, first_after_days: int, interval_days: int, max_count: int},
     *     is_default: bool,
     *     summary: string,
     *     limits: array<string, array{min: int, max: int}>,
     *     window: array{window_start_hour: int, window_end_hour: int},
     *     timezone: string,
     *     timezone_label: string,
     *     recipients: array<string, array{sent: int, last_sent_at: string|null}>,
     *     scheduled_send: array{at: string, at_local: string, input_value: string, timezone: string, timezone_label: string}|null,
     *     scheduled_send_limits: array{min_lead_minutes: int, max_days: int}
     * }
     */
    public function forEnvelope(Envelope $envelope): array
    {
        $organization = $envelope->organization;
        $available = $this->feature->enabledFor($organization);
        $own = ReminderSettings::forEnvelope($envelope);

        // Sem configuração própria: no rascunho vale o padrão da organização (é o que será
        // copiado no envio); depois de enviado, "sem configuração" significa sem lembretes.
        $settings = $own ?? ($envelope->status->isDraftLike()
            ? ReminderSettings::forOrganization($organization)
            : ReminderSettings::fromArray(['enabled' => false]));

        $recipients = [];

        if ($available) {
            $stats = $this->log->statsForEnvelope((int) $envelope->getKey());
            $ulids = Recipient::withoutOrganizationScope()
                ->where('envelope_id', $envelope->getKey())
                ->pluck('ulid', 'id');

            foreach ($stats as $recipientId => $row) {
                $ulid = $ulids->get($recipientId);

                if (is_string($ulid)) {
                    $recipients[$ulid] = [
                        'sent' => $row['sent'],
                        'last_sent_at' => ScheduledSend::parse($row['last_sent_at'])?->toIso8601String(),
                    ];
                }
            }
        }

        return [
            'available' => $available,
            'settings' => $settings->toArray(),
            'is_default' => $own === null,
            'summary' => $settings->summary(),
            'limits' => ReminderSettings::LIMITS,
            'window' => ReminderWindow::forOrganization($organization)->toArray(),
            'timezone' => $organization->timezone,
            'timezone_label' => Timezones::humanLabel($organization->timezone),
            'recipients' => $recipients,
            'scheduled_send' => $available ? ScheduledSend::present($envelope) : null,
            'scheduled_send_limits' => ScheduledSend::limits(),
        ];
    }
}
