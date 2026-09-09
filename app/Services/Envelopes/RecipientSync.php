<?php

namespace App\Services\Envelopes;

use App\Enums\AuditEventType;
use App\Enums\RecipientStatus;
use App\Enums\SigningOrder;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Models\RecipientAccessLink;
use App\Services\Envelopes\Contracts\RotatesInvitations;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Substitui a lista completa de destinatários do envelope (PUT envelopes.recipients.sync,
 * ROUTES §2.6 passo 2), preservando os `id` (ULID) enviados.
 *
 * Só roda com o envelope em `draft`, `preparing` ou `ready`: depois do envio a lista está
 * congelada e a única edição possível é `updatePending()` (nome/e-mail).
 */
final class RecipientSync
{
    /**
     * @param  array{signing_order: string, recipients: list<array<string, mixed>>}  $data
     * @return array{created: int, updated: int, removed: int}
     */
    public function handle(Envelope $envelope, array $data): array
    {
        $signingOrder = SigningOrder::from($data['signing_order']);
        $incoming = $data['recipients'];

        // A ordem de apresentação é a do array recebido; `order` só desempata quando vem.
        usort($incoming, function (array $a, array $b): int {
            $left = isset($a['order']) ? (int) $a['order'] : PHP_INT_MAX;
            $right = isset($b['order']) ? (int) $b['order'] : PHP_INT_MAX;

            return $left <=> $right;
        });

        $result = DB::transaction(function () use ($envelope, $incoming, $signingOrder): array {
            // Sob lock, dentro da transação: `signature_acceptances.recipient_id` é
            // ON DELETE CASCADE, então remover um destinatário de envelope enviado
            // destruiria o aceite de quem já assinou.
            PreparationGuard::lockForPreparation($envelope, 'recipients');

            $existing = $envelope->recipients()->get()->keyBy('ulid');

            /** @var array<int, Recipient> $kept */
            $kept = [];

            foreach ($incoming as $position => $row) {
                $ulid = isset($row['id']) && is_string($row['id']) && $row['id'] !== '' ? $row['id'] : null;

                if ($ulid === null) {
                    continue;
                }

                $recipient = $existing->get($ulid);

                if ($recipient === null) {
                    throw ValidationException::withMessages([
                        "recipients.{$position}.id" => 'Este signatário não pertence ao documento.',
                    ]);
                }

                $kept[$position] = $recipient;
            }

            $keptIds = array_map(fn (Recipient $recipient): int => $recipient->getKey(), $kept);

            // 1) Remove quem saiu da lista (signing_fields.recipient_id é ON DELETE
            //    CASCADE: os campos do signatário removido saem junto).
            $removed = $envelope->recipients()
                ->whereNotIn('id', $keptIds === [] ? [0] : $keptIds)
                ->get();

            foreach ($removed as $recipient) {
                $recipient->delete();
            }

            // 2) Solta os e-mails que vão mudar. Sem isso, trocar dois e-mails entre dois
            //    signatários bateria em UNIQUE(envelope_id, email) no meio do caminho.
            foreach ($kept as $position => $recipient) {
                if ($recipient->email !== self::normalizeEmail($incoming[$position]['email'])) {
                    $recipient->forceFill(['email' => self::placeholderEmail($recipient)])->save();
                }
            }

            $created = 0;
            $updated = 0;

            foreach ($incoming as $position => $row) {
                $recipient = $kept[$position] ?? null;

                // Sequencial: order_index = posição na lista. Paralelo: todos na mesma vez
                // (arquitetura §3.2 — no sequencial só é notificado quem está em
                // `envelope.current_order`; no paralelo todos entram de uma vez).
                $orderIndex = $signingOrder === SigningOrder::Sequential ? $position + 1 : 1;

                $attributes = [
                    'name' => trim($row['name']),
                    'email' => self::normalizeEmail($row['email']),
                    'role_label' => self::roleLabel($row['role'] ?? null),
                    'order_index' => $orderIndex,
                ];

                if ($recipient === null) {
                    $recipient = new Recipient;
                    $recipient->envelope_id = $envelope->getKey();
                    $recipient->organization_id = $envelope->organization_id;
                    $recipient->status = RecipientStatus::Pending;
                    $created++;
                } else {
                    $updated++;
                }

                $recipient->forceFill($attributes)->save();
            }

            $envelope->forceFill([
                'signing_order' => $signingOrder,
                'current_order' => 1,
            ])->save();

            return ['created' => $created, 'updated' => $updated, 'removed' => $removed->count()];
        });

        $envelope->unsetRelation('recipients')->unsetRelation('fields');

        EnvelopeAudit::record($envelope, AuditEventType::RecipientsUpdated, [
            'signing_order' => $signingOrder->value,
            'count' => count($incoming),
            'created' => $result['created'],
            'updated' => $result['updated'],
            'removed' => $result['removed'],
        ]);

        EnvelopeReadiness::refresh($envelope);

        return $result;
    }

