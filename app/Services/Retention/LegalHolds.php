<?php

namespace App\Services\Retention;

use App\Models\Envelope;
use App\Models\Folder;
use App\Models\LegalHold;
use App\Models\Organization;
use App\Models\RetentionEvent;
use App\Models\User;
use App\Services\Retention\Exceptions\LegalHoldActiveException;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Bloqueio de exclusão por preservação (Fase 2 §2.19 — docs/fase-2/retencao-e-preservacao.md §5).
 *
 * Regra única, consultada por TODOS os caminhos que apagam: a retenção
 * ({@see EnvelopePurger}, {@see CategorySweeper}), a exclusão manual do rascunho
 * (EnvelopeController::destroy → {@see self::guardEnvelope()}) e a exclusão definitiva da
 * organização (OrganizationPurge → {@see self::guardOrganization()}). O bloqueio vence
 * sempre, e cada tentativa barrada fica em `retention_events` (`legal_hold.blocked_deletion`).
 *
 * Cobertura:
 *  - `envelope`: aquele envelope;
 *  - `folder`: envelopes que ESTÃO na pasta ou numa subpasta no momento da verificação;
 *  - `organization`: tudo da organização, inclusive a própria organização.
 *
 * Um bloqueio vale independentemente da flag `retention_policies` (ver RetentionFeature).
 */
final class LegalHolds
{
    public const MAX_REASON = 1000;

    public function place(
        Organization $organization,
        User $actor,
        LegalHoldScope $scope,
        string $reason,
        ?Envelope $envelope = null,
        ?Folder $folder = null,
        ?CarbonInterface $endsAt = null,
    ): LegalHold {
        $subject = match ($scope) {
            LegalHoldScope::Envelope => $envelope?->ulid,
            LegalHoldScope::Folder => $folder?->ulid,
            LegalHoldScope::Organization => $organization->ulid,
        };

        if ($subject === null
            || ($envelope !== null && (int) $envelope->organization_id !== (int) $organization->getKey())
            || ($folder !== null && (int) $folder->organization_id !== (int) $organization->getKey())) {
            throw new \InvalidArgumentException('Alvo do bloqueio inválido para esta organização.');
        }

        $hold = LegalHold::query()->create([
            'organization_id' => $organization->getKey(),
            'scope' => $scope,
            'envelope_id' => $scope === LegalHoldScope::Envelope ? $envelope?->getKey() : null,
            'folder_id' => $scope === LegalHoldScope::Folder ? $folder?->getKey() : null,
            'subject_ulid' => $subject,
            'reason' => Str::limit(trim($reason), self::MAX_REASON, ''),
            'created_by_user_id' => $actor->getKey(),
            'starts_at' => Carbon::now(),
            'ends_at' => $endsAt,
        ]);

        RetentionTrail::record(
            (int) $organization->getKey(),
            RetentionEvent::HOLD_PLACED,
            [
                'hold' => $hold->ulid,
                'scope' => $scope->value,
                'ends_at' => $endsAt?->toIso8601String(),
                'reason' => Str::limit($hold->reason, 300),
            ],
            envelopeId: $hold->envelope_id,
            legalHoldId: (int) $hold->getKey(),
            subjectUlid: $subject,
            actorUserId: (int) $actor->getKey(),
        );

        return $hold;
    }

    /**
     * Libera o bloqueio (idempotente: um bloqueio já liberado não muda).
     */
    public function release(LegalHold $hold, User $actor, string $reason): LegalHold
    {
        return DB::transaction(function () use ($hold, $actor, $reason): LegalHold {
            /** @var LegalHold $locked */
            $locked = LegalHold::withoutOrganizationScope()->whereKey($hold->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->released_at !== null) {
                return $locked;
            }

            $locked->forceFill([
                'released_at' => Carbon::now(),
                'released_by_user_id' => $actor->getKey(),
                'release_reason' => Str::limit(trim($reason), self::MAX_REASON, ''),
            ])->save();

            RetentionTrail::record(
                (int) $locked->organization_id,
                RetentionEvent::HOLD_RELEASED,
                [
                    'hold' => $locked->ulid,
                    'scope' => $locked->scope->value,
                    'reason' => Str::limit((string) $locked->release_reason, 300),
                ],
                envelopeId: $locked->envelope_id,
                legalHoldId: (int) $locked->getKey(),
                subjectUlid: $locked->subject_ulid,
                actorUserId: (int) $actor->getKey(),
            );

            return $locked;
        });
    }

