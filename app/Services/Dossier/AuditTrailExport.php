<?php

namespace App\Services\Dossier;

use App\Enums\ActorType;
use App\Models\AuditEvent;
use App\Models\Envelope;
use App\Support\Csv;

/**
 * Trilha de auditoria do envelope para o dossiê, em JSON e CSV.
 *
 * - leitura só (a trilha é append-only, T7);
 * - payload passado por {@see DossierRedaction} (segredos omitidos, IP e e-mail pela política);
 * - CSV com BOM, separador `;` e {@see Csv::row()} contra injeção de fórmula (CWE-1236);
 * - ordem estável (occurred_at, id) para o mesmo conjunto de eventos gerar os mesmos bytes.
 */
final class AuditTrailExport
{
    public const CSV_HEADER = ['sequencia', 'ocorrido_em_utc', 'tipo', 'evento', 'ator_tipo', 'ator', 'participante', 'participante_email', 'ip', 'navegador', 'detalhes'];

    /**
     * @return list<array<string, mixed>>
     */
    public function rows(Envelope $envelope, DossierRedaction $redaction): array
    {
        $organization = $envelope->organization;

        $events = AuditEvent::withoutOrganizationScope()
            ->with(['recipient', 'actorUser'])
            ->where('organization_id', $envelope->organization_id)
            ->where('envelope_id', $envelope->getKey())
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        $rows = [];
        $sequence = 0;

        foreach ($events as $event) {
            $sequence++;
            $recipient = $event->recipient;

            $rows[] = [
                'sequence' => $sequence,
                'id' => $event->ulid,
                'occurred_at' => $event->occurred_at->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
                'type' => $event->event_type->value,
                'label' => $event->event_type->label(),
                'actor_type' => $event->actor_type->value,
                'actor' => match ($event->actor_type) {
                    ActorType::User => $event->actorUser->name ?? 'Usuário',
                    ActorType::Recipient => $event->recipient->name ?? 'Participante',
                    ActorType::System => 'Sistema',
                },
                'recipient' => $recipient?->name,
                'recipient_email' => $redaction->email($recipient?->email),
                'ip' => $redaction->ip($event->ip_address, $organization),
                'user_agent' => $event->user_agent,
                'payload' => $redaction->payload($event->payload, $organization),
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function json(Envelope $envelope, array $rows, string $ipMode): string
    {
        return (string) json_encode([
            'format' => 'assinavelox-audit-trail/1',
            'envelope' => $envelope->display_code,
            'ip_policy' => $ipMode,
            'events_count' => count($rows),
            'note' => 'Trilha append-only do envelope até o momento da montagem do dossiê. Segredos (tokens, códigos, senhas) nunca são gravados na trilha; chaves suspeitas são omitidas. IP e e-mail seguem a política de exibição da organização.',
            'events' => $rows,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function csv(array $rows): string
    {
        $handle = fopen('php://temp', 'w+b');

        if ($handle === false) {
            return Csv::BOM;
        }

        fwrite($handle, Csv::BOM);
        fputcsv($handle, Csv::row(self::CSV_HEADER), ';', '"', '');

        foreach ($rows as $row) {
            fputcsv($handle, Csv::row([
                $row['sequence'],
                $row['occurred_at'],
                $row['type'],
                $row['label'],
                $row['actor_type'],
                $row['actor'],
                $row['recipient'],
                $row['recipient_email'],
                $row['ip'],
                $row['user_agent'],
                $row['payload'] === null ? '' : json_encode($row['payload'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]), ';', '"', '');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
