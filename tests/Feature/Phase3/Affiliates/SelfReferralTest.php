<?php

use App\Enums\PaymentStatus;
use App\Models\Commission;
use App\Models\Referral;
use App\Models\User;
use App\Services\Affiliates\AffiliateRiskSignals;
use App\Services\Affiliates\Attribution;
use App\Services\Affiliates\CommissionLedger;
use App\Services\Affiliates\IpFingerprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Support/AffiliateHelpers.php';

/*
|--------------------------------------------------------------------------
| Autoindicação e contas duplicadas (Fase 3 §3.10 + §3.7)
|--------------------------------------------------------------------------
| Autoindicação → indicação `rejected`, sem comissão, e sinal ao antifraude.
| Possível conta duplicada → `held` (comissão segurada até revisão humana) e sinal.
| Toda decisão automática pode ser revista por uma pessoa (LGPD art. 20).
|
| O sinal passa pelo serviço REAL do antifraude (App\Services\Risk\RiskSignals::record),
| com a flag `antifraud` ligada; as asserções leem a tabela `risk_signals`.
*/

/**
 * Sinais `affiliate_self_referral` gravados pelo antifraude, com a evidência decodificada.
 *
 * @return Collection<int, object>
 */
function selfReferralSignals(): Collection
{
    return DB::table('risk_signals')
        ->where('rule_code', AffiliateRiskSignals::RULE)
        ->orderBy('id')
        ->get()
        ->map(function (object $row): object {
            $row->evidence = json_decode((string) $row->evidence, true) ?? [];

            return $row;
        });
}

beforeEach(function (): void {
    $this->withoutVite();
    enableAffiliates();
    config()->set('assinavelox.features.antifraud', true);
});

test('mesmo e-mail do afiliado (com +tag) é autoindicação: barrada, sem comissão e sinalizada', function () {
    $affiliateUser = User::factory()->create(['email' => 'paula@parceiros-sa.com.br']);
    $affiliate = makeAffiliate(user: $affiliateUser);

    // O cadastro recusa e-mail repetido, então o afiliado usa um alias do mesmo endereço.
    signupWithReferral('paula+cliente@parceiros-sa.com.br', referralCookieValue($affiliate->code));

    $referral = Referral::query()->sole();
    expect($referral->status)->toBe(Referral::STATUS_REJECTED)
        ->and($referral->block_reasons)->toContain(Referral::REASON_SAME_EMAIL)
        ->and($referral->block_reasons)->toContain(Referral::REASON_SAME_DOMAIN);

    $signals = selfReferralSignals();
    expect($signals)->toHaveCount(1)
        ->and($signals[0]->organization_id)->toBe($referral->organization_id)
        ->and($signals[0]->envelope_id)->toBeNull()
        ->and($signals[0]->evidence['referral'] ?? null)->toBe($referral->ulid)
        ->and($signals[0]->evidence['affiliate'] ?? null)->toBe($affiliate->ulid)
        ->and($signals[0]->evidence['match'] ?? null)->toBe('same_email,same_domain')
        ->and($signals[0]->evidence)->not->toHaveKey('_dropped');

    // Pagamento aprovado da organização barrada não gera comissão.
    paymentFor($referral->organization);
    expect(Commission::query()->count())->toBe(0);
});

test('a evidência do sinal só leva chaves permitidas: nunca e-mail, IP ou dados de repasse', function () {
    $affiliate = makeAffiliate(user: User::factory()->create(['email' => 'mara@consultores-eta.com.br']));

    signupWithReferral('ti@consultores-eta.com.br', referralCookieValue($affiliate->code), '198.51.100.44')
        ->assertRedirect(route('dashboard', absolute: false));

    expect(Referral::query()->sole()->status)->toBe(Referral::STATUS_REJECTED);

    $row = DB::table('risk_signals')->where('rule_code', AffiliateRiskSignals::RULE)->sole();
    $allowed = ['affiliate', 'referral', 'match', 'same_user', 'same_ip', 'same_device', 'same_payment_method', 'same_email_domain'];

    expect(array_diff(array_keys(json_decode((string) $row->evidence, true)), $allowed))->toBe([])
        ->and($row->evidence)->not->toContain('consultores-eta.com.br')
        ->and($row->evidence)->not->toContain('198.51.100.44')
        ->and($row->evidence)->not->toContain('52998224725')
        // O sujeito é o HMAC do afiliado, nunca o valor bruto.
        ->and($row->subject_key)->not->toBeNull()
        ->and($row->subject_key)->not->toContain($affiliate->ulid);
});

