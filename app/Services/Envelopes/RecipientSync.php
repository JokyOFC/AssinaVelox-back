<?php

namespace App\Services\Envelopes;

use App\Enums\AuditEventType;
use App\Enums\RecipientRole;
use App\Enums\RecipientStatus;
use App\Enums\SigningOrder;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Models\RecipientAccessLink;
use App\Services\Envelopes\Contracts\RotatesInvitations;
use App\Services\Envelopes\Steps\StepTurns;
use App\Services\Signing\Channels\RecipientChannels;
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

        // Papel de domínio de cada linha (Fase 2 §2.4), decidido ANTES de tocar no banco.
        $roles = self::resolveRoles($envelope, $incoming);

        // Fase 2 §2.9 (C-CAN): telefone E.164, canal, método de autenticação e PIN de cada
        // linha. Com as flags desligadas devolve os valores já gravados (nada muda).
        $channels = app(RecipientChannels::class)->resolve($envelope, $incoming);

        /** @var array<int, Recipient> $saved */
        $saved = [];

        $result = DB::transaction(function () use ($envelope, $incoming, $signingOrder, $roles, $channels, &$saved): array {
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
            $turn = 0;

            foreach ($incoming as $position => $row) {
                $recipient = $kept[$position] ?? null;
                $role = $roles[$position];

                // Sequencial: order_index = posição na lista. Paralelo: todos na mesma vez
                // (arquitetura §3.2 — no sequencial só é notificado quem está em
                // `envelope.current_order`; no paralelo todos entram de uma vez).
                //
                // Fase 2 §2.4: só quem PARTICIPA (signatário, testemunha, aprovador) tem vez.
                // O visualizador fica em 0 — não bloqueia a ordem, não é convidado "na vez"
                // de ninguém e recebe a cópia no envio e na conclusão.
                if ($role->participates()) {
                    $turn++;
                    $orderIndex = $signingOrder === SigningOrder::Sequential ? $turn : 1;
                } else {
                    $orderIndex = 0;
                }

                $attributes = [
                    'name' => trim($row['name']),
                    'email' => self::normalizeEmail($row['email']),
                    'role' => $role,
                    'role_label' => self::roleLabel($row['role'] ?? null),
                    'order_index' => $orderIndex,
                    // Ordem em que o remetente montou a lista — é a que as telas mostram.
                    'position' => $position + 1,
                    ...RecipientChannels::attributes($channels[$position]),
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
                $saved[$position] = $recipient;
            }

            $envelope->forceFill([
                'signing_order' => $signingOrder,
                'current_order' => 1,
            ])->save();

            // Fase 3 §3.3 (F-FLOW): com etapas, a vez vem da etapa de cada participante. Sem
            // etapas não faz nada.
            StepTurns::apply($envelope);

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

        // PIN do remetente: só hash, depois do commit (evento sem o valor).
        app(RecipientChannels::class)->applyPins($envelope, $saved, $channels);

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
     * A ordem da lista é `position` (gravada pelo passo 2) e, para desempatar, o `id`.
     * Ordenar pela vez (`order_index`) perderia a ordem arrumada pelo remetente: em
     * paralelo todos ficam na vez 1, e o visualizador fica sempre na 0.
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
                ->orderBy('position')
                ->orderBy('id')
                ->get();

            $changed = 0;
            $turn = 0;

            foreach ($recipients->values() as $recipient) {
                // Mesma regra de `handle()`: visualizador não tem vez (0).
                if ($recipient->participates()) {
                    $turn++;
                    $orderIndex = $locked->signing_order === SigningOrder::Sequential ? $turn : 1;
                } else {
                    $orderIndex = 0;
                }

                if ((int) $recipient->order_index !== $orderIndex) {
                    $recipient->forceFill(['order_index' => $orderIndex])->save();
                    $changed++;
                }
            }

            // Fase 3 §3.3 (F-FLOW): com etapas, a vez vem da etapa (sem etapas não faz nada).
            $changed += StepTurns::apply($locked);

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

    /**
     * Papel de domínio de cada linha (`recipients.*.participant_role`, Fase 2 §2.4).
     *
     * - ausente: mantém o papel de quem já existe; linha nova é `signer` (Fase 1);
     * - papel diferente de `signer` só com a flag `participant_roles` ligada para a
     *   organização — com ela desligada o wizard continua exatamente como na Fase 1;
     * - um papel já gravado (a flag foi desligada depois) é preservado: desligar a flag
     *   impede criar papéis novos, não reescreve o que existe.
     *
     * @param  list<array<string, mixed>>  $incoming
     * @return array<int, RecipientRole>
     *
     * @throws ValidationException
     */
    private static function resolveRoles(Envelope $envelope, array $incoming): array
    {
        $allowed = DomainFeatures::participantRoles($envelope->organization);

        /** @var array<string, RecipientRole> $existing */
        $existing = $envelope->recipients()->get()
            ->mapWithKeys(fn (Recipient $recipient): array => [$recipient->ulid => $recipient->role])
            ->all();

        $roles = [];
        $errors = [];

        foreach ($incoming as $position => $row) {
            $ulid = isset($row['id']) && is_string($row['id']) ? $row['id'] : null;
            $current = $ulid !== null ? ($existing[$ulid] ?? null) : null;
            $raw = $row['participant_role'] ?? null;

            if ($raw === null || $raw === '') {
                $roles[$position] = $current ?? RecipientRole::Signer;

                continue;
            }

            $role = RecipientRole::tryFrom((string) $raw);

            if ($role === null) {
                $errors["recipients.{$position}.participant_role"] = 'Papel inválido. Use signatário, testemunha, aprovador ou visualizador.';

                continue;
            }

            if ($role !== RecipientRole::Signer && $role !== $current && ! $allowed) {
                $errors["recipients.{$position}.participant_role"] = 'Os papéis de testemunha, aprovador e visualizador ainda não estão disponíveis para esta organização.';

                continue;
            }

            $roles[$position] = $role;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $roles;
    }

    private static function roleLabel(?string $role): ?string
    {
        $role = trim((string) $role);

        return $role === '' ? null : mb_substr($role, 0, 40);
    }
}