    /**
     * @return Collection<int, LegalHold>
     */
    public function activeFor(int $organizationId, ?CarbonInterface $now = null): Collection
    {
        return LegalHold::withoutOrganizationScope()
            ->where('organization_id', $organizationId)
            ->active($now)
            ->orderBy('id')
            ->get();
    }

    public function snapshot(int $organizationId, ?CarbonInterface $now = null): HoldSnapshot
    {
        $holds = $this->activeFor($organizationId, $now);

        $organizationHold = $holds->first(fn (LegalHold $hold): bool => $hold->scope === LegalHoldScope::Organization);

        $byEnvelope = [];
        $folderHolds = [];

        foreach ($holds as $hold) {
            if ($hold->scope === LegalHoldScope::Envelope && $hold->envelope_id !== null) {
                $byEnvelope[(int) $hold->envelope_id] ??= $hold;
            }

            if ($hold->scope === LegalHoldScope::Folder && $hold->folder_id !== null) {
                $folderHolds[(int) $hold->folder_id] ??= $hold;
            }
        }

        return new HoldSnapshot($organizationHold, $byEnvelope, $this->expandFolders($organizationId, $folderHolds));
    }

    /**
     * Bloqueio que cobre QUALQUER um dos envelopes (dossiê em lote, artefato de vários
     * documentos). Consulta a pasta de cada um — inclusive dos já excluídos por soft delete.
     *
     * @param  list<int>  $envelopeIds
     */
    public function coveringAny(HoldSnapshot $snapshot, array $envelopeIds): ?LegalHold
    {
        if ($snapshot->isEmpty() || $envelopeIds === []) {
            return null;
        }

        if ($snapshot->organizationHold !== null) {
            return $snapshot->organizationHold;
        }

        $folders = DB::table('envelopes')->whereIn('id', array_values(array_unique($envelopeIds)))->pluck('folder_id', 'id');

        foreach ($envelopeIds as $id) {
            $folder = $folders[$id] ?? null;
            $hold = $snapshot->covering((int) $id, $folder !== null ? (int) $folder : null);

            if ($hold !== null) {
                return $hold;
            }
        }

        return null;
    }

    public function coveringHold(Envelope $envelope, ?CarbonInterface $now = null): ?LegalHold
    {
        return $this->snapshot((int) $envelope->organization_id, $now)
            ->covering((int) $envelope->getKey(), $envelope->folder_id !== null ? (int) $envelope->folder_id : null);
    }

    /**
     * Qualquer bloqueio ativo da organização (de documento, de pasta ou da organização).
     */
    public function anyActive(int $organizationId, ?CarbonInterface $now = null): ?LegalHold
    {
        return LegalHold::withoutOrganizationScope()
            ->where('organization_id', $organizationId)
            ->active($now)
            ->orderBy('id')
            ->first();
    }

    /**
     * Bloqueios ativos que cobrem este envelope (para exibir no detalhe do documento).
     *
     * @return Collection<int, LegalHold>
     */
    public function holdsCovering(Envelope $envelope, ?CarbonInterface $now = null): Collection
    {
        $ancestors = [];
        $folderId = $envelope->folder_id !== null ? (int) $envelope->folder_id : null;

        // Sobe a árvore de pastas (poucos níveis): uma pasta preservada cobre as subpastas.
        while ($folderId !== null && ! in_array($folderId, $ancestors, true)) {
            $ancestors[] = $folderId;
            $parent = DB::table('folders')->where('id', $folderId)->value('parent_id');
            $folderId = $parent !== null ? (int) $parent : null;
        }

        return $this->activeFor((int) $envelope->organization_id, $now)
            ->filter(fn (LegalHold $hold): bool => match ($hold->scope) {
                LegalHoldScope::Organization => true,
                LegalHoldScope::Envelope => (int) $hold->envelope_id === (int) $envelope->getKey(),
                LegalHoldScope::Folder => $hold->folder_id !== null && in_array((int) $hold->folder_id, $ancestors, true),
            })
            ->values();
    }