test('com a flag do antifraude desligada a indicação é barrada do mesmo jeito, mas nenhum sinal é gravado', function () {
    config()->set('assinavelox.features.antifraud', false);
    $affiliate = makeAffiliate(user: User::factory()->create(['email' => 'nina@auditores-teta.com.br']));

    signupWithReferral('rh@auditores-teta.com.br', referralCookieValue($affiliate->code))
        ->assertRedirect(route('dashboard', absolute: false));

    expect(Referral::query()->sole()->status)->toBe(Referral::STATUS_REJECTED)
        ->and(DB::table('risk_signals')->count())->toBe(0);
});

test('mesmo domínio corporativo é autoindicação; domínio público não', function () {
    $affiliate = makeAffiliate(user: User::factory()->create(['email' => 'socio@imobiliaria-alfa.com.br']));

    signupWithReferral('financeiro@imobiliaria-alfa.com.br', referralCookieValue($affiliate->code));
    expect(Referral::query()->sole()->block_reasons)->toBe([Referral::REASON_SAME_DOMAIN]);

    Referral::query()->delete();
    $public = makeAffiliate(user: User::factory()->create(['email' => 'joana.parceira@gmail.com']));

    signupWithReferral('cliente.qualquer@gmail.com', referralCookieValue($public->code), '198.51.100.31');

    $referral = Referral::query()->sole();
    expect($referral->status)->toBe(Referral::STATUS_ACTIVE)
        ->and($referral->block_reasons)->toBeNull();

    // Só a primeira (domínio corporativo) virou sinal.
    expect(selfReferralSignals())->toHaveCount(1);
});

test('mesmo IP usado pelo afiliado dentro da janela é autoindicação; fora da janela não', function () {
    $affiliate = makeAffiliate([
        'last_ip_hash' => IpFingerprint::of('198.51.100.77'),
        'last_ip_at' => now()->subDays(5),
    ]);

    signupWithReferral('hugo@transportes-beta.com.br', referralCookieValue($affiliate->code), '198.51.100.77');
    expect(Referral::query()->sole()->block_reasons)->toBe([Referral::REASON_SAME_IP]);
    expect(selfReferralSignals()->sole()->evidence['same_ip'] ?? null)->toBeTrue();

    $old = makeAffiliate([
        'last_ip_hash' => IpFingerprint::of('198.51.100.88'),
        'last_ip_at' => now()->subDays(90),
        'application_ip_hash' => IpFingerprint::of('198.51.100.88'),
    ]);

    Referral::query()->delete();
    signupWithReferral('iris@transportes-gama.com.br', referralCookieValue($old->code), '198.51.100.88');
    expect(Referral::query()->sole()->status)->toBe(Referral::STATUS_ACTIVE);
});

test('o IP nunca é guardado em claro', function () {
    $affiliate = makeAffiliate();

    signupWithReferral('jonas@grafica-delta.com.br', referralCookieValue($affiliate->code), '198.51.100.99');

    $raw = DB::table('referrals')->first();
    expect($raw->signup_ip_hash)->toBe(IpFingerprint::of('198.51.100.99'))
        ->and(json_encode($raw))->not->toContain('198.51.100.99');
});

test('mesmo usuário do afiliado é autoindicação (regra de serviço)', function () {
    $affiliate = makeAffiliate();

    $reasons = app(Attribution::class)->selfReferralReasons($affiliate->fresh(), $affiliate->user, null, now());

    expect($reasons)->toContain(Referral::REASON_SAME_USER)
        ->and($reasons)->toContain(Referral::REASON_SAME_EMAIL);
});

test('mesmo IP de outra indicação do afiliado dentro da janela: segurada até revisão humana', function () {
    $affiliate = makeAffiliate();
    ['referral' => $earlier] = referOrganization($affiliate, attributedAt: now()->subDays(5));
    $earlier->forceFill(['signup_ip_hash' => IpFingerprint::of('198.51.100.55')])->save();

    signupWithReferral('karla@padaria-epsilon.com.br', referralCookieValue($affiliate->code), '198.51.100.55');

    $held = Referral::query()->whereKeyNot($earlier->id)->sole();
    expect($held->status)->toBe(Referral::STATUS_HELD)
        ->and($held->block_reasons)->toBe([Referral::REASON_DUPLICATE_IP]);

    $signal = selfReferralSignals()->sole();
    expect($signal->organization_id)->toBe($held->organization_id)
        ->and($signal->evidence['match'] ?? null)->toBe(Referral::REASON_DUPLICATE_IP)
        ->and($signal->evidence['same_user'] ?? null)->toBeFalse();

    // A comissão nasce, mas não é aprovada enquanto a indicação estiver em revisão.
    paymentFor($held->organization, paidAt: now());
    $this->travel(31)->days();
    app(CommissionLedger::class)->approveDue();
    expect(Commission::query()->sole()->status)->toBe(Commission::STATUS_PENDING);

    // Revisão humana libera → aprovada na próxima execução.
    $admin = User::factory()->platformAdmin()->create();
    $this->actingAs($admin)->post(route('admin.affiliates.referrals.review', $held), [
        'decision' => 'release',
        'note' => 'Mesmo escritório de contabilidade, contas legítimas.',
    ])->assertRedirect();

    $held->refresh();
    expect($held->status)->toBe(Referral::STATUS_ACTIVE)
        ->and($held->reviewed_by_user_id)->toBe($admin->id)
        ->and($held->reviewed_at)->not->toBeNull();

    app(CommissionLedger::class)->approveDue();
    expect(Commission::query()->sole()->status)->toBe(Commission::STATUS_APPROVED);

    $event = DB::table('affiliate_events')->where('action', 'referral.reviewed')->sole();
    expect($event->actor_user_id)->toBe($admin->id)
        ->and(json_decode($event->payload, true))->toMatchArray(['from' => 'held', 'to' => 'active']);
});

