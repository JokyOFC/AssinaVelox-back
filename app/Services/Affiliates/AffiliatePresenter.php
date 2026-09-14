<?php

namespace App\Services\Affiliates;

use App\Models\Affiliate;
use App\Models\Commission;
use App\Models\Referral;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Formatação das props das telas do programa. No PORTAL do afiliado, uma indicação expõe só o
 * nome da organização, datas e estado — nunca e-mail, usuário ou IP de quem se cadastrou.
 */
final class AffiliatePresenter
{
    /**
     * @param  LengthAwarePaginator<int, mixed>  $page
     * @param  list<array<string, mixed>>  $rows
     * @return array{data: list<array<string, mixed>>, links: array<string, string|null>, meta: array<string, mixed>}
     */
    public static function paginated(LengthAwarePaginator $page, array $rows): array
    {
        return [
            'data' => $rows,
            'links' => [
                'first' => $page->url(1),
                'last' => $page->url($page->lastPage()),
                'prev' => $page->previousPageUrl(),
                'next' => $page->nextPageUrl(),
            ],
            'meta' => [
                'current_page' => $page->currentPage(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'path' => $page->path(),
                'links' => $page->linkCollection()->toArray(),
            ],
        ];
    }

    /**
     * Totais por moeda e estado. "A receber" (`approved_cents`) é o saldo LÍQUIDO aprovado ainda
     * não pago (estornos negativos já abatidos); `reversed_cents` soma o que foi revertido.
     *
     * @return list<array{currency: string, pending_cents: int, approved_cents: int, paid_cents: int, reversed_cents: int}>
     */
    public static function totals(?int $affiliateId = null): array
    {
        $rows = DB::table('commissions')
            ->when($affiliateId !== null, fn ($q) => $q->where('affiliate_id', $affiliateId))
            ->selectRaw('currency, status, kind, SUM(amount_cents) as total')
            ->groupBy('currency', 'status', 'kind')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $currency = (string) $row->currency;
            $total = (int) $row->total;
            $out[$currency] ??= ['currency' => $currency, 'pending_cents' => 0, 'approved_cents' => 0, 'paid_cents' => 0, 'reversed_cents' => 0];

            if ((string) $row->kind === Commission::KIND_REVERSAL) {
                $out[$currency]['reversed_cents'] += abs($total);
            }

            match ((string) $row->status) {
                Commission::STATUS_PENDING => $out[$currency]['pending_cents'] += $total,
                Commission::STATUS_APPROVED => $out[$currency]['approved_cents'] += $total,
                Commission::STATUS_PAID => $out[$currency]['paid_cents'] += $total,
                Commission::STATUS_REVERSED => $out[$currency]['reversed_cents'] += $total,
                default => null,
            };
        }

        if ($out === []) {
            $out['BRL'] = ['currency' => 'BRL', 'pending_cents' => 0, 'approved_cents' => 0, 'paid_cents' => 0, 'reversed_cents' => 0];
        }

        return array_values($out);
    }

    /**
     * @param  iterable<Commission>  $commissions
     * @return list<array<string, mixed>>
     */
    public static function commissionRows(iterable $commissions, bool $forOperator = false): array
    {
        $list = [];
        $organizationIds = [];

        foreach ($commissions as $commission) {
            $list[] = $commission;

            if ($commission->organization_id !== null) {
                $organizationIds[] = (int) $commission->organization_id;
            }
        }

        $names = self::organizationNames($organizationIds);
        $batches = DB::table('payout_batches')
            ->whereIn('id', array_values(array_filter(array_map(fn (Commission $c): ?int => $c->payout_batch_id, $list))))
            ->pluck('ulid', 'id');

        return array_map(fn (Commission $c): array => [
            'id' => $c->ulid,
            'kind' => $c->kind,
            'kind_label' => Commission::kindLabel($c->kind),
            'organization_name' => $c->organization_id !== null ? ($names[(int) $c->organization_id] ?? 'Organização removida') : 'Organização removida',
            'base_amount_cents' => $c->base_amount_cents,
            'rate_bp' => $c->rate_bp,
            'amount_cents' => $c->amount_cents,
            'currency' => $c->currency,
            'status' => $c->status,
            'status_label' => Commission::statusLabel($c->status),
            // Ao afiliado, estorno e contestação (chargeback) do CLIENTE indicado são dado
            // financeiro dele (LGPD; afiliados.md §7): motivo neutro. O detalhe fica no painel.
            'reason_label' => $forOperator ? Commission::reasonLabel($c->reversal_reason) : Commission::affiliateReasonLabel($c->reversal_reason),
            'created_at' => $c->created_at?->toIso8601String(),
            'available_at' => $c->available_at?->toIso8601String(),
            'approved_at' => $c->approved_at?->toIso8601String(),
            'paid_at' => $c->paid_at?->toIso8601String(),
            'payout_batch_id' => $c->payout_batch_id !== null ? (string) ($batches[$c->payout_batch_id] ?? '') : null,
        ], $list);
    }

