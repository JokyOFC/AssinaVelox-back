<?php

namespace App\Services\Envelopes\Reminders;

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Acesso à tabela `envelope_reminders` (sem model: a tabela é interna deste módulo).
 *
 * Toda consulta é por `recipient_id`/`envelope_id` resolvidos no servidor — nunca por id
 * vindo do navegador —, então o isolamento entre organizações vem de quem chama.
 */
final class ReminderLog
{
    public const TABLE = 'envelope_reminders';

    public const SENT = 'sent';

    public const SKIPPED = 'skipped';

    /**
     * Estatística por destinatário do envelope.
     *
     * @return array<int, array{total: int, sent: int, last_sent_at: string|null}>
     */
    public function statsForEnvelope(int $envelopeId): array
    {
        $rows = DB::table(self::TABLE)
            ->where('envelope_id', $envelopeId)
            ->groupBy('recipient_id')
            ->selectRaw('recipient_id, COUNT(*) AS total, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS sent_total, MAX(sent_at) AS last_sent_at', [self::SENT])
            ->get();

        $stats = [];

        foreach ($rows as $row) {
            $stats[(int) $row->recipient_id] = [
                'total' => (int) $row->total,
                'sent' => (int) $row->sent_total,
                'last_sent_at' => $row->last_sent_at !== null ? (string) $row->last_sent_at : null,
            ];
        }

        return $stats;
    }

    /**
     * @return array{total: int, sent: int, last_sent_at: string|null}
     */
    public function statsForRecipient(int $recipientId): array
    {
        $row = DB::table(self::TABLE)
            ->where('recipient_id', $recipientId)
            ->selectRaw('COUNT(*) AS total, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS sent_total, MAX(sent_at) AS last_sent_at', [self::SENT])
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'sent' => (int) ($row->sent_total ?? 0),
            'last_sent_at' => isset($row->last_sent_at) ? (string) $row->last_sent_at : null,
        ];
    }

    public function exists(int $recipientId, int $sequence): bool
    {
        return DB::table(self::TABLE)
            ->where('recipient_id', $recipientId)
            ->where('sequence', $sequence)
            ->exists();
    }

    /**
     * Grava a linha. Devolve `false` quando a chave (destinatário, número) já existe — outra
     * execução chegou antes; quem chama desiste sem efeito colateral.
     *
     * @param  array{organization_id: int, envelope_id: int, recipient_id: int, sequence: int, status: string, reason?: string|null, correlation_id?: string|null, access_link_id?: int|null, sent_at?: Carbon|null}  $row
     */
    public function record(array $row): bool
    {
        $now = Carbon::now();

        try {
            DB::table(self::TABLE)->insert([
                'organization_id' => $row['organization_id'],
                'envelope_id' => $row['envelope_id'],
                'recipient_id' => $row['recipient_id'],
                'sequence' => $row['sequence'],
                'status' => $row['status'],
                'reason' => $row['reason'] ?? null,
                'correlation_id' => $row['correlation_id'] ?? null,
                'access_link_id' => $row['access_link_id'] ?? null,
                'sent_at' => $row['sent_at'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (QueryException $exception) {
            if ($this->exists($row['recipient_id'], $row['sequence'])) {
                return false;
            }

            throw $exception;
        }

        return true;
    }
}