test('o afiliado pede revisão humana de uma indicação barrada; a revisão pode liberar e gerar comissão', function () {
    $affiliate = makeAffiliate(user: User::factory()->create(['email' => 'lia@contabil-zeta.com.br']));
    signupWithReferral('compras@contabil-zeta.com.br', referralCookieValue($affiliate->code));
    $referral = Referral::query()->sole();
    expect($referral->status)->toBe(Referral::STATUS_REJECTED);

    paymentFor($referral->organization);
    expect(Commission::query()->count())->toBe(0);

    auth()->logout();
    $this->actingAs($affiliate->user)
        ->post(route('affiliates.referrals.review', $referral), ['message' => 'Filial com CNPJ próprio.'])
        ->assertRedirect()
        ->assertSessionHas('success');
    expect($referral->fresh()->review_requested_at)->not->toBeNull();

    // Um segundo pedido não é aceito.
    $this->actingAs($affiliate->user)->post(route('affiliates.referrals.review', $referral))->assertSessionHas('warning');

    $admin = User::factory()->platformAdmin()->create();
    $this->actingAs($admin)->post(route('admin.affiliates.referrals.review', $referral), [
        'decision' => 'release',
        'note' => 'Filial confirmada, CNPJ distinto.',
    ])->assertRedirect();

    // Liberada: o pagamento já aprovado passa a gerar comissão (pendente, com o prazo normal).
    expect(Commission::query()->sole()->status)->toBe(Commission::STATUS_PENDING);
});

test('revisão que rejeita reverte pendentes e estorna aprovadas no próximo lote', function () {
    $affiliate = makeAffiliate();
    ['organization' => $organization, 'referral' => $referral] = referOrganization($affiliate);

    $old = paymentFor($organization, paidAt: now()->subDays(40));
    app(CommissionLedger::class)->approveDue();
    $new = paymentFor($organization, paidAt: now());

    $admin = User::factory()->platformAdmin()->create();
    $this->actingAs($admin)->post(route('admin.affiliates.referrals.review', $referral), [
        'decision' => 'reject',
        'note' => 'Autoindicação confirmada pela equipe.',
    ])->assertRedirect();

    expect(Commission::query()->where('payment_id', $new->id)->sole()->status)->toBe(Commission::STATUS_REVERSED);

    $reversal = Commission::query()->where('payment_id', $old->id)->where('kind', Commission::KIND_REVERSAL)->sole();
    expect($reversal->amount_cents)->toBe(-1_000)
        ->and($reversal->status)->toBe(Commission::STATUS_APPROVED)
        ->and($reversal->reversal_reason)->toBe(Commission::REASON_REFERRAL_REJECTED);
});

test('a revisão exige justificativa e só a equipe da plataforma revisa', function () {
    $affiliate = makeAffiliate();
    ['referral' => $referral, 'owner' => $owner] = referOrganization($affiliate, Referral::STATUS_HELD);

    $this->actingAs($owner)->post(route('admin.affiliates.referrals.review', $referral), ['decision' => 'release', 'note' => 'Quero liberar.'])
        ->assertForbidden();

    $admin = User::factory()->platformAdmin()->create();
    $this->actingAs($admin)->post(route('admin.affiliates.referrals.review', $referral), ['decision' => 'release'])
        ->assertSessionHasErrors('note');

    expect($referral->fresh()->status)->toBe(Referral::STATUS_HELD);
});

test('pagamento aprovado de organização indicada por autoindicação continua sem comissão mesmo após varredura', function () {
    $affiliate = makeAffiliate();
    ['organization' => $organization] = referOrganization($affiliate, Referral::STATUS_REJECTED);

    paymentFor($organization);
    movePayment(paymentFor($organization, status: PaymentStatus::Pending), PaymentStatus::Approved, ['paid_at' => now()]);
    app(CommissionLedger::class)->sweep();

    expect(Commission::query()->count())->toBe(0);
});
