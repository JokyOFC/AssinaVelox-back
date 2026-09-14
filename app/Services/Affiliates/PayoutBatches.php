<?php

namespace App\Services\Affiliates;

use App\Models\Affiliate;
use App\Models\Commission;
use App\Models\PayoutBatch;
use App\Models\User;
use App\Support\Csv;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Lotes de repasse (Fase 3 §3.10). **O sistema calcula, não paga**: nenhuma chamada a banco,
 * PIX ou gateway sai daqui. A operadora monta o lote, faz o repasse fora da plataforma e
 * registra MANUALMENTE que pagou (quem, quando, referência externa).
 *
 * Regras de montagem (por moeda, até a data de corte):
 * - entram lançamentos `approved` ainda sem lote (comissões, ajustes e estornos negativos);
 * - por afiliado, só entra quem tem saldo líquido ≥ `min_payout_cents`, está APROVADO (não
 *   suspenso) e tem dados de repasse; os demais lançamentos ficam para o próximo lote —
 *   inclusive saldos negativos, que abatem as próximas comissões.
 */
final class PayoutBatches
{
    public function __construct(private readonly AffiliateSettings $settings) {}

    public function build(string $currency, CarbonInterface $cutoff, User $actor): PayoutBatch
    {
        $currency = strtoupper($currency);

        return DB::transaction(function () use ($currency, $cutoff, $actor): PayoutBatch {
            $entries = Commission::query()
                ->whereNull('payout_batch_id')
                ->where('status', Commission::STATUS_APPROVED)
                ->where('currency', $currency)
                ->where('approved_at', '<=', $cutoff)
                ->lockForUpdate()
                ->get();

            $included = $this->eligible($entries);

            if ($included->isEmpty()) {
                throw ValidationException::withMessages(['cutoff' => 'Nenhum afiliado tem saldo aprovado a repassar até essa data.']);
            }

            $ids = $included->flatten(1)->pluck('id')->all();
            $total = (int) $included->flatten(1)->sum('amount_cents');

            $batch = PayoutBatch::query()->create([
                'currency' => $currency,
                'status' => PayoutBatch::STATUS_DRAFT,
                'cutoff_at' => $cutoff,
                'total_cents' => $total,
                'affiliates_count' => $included->count(),
                'entries_count' => count($ids),
                'created_by_user_id' => $actor->getKey(),
            ]);

            $updated = Commission::query()->whereIn('id', $ids)->whereNull('payout_batch_id')->update([
                'payout_batch_id' => $batch->getKey(),
                'updated_at' => Carbon::now(),
            ]);

            if ($updated !== count($ids)) {
                throw ValidationException::withMessages(['cutoff' => 'Os lançamentos mudaram durante a montagem. Tente novamente.']);
            }

            AffiliateTrail::record(AffiliateTrail::BATCH_CREATED, $actor, batch: $batch, payload: [
                'currency' => $currency,
                'cutoff' => $cutoff->toIso8601String(),
                'total_cents' => $total,
                'affiliates' => $included->count(),
                'entries' => count($ids),
            ]);

            return $batch;
        });
    }

    /**
     * O que um lote montado agora teria (sem gravar nada).
     *
     * @return array{currency: string, entries: int, affiliates: int, total_cents: int, eligible_affiliates: int, eligible_total_cents: int, without_payout_details: int}
     */
    public function preview(string $currency, CarbonInterface $cutoff): array
    {
        $entries = Commission::query()
            ->whereNull('payout_batch_id')
            ->where('status', Commission::STATUS_APPROVED)
            ->where('currency', strtoupper($currency))
            ->where('approved_at', '<=', $cutoff)
            ->get();

        $included = $this->eligible($entries);
        $withoutDetails = Affiliate::query()
            ->whereIn('id', $entries->pluck('affiliate_id')->unique()->all())
            ->get()
            ->filter(fn (Affiliate $a): bool => ! $a->hasPayoutDetails())
            ->count();

        return [
            'currency' => strtoupper($currency),
            'entries' => $entries->count(),
            'affiliates' => $entries->pluck('affiliate_id')->unique()->count(),
            'total_cents' => (int) $entries->sum('amount_cents'),
            'eligible_affiliates' => $included->count(),
            'eligible_total_cents' => (int) $included->flatten(1)->sum('amount_cents'),
            'without_payout_details' => $withoutDetails,
        ];
    }

    /**
     * Agrupa por afiliado e mantém só quem pode receber: aprovado (não suspenso), com dados de
     * repasse e saldo líquido ≥ mínimo. O resto fica para o próximo lote.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, Commission>  $entries
     * @return Collection<int|string, \Illuminate\Database\Eloquent\Collection<int, Commission>>
     */
    private function eligible($entries)
    {
        $affiliates = Affiliate::query()->whereIn('id', $entries->pluck('affiliate_id')->unique()->all())->get()->keyBy('id');

        return $entries->groupBy('affiliate_id')->filter(function ($group, $affiliateId) use ($affiliates): bool {
            /** @var Affiliate|null $affiliate */
            $affiliate = $affiliates->get($affiliateId);

            return $affiliate !== null
                && $affiliate->isApproved()
                && $affiliate->hasPayoutDetails()
                && (int) $group->sum('amount_cents') >= $this->settings->minPayoutCents();
        });
    }