    /**
     * Guarda da exclusão de UM envelope (manual ou qualquer outra). Registra a tentativa e
     * lança {@see LegalHoldActiveException} quando há bloqueio ativo.
     *
     * @throws LegalHoldActiveException
     */
    public function guardEnvelope(Envelope $envelope, string $context, ?User $actor = null): void
    {
        $hold = $this->coveringHold($envelope);

        if ($hold === null) {
            return;
        }

        $this->recordBlocked((int) $envelope->organization_id, $context, $hold, $envelope, $actor);

        throw new LegalHoldActiveException($hold, sprintf(
            'Este documento está preservado por um bloqueio de exclusão (desde %s). Nada dele pode ser excluído até que alguém com permissão libere a preservação.',
            $this->localDate($hold, $envelope->organization_id),
        ));
    }

    /**
     * Guarda da exclusão definitiva da organização: QUALQUER bloqueio ativo (de documento, de
     * pasta ou da organização) impede. Decisão pendente do roadmap ("bloqueia ou transfere"):
     * bloqueia — transferir documentos preservados para outra conta mudaria o controlador dos
     * dados sem base; ver docs/fase-2/retencao-e-preservacao.md §5.4.
     *
     * @throws LegalHoldActiveException
     */
    public function guardOrganization(Organization $organization, string $context, ?User $actor = null): void
    {
        $hold = $this->anyActive((int) $organization->getKey());

        if ($hold === null) {
            return;
        }

        $this->recordBlocked((int) $organization->getKey(), $context, $hold, null, $actor);

        throw new LegalHoldActiveException($hold, 'A organização tem documentos sob preservação legal. A exclusão só acontece depois que todas as preservações forem liberadas.');
    }

    /**
     * Guarda da troca de pasta (integração I-2C, docs/fase-2/retencao-e-preservacao.md §5.5): a
     * cobertura por pasta é avaliada no momento, então mover um documento para FORA de uma pasta
     * preservada tiraria a proteção sem ninguém liberar nada. Recusa quando algum bloqueio de
     * pasta que hoje cobre o documento deixaria de cobri-lo no destino — mesmo que outro bloqueio
     * (do documento ou da organização) continue cobrindo: quem preservou a pasta decide sobre o
     * conteúdo dela. Bloqueio de documento e de organização, sozinhos, não impedem a mudança.
     *
     * @throws LegalHoldActiveException
     */
    public function guardMove(Envelope $envelope, ?int $targetFolderId, string $context, ?User $actor = null): void
    {
        $folderHolds = $this->holdsCovering($envelope)
            ->filter(fn (LegalHold $hold): bool => $hold->scope === LegalHoldScope::Folder);

        if ($folderHolds->isEmpty()) {
            return;
        }

        // Cópia em memória com a pasta de destino — nada é gravado aqui.
        $moved = (clone $envelope)->forceFill(['folder_id' => $targetFolderId]);
        $stillCovering = $this->holdsCovering($moved)->map(fn (LegalHold $hold): int => (int) $hold->getKey())->all();

        $lost = $folderHolds->first(fn (LegalHold $hold): bool => ! in_array((int) $hold->getKey(), $stillCovering, true));

        if ($lost === null) {
            return;
        }

        $this->recordBlocked((int) $envelope->organization_id, $context, $lost, $envelope, $actor);

        throw new LegalHoldActiveException($lost, 'Este documento está preservado pela pasta em que está. Movê-lo para fora dela tiraria a proteção: libere a preservação da pasta ou preserve o próprio documento antes de mover.');
    }

