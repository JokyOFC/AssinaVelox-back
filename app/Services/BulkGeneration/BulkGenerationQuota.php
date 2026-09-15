<?php

namespace App\Services\BulkGeneration;

use App\Enums\PlanConsumptionStatus;
use App\Models\BulkGeneration;
use App\Models\BulkGenerationRow;
use App\Models\Envelope;
use App\Models\PlanConsumption;
use App\Models\Subscription;
use App\Services\Plans\PlanLedger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Cota do plano no lote (docs/fase-3/geracao-em-lote.md §6), sobre o MESMO ledger do envio
 * (`plan_consumptions` + `subscriptions.envelopes_reserved`).
 *
 * - Confirmar o lote reserva UMA unidade por linha VÁLIDA, chave `bulk:{lote}:row:{linha}`
 *   (UNIQUE: reconfirmar não reserva de novo). As linhas inválidas não reservam nada.
 * - Cada unidade é devolvida exatamente uma vez ({@see PlanLedger::release()} é idempotente):
 *   quando a linha falha ou é cancelada, quando o envelope nasce como rascunho/pronto para
 *   revisão (o envio posterior consome pela regra de sempre), antes do AGENDAMENTO (o envio
 *   agendado pode cair em outro ciclo e consome pela regra de sempre) ou, no modo "enviar ao
 *   gerar", DENTRO da transação do `SendEnvelope`, trocada pela unidade `envelope:{id}:send`
 *   sem nova checagem de cota ({@see self::handOver()}); se o envio não acontece, a unidade
 *   volta logo depois.
 * - A renovação do ciclo reconta `envelopes_reserved` a partir das reservas abertas, então as
 *   unidades do lote em andamento continuam contadas no ciclo novo.
 */
final class BulkGenerationQuota
{
    public function __construct(private readonly PlanLedger $ledger) {}

    public static function keyFor(int $batchId, int $rowId): string
    {
        return "bulk:{$batchId}:row:{$rowId}";
    }

    /**
     * Chamado DENTRO da transação do chamador, com a assinatura bloqueada.
     *
     * @param  list<int>  $rowIds
     */
    public function reserve(BulkGeneration $batch, Subscription $subscription, array $rowIds): void
    {
        if ($rowIds === []) {
            return;
        }

        $now = Carbon::now();

        foreach (array_chunk($rowIds, 200) as $chunk) {
            DB::table('plan_consumptions')->insert(array_map(fn (int $rowId): array => [
                'organization_id' => $batch->organization_id,
                'subscription_id' => $subscription->getKey(),
                'envelope_id' => null,
                'idempotency_key' => self::keyFor((int) $batch->getKey(), $rowId),
                'quantity' => 1,
                'status' => PlanConsumptionStatus::Reserved->value,
                'reserved_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ], $chunk));
        }

        Subscription::withoutOrganizationScope()
            ->whereKey($subscription->getKey())
            ->increment('envelopes_reserved', count($rowIds));
    }

    /**
     * Modo "enviar ao gerar": a unidade da linha NÃO volta antes do envio — ela é entregue ao
     * envelope (`envelope_id` na reserva) e o SendEnvelope a converte na própria reserva
     * `envelope:{id}:send` na MESMA transação, com a assinatura bloqueada ({@see self::handedOverTo()}).
     * Sem intervalo em que a unidade fique livre para outro envio da organização.
     */
    public function handOver(BulkGenerationRow $row, Envelope $envelope): void
    {
        PlanConsumption::withoutOrganizationScope()
            ->where('idempotency_key', self::keyFor((int) $row->bulk_generation_id, (int) $row->getKey()))
            ->where('status', PlanConsumptionStatus::Reserved->value)
            ->update(['envelope_id' => $envelope->getKey(), 'updated_at' => Carbon::now()]);
    }

    /**
     * Reserva de lote entregue a este envelope e ainda aberta (null = nenhuma). Lida pelo
     * SendEnvelope sob o lock da assinatura.
     */
    public static function handedOverTo(Envelope $envelope): ?PlanConsumption
    {
        /** @var PlanConsumption|null */
        return PlanConsumption::withoutOrganizationScope()
            ->where('envelope_id', $envelope->getKey())
            ->where('idempotency_key', 'like', 'bulk:%')
            ->where('status', PlanConsumptionStatus::Reserved->value)
            ->first();
    }

    public function releaseRow(BulkGenerationRow $row, string $reason): void
    {
        $consumption = PlanConsumption::withoutOrganizationScope()
            ->where('idempotency_key', self::keyFor((int) $row->bulk_generation_id, (int) $row->getKey()))
            ->first();

        if ($consumption !== null) {
            $this->ledger->release($consumption, null, $reason);
        }
    }

    /**
     * Unidades ainda reservadas pelo lote (para a tela de acompanhamento e os testes).
     */
    public function reservedFor(BulkGeneration $batch): int
    {
        return (int) PlanConsumption::withoutOrganizationScope()
            ->where('idempotency_key', 'like', "bulk:{$batch->getKey()}:row:%")
            ->where('status', PlanConsumptionStatus::Reserved->value)
            ->sum('quantity');
    }
}