    public function markPaid(PayoutBatch $batch, User $actor, CarbonInterface $paidAt, string $externalReference, ?string $notes): void
    {
        DB::transaction(function () use ($batch, $actor, $paidAt, $externalReference, $notes): void {
            $locked = PayoutBatch::query()->whereKey($batch->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isDraft()) {
                throw ValidationException::withMessages(['batch' => 'Só um lote aberto pode ser marcado como pago.']);
            }

            $now = Carbon::now();

            $locked->forceFill([
                'status' => PayoutBatch::STATUS_PAID,
                'paid_by_user_id' => $actor->getKey(),
                'paid_at' => $paidAt,
                'marked_paid_at' => $now,
                'external_reference' => $externalReference,
                'notes' => $notes,
            ])->save();

            Commission::query()->where('payout_batch_id', $locked->getKey())->update([
                'status' => Commission::STATUS_PAID,
                'paid_at' => $paidAt,
                'updated_at' => $now,
            ]);

            AffiliateTrail::record(AffiliateTrail::BATCH_PAID, $actor, batch: $locked, payload: [
                'paid_at' => $paidAt->toIso8601String(),
                'external_reference' => $externalReference,
                'total_cents' => $locked->total_cents,
                'currency' => $locked->currency,
            ]);

            $batch->setRawAttributes($locked->getAttributes(), true);
        });
    }

    public function cancel(PayoutBatch $batch, User $actor, string $reason): void
    {
        DB::transaction(function () use ($batch, $actor, $reason): void {
            $locked = PayoutBatch::query()->whereKey($batch->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isDraft()) {
                throw ValidationException::withMessages(['batch' => 'Só um lote aberto pode ser cancelado.']);
            }

            $locked->forceFill([
                'status' => PayoutBatch::STATUS_CANCELED,
                'canceled_by_user_id' => $actor->getKey(),
                'canceled_at' => Carbon::now(),
                'notes' => $reason,
            ])->save();

            // Os lançamentos voltam para o próximo lote.
            Commission::query()->where('payout_batch_id', $locked->getKey())->update([
                'payout_batch_id' => null,
                'updated_at' => Carbon::now(),
            ]);

            AffiliateTrail::record(AffiliateTrail::BATCH_CANCELED, $actor, batch: $locked, payload: ['reason' => $reason]);

            $batch->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Linhas do lote, uma por afiliado, com dados de repasse MASCARADOS.
     *
     * @return list<array{affiliate_id: string, code: string|null, name: string, payout: array<string, string>|null, entries: int, total_cents: int}>
     */
    public function lines(PayoutBatch $batch): array
    {
        $groups = Commission::query()->where('payout_batch_id', $batch->getKey())->get()->groupBy('affiliate_id');
        $affiliates = Affiliate::query()->with('user:id,name')->whereIn('id', $groups->keys()->all())->get()->keyBy('id');

        $lines = [];

        foreach ($groups as $affiliateId => $group) {
            /** @var Affiliate|null $affiliate */
            $affiliate = $affiliates->get($affiliateId);

            $lines[] = [
                'affiliate_id' => (string) $affiliate?->ulid,
                'code' => $affiliate?->code,
                'name' => (string) ($affiliate?->user->name ?? 'Conta excluída'),
                'payout' => PayoutDetails::mask($affiliate?->payout_details),
                'entries' => $group->count(),
                'total_cents' => (int) $group->sum('amount_cents'),
            ];
        }

        usort($lines, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $lines;
    }

    /**
     * CSV do lote (`;`, BOM UTF-8), protegido contra injeção de fórmula (App\Support\Csv).
     * Dados de repasse sempre mascarados.
     */
    public function csv(PayoutBatch $batch, User $actor): StreamedResponse
    {
        $lines = $this->lines($batch);

        AffiliateTrail::record(AffiliateTrail::BATCH_EXPORTED, $actor, batch: $batch, payload: ['lines' => count($lines)]);

        $filename = 'lote-repasse-'.$batch->ulid.'.csv';

        return response()->streamDownload(function () use ($batch, $lines): void {
            $out = fopen('php://output', 'wb');

            if ($out === false) {
                return;
            }

            fwrite($out, Csv::BOM);
            fputcsv($out, Csv::row([
                'Lote', 'Estado do lote', 'Moeda', 'Código do afiliado', 'Afiliado', 'Tipo de chave PIX',
                'Chave PIX (mascarada)', 'Titular (mascarado)', 'CPF/CNPJ do titular (mascarado)', 'Lançamentos', 'Valor',
            ]), ';');

            foreach ($lines as $line) {
                fputcsv($out, Csv::row([
                    $batch->ulid,
                    PayoutBatch::statusLabel($batch->status),
                    $batch->currency,
                    $line['code'],
                    $line['name'],
                    $line['payout']['pix_key_type_label'] ?? '',
                    $line['payout']['pix_key'] ?? '',
                    $line['payout']['holder_name'] ?? '',
                    $line['payout']['holder_tax_id'] ?? '',
                    $line['entries'],
                    self::decimal($line['total_cents']),
                ]), ';');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** "1234,56" — sem símbolo de moeda (a moeda tem coluna própria). */
    public static function decimal(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $abs = abs($cents);

        return $sign.intdiv($abs, 100).','.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }
}