    /**
     * Reaplica `recipients.order_index` a partir do `signing_order` gravado no envelope.
     *
     * Existe porque a ordem de assinatura tem DOIS donos no wizard: o passo 2 (este
     * serviço, `handle()`) e o autosave do passo 1, que grava só `envelopes.signing_order`
     * — e que, sozinho, deixava um envelope `sequential` com todos os signatários na mesma
     * vez (ou um `parallel` com as vezes de um sequencial, em que ninguém além do primeiro
     * receberia convite). Quem muda a ordem por fora do sync chama isto e a invariante
     * volta a valer, com a MESMA regra de `handle()`: sequencial → 1..N na ordem da lista;
     * paralelo → todos em 1.
     *
     * A ordem da lista é `order_index` e, para desempatar, o `id` — a ordem em que os
     * signatários foram criados, que é a ordem que o wizard mostra.
     *
     * @return int quantidade de destinatários cuja vez mudou
     */
    public static function reindex(Envelope $envelope): int
    {
        $changed = DB::transaction(function () use ($envelope): int {
            $locked = PreparationGuard::lockForPreparation($envelope, 'signing_order');

            /** @var Collection<int, Recipient> $recipients */
            $recipients = Recipient::withoutOrganizationScope()
                ->where('envelope_id', $locked->getKey())
                ->orderBy('order_index')
                ->orderBy('id')
                ->get();

            $changed = 0;

            foreach ($recipients->values() as $position => $recipient) {
                $orderIndex = $locked->signing_order === SigningOrder::Sequential ? $position + 1 : 1;

                if ((int) $recipient->order_index !== $orderIndex) {
                    $recipient->forceFill(['order_index' => $orderIndex])->save();
                    $changed++;
                }
            }

            // O envelope ainda está em preparo: a vez corrente é sempre a primeira.
            if ((int) $locked->current_order !== 1) {
                $locked->forceFill(['current_order' => 1])->save();
            }

            return $changed;
        });

        $envelope->unsetRelation('recipients');

        return $changed;
    }

    /**
     * Edição de um destinatário ainda pendente depois do envio (PATCH
     * envelopes.recipients.update): só nome e e-mail, só em `pending|notified|viewed`.
     *
     * Trocar o e-mail invalida o convite: os links ativos são REVOGADOS aqui mesmo. A
     * emissão do novo link e o reenvio ficam com a implementação de `RotatesInvitations`
     * (envio); sem ela, `rotated` volta false e o controller orienta reenviar à mão.
     *
     * @return array{rotated: bool, email_changed: bool}
     */
    public function updatePending(Recipient $recipient, string $name, string $email): array
    {
        $envelope = $recipient->envelope;
        $email = self::normalizeEmail($email);

        if (! $recipient->status->isPendingSignature()) {
            throw ValidationException::withMessages([
                'name' => 'Este signatário já concluiu a etapa e não pode mais ser editado.',
            ]);
        }

        $duplicate = Recipient::query()
            ->where('envelope_id', $recipient->envelope_id)
            ->where('email', $email)
            ->whereKeyNot($recipient->getKey())
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'email' => 'Este e-mail já está em outro signatário deste documento.',
            ]);
        }

        $emailChanged = $recipient->email !== $email;

        $recipient->forceFill(['name' => trim($name), 'email' => $email])->save();

        $rotated = false;

        if ($emailChanged) {
            RecipientAccessLink::query()
                ->where('recipient_id', $recipient->getKey())
                ->whereNull('revoked_at')
                ->update(['revoked_at' => Carbon::now()]);

            if (app()->bound(RotatesInvitations::class)) {
                // `rotate()` devolve false quando não há convite a emitir (envelope ainda em
                // preparo, terminal, ou signatário que aguarda a vez): a interface precisa
                // pedir o reenvio manual em vez de afirmar que o convite saiu.
                $rotated = app(RotatesInvitations::class)->rotate($recipient, 'recipient_updated');
            }
        }

        EnvelopeAudit::record($envelope, AuditEventType::RecipientsUpdated, [
            'recipient' => $recipient->ulid,
            'email_changed' => $emailChanged,
            'links_revoked' => $emailChanged,
            'invitation_rotated' => $rotated,
        ], $recipient);

        return ['rotated' => $rotated, 'email_changed' => $emailChanged];
    }

    /**
     * E-mail comparável: sem espaços e em minúsculas (o UNIQUE(envelope_id, email) do
     * MySQL é case-insensitive, o do SQLite dos testes não).
     */
    private static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * E-mail temporário, único por linha, usado só dentro da transação do sync.
     */
    private static function placeholderEmail(Recipient $recipient): string
    {
        return 'sync-'.$recipient->getKey().'-'.Str::lower(Str::random(8)).'@invalid.assinavelox';
    }

    private static function roleLabel(?string $role): ?string
    {
        $role = trim((string) $role);

        return $role === '' ? null : mb_substr($role, 0, 40);
    }
}