    /**
     * @param  iterable<Referral>  $referrals
     * @return list<array<string, mixed>>
     */
    public static function referralRows(iterable $referrals, bool $forOperator = false): array
    {
        $list = [];
        $organizationIds = [];

        foreach ($referrals as $referral) {
            $list[] = $referral;

            if ($referral->organization_id !== null) {
                $organizationIds[] = (int) $referral->organization_id;
            }
        }

        $names = self::organizationNames($organizationIds);
        $ulids = $forOperator ? DB::table('organizations')->whereIn('id', $organizationIds)->pluck('ulid', 'id') : collect();

        return array_map(function (Referral $referral) use ($names, $ulids, $forOperator): array {
            $reasons = array_map(
                fn (string $reason): array => ['code' => $reason, 'label' => Referral::reasonLabel($reason)],
                $referral->block_reasons ?? [],
            );

            $row = [
                'id' => $referral->ulid,
                'organization_name' => $referral->organization_id !== null ? ($names[(int) $referral->organization_id] ?? 'Organização removida') : 'Organização removida',
                'status' => $referral->status,
                'status_label' => Referral::statusLabel($referral->status),
                'reasons' => $reasons,
                'attributed_at' => $referral->attributed_at->toIso8601String(),
                'expires_at' => $referral->expires_at?->toIso8601String(),
                'review_requested_at' => $referral->review_requested_at?->toIso8601String(),
                'reviewed_at' => $referral->reviewed_at?->toIso8601String(),
                'review_note' => $referral->review_note,
                'can_request_review' => in_array($referral->status, [Referral::STATUS_HELD, Referral::STATUS_REJECTED], true)
                    && $referral->review_requested_at === null && $referral->reviewed_at === null,
            ];

            if ($forOperator) {
                $row['organization_id'] = $referral->organization_id !== null ? (string) ($ulids[(int) $referral->organization_id] ?? '') : null;
                $row['affiliate'] = [
                    'id' => $referral->affiliate->ulid,
                    'code' => $referral->affiliate->code,
                    'name' => (string) ($referral->affiliate->user->name ?? 'Conta excluída'),
                ];
            }

            return $row;
        }, $list);
    }

    /**
     * @return array<string, mixed>
     */
    public static function affiliate(Affiliate $affiliate): array
    {
        return [
            'id' => $affiliate->ulid,
            'status' => $affiliate->status,
            'status_label' => Affiliate::statusLabel($affiliate->status),
            'status_reason' => $affiliate->status_reason,
            'code' => $affiliate->code,
            'link' => $affiliate->code !== null && $affiliate->isApproved() ? route('affiliates.link', ['code' => $affiliate->code]) : null,
            'commission_rate_bp' => $affiliate->commission_rate_bp,
            'payout' => PayoutDetails::mask($affiliate->payout_details),
            'terms_version' => $affiliate->terms_version,
            'applied_at' => $affiliate->terms_accepted_at->toIso8601String(),
            'approved_at' => $affiliate->approved_at?->toIso8601String(),
            'suspended_at' => $affiliate->suspended_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function program(AffiliateSettings $settings): array
    {
        return [
            'terms_version' => $settings->termsVersion(),
            'default_rate_bp' => $settings->defaultRateBp(),
            'max_rate_bp' => $settings->maxRateBp(),
            'attribution_window_days' => $settings->attributionWindowDays(),
            'attribution_model' => $settings->attributionModel(),
            'approval_hold_days' => $settings->approvalHoldDays(),
            'commission_months' => $settings->commissionMonths(),
            'min_payout_cents' => $settings->minPayoutCents(),
            'pix_key_types' => array_map(
                fn (string $type): array => ['value' => $type, 'label' => PayoutDetails::keyTypeLabel($type)],
                PayoutDetails::KEY_TYPES,
            ),
        ];
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private static function organizationNames(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $names = [];

        foreach (DB::table('organizations')->whereIn('id', array_values(array_unique($ids)))->get(['id', 'name']) as $row) {
            $names[(int) $row->id] = (string) $row->name;
        }

        return $names;
    }
}
