<?php

use App\Models\Affiliate;
use App\Models\Commission;
use App\Models\PayoutBatch;
use App\Models\User;
use App\Services\Affiliates\CommissionLedger;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Support/AffiliateHelpers.php';

/*
|--------------------------------------------------------------------------
| Lotes de repasse: o sistema calcula, não paga. Montagem e baixa MANUAIS com trilha;
| CSV protegido contra fórmula e com dados de repasse mascarados.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    $this->withoutVite();
    enableAffiliates();
    fakeAffiliateRisk();
    $this->admin = User::factory()->platformAdmin()->create();
});

/**
 * Afiliado com N comissões já aprovadas (pagamentos de 10 000 → 1 000 cada).
 */
function affiliateWithApproved(int $count = 1, array $attributes = []): Affiliate
{
    $affiliate = makeAffiliate($attributes);
    ['organization' => $organization] = referOrganization($affiliate, attributedAt: now()->subDays(60));

    foreach (range(1, $count) as $ignored) {
        paymentFor($organization, paidAt: now()->subDays(31));
    }

    app(CommissionLedger::class)->approveDue();

    return $affiliate;
}

function adminPost(User $admin, string $route, array $parameters = [], array $data = [], bool $confirmed = true)
{
    $test = test()->actingAs($admin);

    $test->withSession(['auth.password_confirmed_at' => $confirmed ? time() : 0]);

    return $test->post(route($route, $parameters), $data);
}

test('o lote agrupa por afiliado só o que pode ser repassado; o resto fica para o próximo', function () {
    $ok = affiliateWithApproved(2);
    $suspended = affiliateWithApproved(1);
    $suspended->forceFill(['status' => Affiliate::STATUS_SUSPENDED])->save();
    $withoutDetails = affiliateWithApproved(1, ['payout_details' => null]);

    // Pendente (dentro do prazo) não entra.
    ['organization' => $org] = referOrganization($ok);
    paymentFor($org, paidAt: now());

    adminPost($this->admin, 'admin.affiliates.payouts.store', data: ['currency' => 'BRL'])->assertRedirect();

    $batch = PayoutBatch::query()->sole();
    expect($batch->status)->toBe(PayoutBatch::STATUS_DRAFT)
        ->and($batch->affiliates_count)->toBe(1)
        ->and($batch->entries_count)->toBe(2)
        ->and($batch->total_cents)->toBe(2_000)
        ->and($batch->created_by_user_id)->toBe($this->admin->id);

    expect(Commission::query()->where('affiliate_id', $suspended->id)->whereNull('payout_batch_id')->count())->toBe(1)
        ->and(Commission::query()->where('affiliate_id', $withoutDetails->id)->whereNull('payout_batch_id')->count())->toBe(1)
        ->and(Commission::query()->where('status', Commission::STATUS_PENDING)->whereNull('payout_batch_id')->count())->toBe(1);
});

test('saldo líquido negativo ou abaixo do mínimo não entra; estorno negativo abate no lote seguinte', function () {
    config()->set('assinavelox.affiliates.min_payout_cents', 1_500);
    $affiliate = affiliateWithApproved(1);

    adminPost($this->admin, 'admin.affiliates.payouts.store', data: ['currency' => 'BRL'])->assertSessionHasErrors('cutoff');
    expect(PayoutBatch::query()->count())->toBe(0);

    ['organization' => $org] = referOrganization($affiliate);
    paymentFor($org, paidAt: now()->subDays(31));
    app(CommissionLedger::class)->approveDue();

    adminPost($this->admin, 'admin.affiliates.payouts.store', data: ['currency' => 'BRL'])->assertRedirect();
    expect(PayoutBatch::query()->sole()->total_cents)->toBe(2_000);
});