    /**
     * Guarda da exclusão de uma pasta (integração I-2C, §5.5): excluir a pasta devolve os
     * documentos e as subpastas para "Todos", o que tiraria a cobertura de um bloqueio sobre ela
     * ou sobre uma pasta acima dela. Recusa quando a própria pasta está preservada, ou quando uma
     * pasta acima está preservada e esta ainda tem documentos ou subpastas.
     *
     * @throws LegalHoldActiveException
     */
    public function guardFolderDeletion(Folder $folder, string $context, ?User $actor = null): void
    {
        $chain = [];
        $current = (int) $folder->getKey();

        while (! in_array($current, $chain, true)) {
            $chain[] = $current;
            $parent = DB::table('folders')->where('id', $current)->value('parent_id');

            if ($parent === null) {
                break;
            }

            $current = (int) $parent;
        }

        $holds = $this->activeFor((int) $folder->organization_id)
            ->filter(fn (LegalHold $hold): bool => $hold->scope === LegalHoldScope::Folder
                && $hold->folder_id !== null
                && in_array((int) $hold->folder_id, $chain, true));

        $own = $holds->first(fn (LegalHold $hold): bool => (int) $hold->folder_id === (int) $folder->getKey());
        $inherited = $holds->first();

        $hasContent = DB::table('envelopes')->where('folder_id', $folder->getKey())->exists()
            || DB::table('folders')->where('parent_id', $folder->getKey())->exists();

        $hold = $own ?? ($hasContent ? $inherited : null);

        if ($hold === null) {
            return;
        }

        $this->recordBlocked((int) $folder->organization_id, $context, $hold, null, $actor);

        throw new LegalHoldActiveException($hold, 'Esta pasta está sob preservação legal (ela ou uma pasta acima dela). Excluí-la devolveria os documentos para "Todos" e tiraria a proteção: libere a preservação antes de excluir a pasta.');
    }

    public function recordBlocked(int $organizationId, string $context, LegalHold $hold, ?Envelope $envelope = null, ?User $actor = null): void
    {
        $payload = ['context' => $context, 'hold' => $hold->ulid, 'scope' => $hold->scope->value];
        $subject = $envelope->ulid ?? $hold->subject_ulid;

        // Tentativas de uma PESSOA sempre entram; as automáticas (job diário, varredura da
        // exclusão da organização) só uma vez por dia por assunto.
        if ($actor !== null) {
            RetentionTrail::record($organizationId, RetentionEvent::HOLD_BLOCKED_DELETION, $payload, $envelope?->getKey(), (int) $hold->getKey(), $subject, (int) $actor->getKey());

            return;
        }

        RetentionTrail::recordOncePerDay($organizationId, RetentionEvent::HOLD_BLOCKED_DELETION, $subject, $payload, $envelope?->getKey(), (int) $hold->getKey());
    }

    /**
     * Pastas bloqueadas + todas as subpastas (a árvore é pequena por organização).
     *
     * @param  array<int, LegalHold>  $folderHolds
     * @return array<int, LegalHold>
     */
    private function expandFolders(int $organizationId, array $folderHolds): array
    {
        if ($folderHolds === []) {
            return [];
        }

        $children = [];

        foreach (DB::table('folders')->where('organization_id', $organizationId)->get(['id', 'parent_id']) as $row) {
            if ($row->parent_id !== null) {
                $children[(int) $row->parent_id][] = (int) $row->id;
            }
        }

        $covered = [];

        foreach ($folderHolds as $folderId => $hold) {
            $queue = [$folderId];

            while ($queue !== []) {
                $current = array_shift($queue);

                if (isset($covered[$current])) {
                    continue;
                }

                $covered[$current] = $hold;

                foreach ($children[$current] ?? [] as $child) {
                    $queue[] = $child;
                }
            }
        }

        return $covered;
    }

    private function localDate(LegalHold $hold, int|string|null $organizationId): string
    {
        $timezone = Organization::withTrashed()->whereKey($organizationId)->value('timezone');

        return $hold->starts_at->copy()->setTimezone(is_string($timezone) && $timezone !== '' ? $timezone : 'America/Sao_Paulo')->format('d/m/Y');
    }
}
