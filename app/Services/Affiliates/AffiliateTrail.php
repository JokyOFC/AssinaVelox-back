<?php

namespace App\Services\Affiliates;

use App\Models\Affiliate;
use App\Models\PayoutBatch;
use App\Models\Referral;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Trilha append-only do programa (`affiliate_events`). Só INSERT: nenhum método altera ou
 * apaga linhas. Payload mínimo — ULIDs, rótulos, taxas, valores e motivos digitados pela
 * operadora; nunca dados de repasse, e-mail/IP do indicado ou segredo.
 */
final class AffiliateTrail
{
    public const APPLIED = 'affiliate.applied';

    public const APPROVED = 'affiliate.approved';

    public const REJECTED = 'affiliate.rejected';

    public const SUSPENDED = 'affiliate.suspended';

    public const REACTIVATED = 'affiliate.reactivated';

    public const RATE_CHANGED = 'affiliate.rate_changed';

    public const PAYOUT_DETAILS_UPDATED = 'affiliate.payout_details_updated';

    public const REFERRAL_ATTRIBUTED = 'referral.attributed';

    public const REFERRAL_REVIEW_REQUESTED = 'referral.review_requested';

    public const REFERRAL_REVIEWED = 'referral.reviewed';

    public const BATCH_CREATED = 'payout_batch.created';

    public const BATCH_PAID = 'payout_batch.paid';

    public const BATCH_CANCELED = 'payout_batch.canceled';

    public const BATCH_EXPORTED = 'payout_batch.exported';

    /**
     * @param  array<string, scalar|list<scalar>|null>  $payload
     */
    public static function record(
        string $action,
        ?User $actor,
        ?Affiliate $affiliate = null,
        ?Referral $referral = null,
        ?PayoutBatch $batch = null,
        array $payload = [],
    ): void {
        $now = Carbon::now();

        DB::table('affiliate_events')->insert([
            'ulid' => (string) Str::ulid(),
            'affiliate_id' => $affiliate?->getKey(),
            'referral_id' => $referral?->getKey(),
            'payout_batch_id' => $batch?->getKey(),
            'actor_user_id' => $actor?->getKey(),
            'action' => $action,
            'payload' => $payload === [] ? null : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'ip_address' => $actor !== null && app()->bound('request') ? request()->ip() : null,
            'occurred_at' => $now,
            'created_at' => $now,
        ]);
    }

    public static function label(string $action): string
    {
        return match ($action) {
            self::APPLIED => 'Candidatura enviada',
            self::APPROVED => 'Afiliado aprovado',
            self::REJECTED => 'Candidatura recusada',
            self::SUSPENDED => 'Afiliado suspenso',
            self::REACTIVATED => 'Afiliado reativado',
            self::RATE_CHANGED => 'Taxa de comissão alterada',
            self::PAYOUT_DETAILS_UPDATED => 'Dados de repasse atualizados',
            self::REFERRAL_ATTRIBUTED => 'Indicação atribuída',
            self::REFERRAL_REVIEW_REQUESTED => 'Revisão humana solicitada',
            self::REFERRAL_REVIEWED => 'Indicação revisada',
            self::BATCH_CREATED => 'Lote de repasse montado',
            self::BATCH_PAID => 'Lote marcado como pago',
            self::BATCH_CANCELED => 'Lote cancelado',
            self::BATCH_EXPORTED => 'Lote exportado (CSV)',
            default => $action,
        };
    }

    /**
     * Eventos de um afiliado (mais recentes primeiro), já com o nome de quem agiu.
     *
     * @return list<array{id: string, action: string, label: string, actor: string|null, payload: array<string, mixed>, occurred_at: string|null}>
     */
    public static function forAffiliate(Affiliate $affiliate, int $limit = 50): array
    {
        return self::rows(DB::table('affiliate_events')->where('affiliate_events.affiliate_id', $affiliate->getKey()), $limit);
    }

    /**
     * @return list<array{id: string, action: string, label: string, actor: string|null, payload: array<string, mixed>, occurred_at: string|null}>
     */
    public static function forBatch(PayoutBatch $batch, int $limit = 50): array
    {
        return self::rows(DB::table('affiliate_events')->where('affiliate_events.payout_batch_id', $batch->getKey()), $limit);
    }

    /**
     * @param  Builder  $query
     * @return list<array{id: string, action: string, label: string, actor: string|null, payload: array<string, mixed>, occurred_at: string|null}>
     */
    private static function rows($query, int $limit): array
    {
        $rows = $query
            ->leftJoin('users', 'users.id', '=', 'affiliate_events.actor_user_id')
            ->orderByDesc('affiliate_events.occurred_at')
            ->orderByDesc('affiliate_events.id')
            ->limit($limit)
            ->get(['affiliate_events.ulid', 'affiliate_events.action', 'affiliate_events.payload', 'affiliate_events.occurred_at', 'users.name as actor_name']);

        $out = [];

        foreach ($rows as $row) {
            $payload = is_string($row->payload) ? json_decode($row->payload, true) : null;

            $out[] = [
                'id' => (string) $row->ulid,
                'action' => (string) $row->action,
                'label' => self::label((string) $row->action),
                'actor' => $row->actor_name !== null ? (string) $row->actor_name : null,
                'payload' => is_array($payload) ? $payload : [],
                'occurred_at' => $row->occurred_at !== null ? Carbon::parse((string) $row->occurred_at)->toIso8601String() : null,
            ];
        }

        return $out;
    }
}