test('marcar como pago é manual, exige senha confirmada e registra quem, quando e referência', function () {
    affiliateWithApproved(1);
    adminPost($this->admin, 'admin.affiliates.payouts.store', data: ['currency' => 'BRL']);
    $batch = PayoutBatch::query()->sole();

    // Sem senha confirmada: vai para a confirmação e nada muda.
    adminPost($this->admin, 'admin.affiliates.payouts.paid', ['batch' => $batch->ulid], [
        'paid_at' => now()->toDateString(),
        'external_reference' => 'PIX E2E 1234',
    ], confirmed: false)->assertRedirect(route('password.confirm'));
    expect($batch->fresh()->status)->toBe(PayoutBatch::STATUS_DRAFT);

    // Referência externa obrigatória e data não futura.
    adminPost($this->admin, 'admin.affiliates.payouts.paid', ['batch' => $batch->ulid], [
        'paid_at' => now()->addDay()->toDateString(),
    ])->assertSessionHasErrors(['paid_at', 'external_reference']);

    adminPost($this->admin, 'admin.affiliates.payouts.paid', ['batch' => $batch->ulid], [
        'paid_at' => now()->toDateString(),
        'external_reference' => 'PIX E2E 1234',
        'notes' => 'Transferência pelo banco da operadora.',
    ])->assertRedirect()->assertSessionHas('success');

    $batch->refresh();
    expect($batch->status)->toBe(PayoutBatch::STATUS_PAID)
        ->and($batch->paid_by_user_id)->toBe($this->admin->id)
        ->and($batch->paid_at?->toDateString())->toBe(now()->toDateString())
        ->and($batch->marked_paid_at)->not->toBeNull()
        ->and($batch->external_reference)->toBe('PIX E2E 1234')
        ->and(Commission::query()->sole()->status)->toBe(Commission::STATUS_PAID);

    $event = DB::table('affiliate_events')->where('action', 'payout_batch.paid')->sole();
    expect($event->actor_user_id)->toBe($this->admin->id)
        ->and(json_decode($event->payload, true)['external_reference'])->toBe('PIX E2E 1234');

    // Um lote pago não é marcado de novo nem cancelado.
    adminPost($this->admin, 'admin.affiliates.payouts.paid', ['batch' => $batch->ulid], [
        'paid_at' => now()->toDateString(),
        'external_reference' => 'OUTRA',
    ])->assertSessionHasErrors('batch');
    adminPost($this->admin, 'admin.affiliates.payouts.cancel', ['batch' => $batch->ulid], ['reason' => 'Tentativa indevida.'])
        ->assertSessionHasErrors('batch');
});

test('cancelar um lote aberto devolve os lançamentos para o próximo', function () {
    affiliateWithApproved(1);
    adminPost($this->admin, 'admin.affiliates.payouts.store', data: ['currency' => 'BRL']);
    $batch = PayoutBatch::query()->sole();

    adminPost($this->admin, 'admin.affiliates.payouts.cancel', ['batch' => $batch->ulid], ['reason' => 'Valores revistos.'])
        ->assertRedirect(route('admin.affiliates.payouts.index'));

    expect($batch->fresh()->status)->toBe(PayoutBatch::STATUS_CANCELED)
        ->and(Commission::query()->sole()->payout_batch_id)->toBeNull()
        ->and(DB::table('affiliate_events')->where('action', 'payout_batch.canceled')->count())->toBe(1);

    adminPost($this->admin, 'admin.affiliates.payouts.store', data: ['currency' => 'BRL'])->assertRedirect();
    expect(PayoutBatch::query()->where('status', PayoutBatch::STATUS_DRAFT)->sole()->entries_count)->toBe(1);
});

test('CSV do lote: BOM, delimitador ; , fórmula neutralizada e repasse mascarado', function () {
    $affiliate = affiliateWithApproved(1);
    $affiliate->user->forceFill(['name' => '=HYPERLINK("http://mal.example","x")'])->save();

    adminPost($this->admin, 'admin.affiliates.payouts.store', data: ['currency' => 'BRL']);
    $batch = PayoutBatch::query()->sole();

    $response = $this->actingAs($this->admin)->get(route('admin.affiliates.payouts.export', ['batch' => $batch->ulid]));
    $response->assertOk();

    $csv = $response->streamedContent();
    expect(str_starts_with($csv, "\xEF\xBB\xBF"))->toBeTrue()
        ->and($csv)->toContain(chr(34).'\'=HYPERLINK')->and($csv)->not->toContain(chr(34).'=HYPERLINK')
        ->and($csv)->toContain('10,00')
        ->and($csv)->not->toContain('52998224725')
        ->and($csv)->not->toContain('Parceira Souza')
        ->and($csv)->toContain('725');

    expect(DB::table('affiliate_events')->where('action', 'payout_batch.exported')->count())->toBe(1);
});

test('somente a equipe da plataforma acessa os lotes', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('admin.affiliates.payouts.index'))->assertForbidden();
    adminPost($user, 'admin.affiliates.payouts.store', data: ['currency' => 'BRL'])->assertForbidden();
});

test('telas de lotes renderizam com prévia e linhas', function () {
    affiliateWithApproved(1);

    $this->actingAs($this->admin)->get(route('admin.affiliates.payouts.index'))
        ->assertInertia(fn ($page) => $page
            ->component('admin/affiliates/payouts/index')
            ->where('preview.0.currency', 'BRL')
            ->where('preview.0.eligible_total_cents', 1_000)
            ->where('preview.0.eligible_affiliates', 1));

    adminPost($this->admin, 'admin.affiliates.payouts.store', data: ['currency' => 'BRL']);
    $batch = PayoutBatch::query()->sole();

    $this->actingAs($this->admin)->get(route('admin.affiliates.payouts.show', ['batch' => $batch->ulid]))
        ->assertInertia(fn ($page) => $page
            ->component('admin/affiliates/payouts/show')
            ->where('batch.status', 'draft')
            ->has('lines', 1)
            ->where('lines.0.total_cents', 1_000)
            ->where('lines.0.payout.pix_key', '••••••••725')
            ->has('trail', 1));
});
