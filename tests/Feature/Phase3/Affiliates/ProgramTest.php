<?php

use App\Models\Affiliate;
use App\Models\Referral;
use App\Models\User;
use App\Services\Affiliates\PayoutDetails;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Support/AffiliateHelpers.php';

/*
|--------------------------------------------------------------------------
| Cadastro de afiliados, aprovação pela operadora, taxa com trilha, dados de repasse
| cifrados e mascarados, portal sem dados pessoais de indicados.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    $this->withoutVite();
    enableAffiliates();
    fakeAffiliateRisk();
    $this->admin = User::factory()->platformAdmin()->create();
});

test('candidatura pelo portal: pendente, sem código, termos e dados de repasse cifrados em repouso', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('affiliates.apply'), affiliatePayoutInput())->assertSessionHasErrors('terms');

    $this->actingAs($user)->post(route('affiliates.apply'), [...affiliatePayoutInput(), 'terms' => true])
        ->assertRedirect()
        ->assertSessionHas('success');

    $affiliate = Affiliate::query()->sole();
    expect($affiliate->status)->toBe(Affiliate::STATUS_PENDING)
        ->and($affiliate->code)->toBeNull()
        ->and($affiliate->terms_version)->toBe(config('assinavelox.affiliates.terms_version'))
        ->and($affiliate->payout_details['pix_key'])->toBe('52998224725');

    $raw = (string) DB::table('affiliates')->value('payout_details');
    expect($raw)->not->toContain('52998224725')
        ->and($raw)->not->toContain('Paula')
        ->and($raw)->not->toBe('');

    // Uma segunda candidatura não é aceita.
    $this->actingAs($user)->post(route('affiliates.apply'), [...affiliatePayoutInput(), 'terms' => true])
        ->assertSessionHasErrors('application');
});

test('chave PIX incompatível com o tipo e CPF inválido são recusados', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('affiliates.apply'), [
        'pix_key_type' => 'email',
        'pix_key' => '12345',
        'holder_name' => 'Fulano de Tal',
        'holder_tax_id' => '111.111.111-11',
        'terms' => true,
    ])->assertSessionHasErrors(['pix_key', 'holder_tax_id']);

    expect(Affiliate::query()->count())->toBe(0);
});

test('mascaramento nunca devolve o valor inteiro', function () {
    $masked = PayoutDetails::mask([
        'pix_key_type' => 'email',
        'pix_key' => 'paula.parceira@exemplo.com.br',
        'holder_name' => 'Paula Parceira Souza',
        'holder_tax_id' => '52998224725',
    ]);

    expect($masked['pix_key'])->toBe('p•••@e•••.br')
        ->and($masked['holder_name'])->toBe('Paula P••••••• S••••')
        ->and($masked['holder_tax_id'])->toBe('•••••••••25')
        ->and(PayoutDetails::maskTail('12345678901', 3))->toBe('••••••••901')
        ->and(PayoutDetails::maskTail('ab', 4))->toBe('••');
});

test('a operadora aprova com código único e taxa; recusa e suspensão exigem motivo', function () {
    $pending = Affiliate::query()->create([
        'user_id' => User::factory()->create()->id,
        'commission_rate_bp' => 1000,
        'status' => Affiliate::STATUS_PENDING,
        'payout_details' => ['pix_key_type' => 'cpf', 'pix_key' => '52998224725', 'holder_name' => 'Ana', 'holder_tax_id' => '52998224725'],
        'terms_version' => 'v1',
        'terms_accepted_at' => now(),
    ]);

    $this->actingAs($this->admin)->get(route('admin.affiliates.index'))
        ->assertInertia(fn ($page) => $page
            ->component('admin/affiliates/index')
            ->where('summary.pending', 1)
            ->where('affiliates.data.0.status', 'pending'));

    $this->actingAs($this->admin)->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('admin.affiliates.approve', $pending), ['commission_rate_bp' => 1500])
        ->assertRedirect();

    $pending->refresh();
    expect($pending->status)->toBe(Affiliate::STATUS_APPROVED)
        ->and($pending->code)->toMatch('/^[A-Z2-9]{8}$/')
        ->and($pending->commission_rate_bp)->toBe(1500)
        ->and($pending->approved_by_user_id)->toBe($this->admin->id);

    $this->actingAs($this->admin)->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('admin.affiliates.suspend', $pending), [])
        ->assertSessionHasErrors('reason');

    $this->actingAs($this->admin)->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('admin.affiliates.suspend', $pending), ['reason' => 'Uso indevido da marca.'])
        ->assertRedirect();
    expect($pending->fresh()->status)->toBe(Affiliate::STATUS_SUSPENDED);

    $actions = DB::table('affiliate_events')->where('affiliate_id', $pending->id)->orderBy('id')->pluck('action')->all();
    expect($actions)->toBe(['affiliate.approved', 'affiliate.suspended']);
});

test('alteração de taxa exige senha e motivo e fica na trilha (antes → depois, quem)', function () {
    $affiliate = makeAffiliate();

    $this->actingAs($this->admin)->put(route('admin.affiliates.rate.update', $affiliate), ['commission_rate_bp' => 1500, 'reason' => 'Nova faixa.'])
        ->assertRedirect(route('password.confirm'));

    $confirmed = fn () => $this->actingAs($this->admin)->withSession(['auth.password_confirmed_at' => time()]);

    $confirmed()->put(route('admin.affiliates.rate.update', $affiliate), ['commission_rate_bp' => 1500])
        ->assertSessionHasErrors('reason');
    $confirmed()->put(route('admin.affiliates.rate.update', $affiliate), ['commission_rate_bp' => 9000, 'reason' => 'Acima do teto.'])
        ->assertSessionHasErrors('commission_rate_bp');

    $confirmed()->put(route('admin.affiliates.rate.update', $affiliate), ['commission_rate_bp' => 1500, 'reason' => 'Nova faixa de volume.'])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($affiliate->fresh()->commission_rate_bp)->toBe(1500);

    $event = DB::table('affiliate_events')->where('action', 'affiliate.rate_changed')->sole();
    expect($event->actor_user_id)->toBe($this->admin->id)
        ->and(json_decode($event->payload, true))->toBe(['from_bp' => 1000, 'to_bp' => 1500, 'reason' => 'Nova faixa de volume.']);

    $this->actingAs($this->admin)->get(route('admin.affiliates.show', $affiliate))
        ->assertInertia(fn ($page) => $page
            ->component('admin/affiliates/show')
            ->where('trail.0.action', 'affiliate.rate_changed')
            ->where('trail.0.actor', $this->admin->name)
            ->where('affiliate.payout.pix_key', '••••••••725'));
});

test('portal do afiliado: código, link, comissões por estado e indicados só com o nome da organização', function () {
    $affiliate = makeAffiliate();
    ['organization' => $organization, 'owner' => $owner] = referOrganization($affiliate);
    paymentFor($organization, paidAt: now());

    $response = $this->actingAs($affiliate->user)->get(route('affiliates.index'));

    $response->assertInertia(fn ($page) => $page
        ->component('affiliates/index')
        ->where('affiliate.code', $affiliate->code)
        ->where('affiliate.link', route('affiliates.link', ['code' => $affiliate->code]))
        ->where('affiliate.payout.pix_key', '••••••••725')
        ->where('totals.0.pending_cents', 1_000)
        ->has('referrals', 1)
        ->where('referrals.0.organization_name', $organization->name)
        ->missing('referrals.0.affiliate')
        ->missing('referrals.0.organization_id')
        ->has('commissions.data', 1));

    $json = json_encode($response->viewData('page'));
    expect($json)->not->toContain($owner->email)
        ->and($json)->not->toContain('52998224725');

    // O IP do portal é guardado só como HMAC.
    expect($affiliate->fresh()->last_ip_hash)->not->toBeNull()
        ->and($affiliate->fresh()->last_ip_hash)->not->toContain('127.0.0.1');
});

test('atualizar dados de repasse exige senha e não registra o valor na trilha', function () {
    $affiliate = makeAffiliate();

    $this->actingAs($affiliate->user)->put(route('affiliates.payout.update'), [
        'pix_key_type' => 'email', 'pix_key' => 'novo.pix@parceira.com.br', 'holder_name' => 'Paula Souza', 'holder_tax_id' => '52998224725',
    ])->assertRedirect(route('password.confirm'));

    $this->actingAs($affiliate->user)->withSession(['auth.password_confirmed_at' => time()])->put(route('affiliates.payout.update'), [
        'pix_key_type' => 'email', 'pix_key' => 'novo.pix@parceira.com.br', 'holder_name' => 'Paula Souza', 'holder_tax_id' => '52998224725',
    ])->assertRedirect()->assertSessionHas('success');

    expect($affiliate->fresh()->payout_details['pix_key'])->toBe('novo.pix@parceira.com.br');

    $events = DB::table('affiliate_events')->where('action', 'affiliate.payout_details_updated')->get();
    expect($events)->toHaveCount(1)
        ->and((string) $events[0]->payload)->not->toContain('novo.pix')
        ->and((string) $events[0]->payload)->not->toContain('52998224725');
});

test('relatório do afiliado exportável em CSV, protegido contra fórmula', function () {
    $affiliate = makeAffiliate();
    ['organization' => $organization] = referOrganization($affiliate);
    $organization->forceFill(['name' => '@SUM(1+1)'])->save();
    paymentFor($organization);

    $response = $this->actingAs($affiliate->user)->get(route('affiliates.commissions.export'));
    $response->assertOk();

    $csv = $response->streamedContent();
    expect($csv)->toContain("'@SUM(1+1)")
        ->and($csv)->toContain('Pendente')
        ->and($csv)->toContain('10,00');
});

test('quem não é afiliado vê só a candidatura; outro usuário não pede revisão de indicação alheia', function () {
    $affiliate = makeAffiliate();
    ['referral' => $referral] = referOrganization($affiliate, Referral::STATUS_HELD);
    $stranger = User::factory()->create();

    $this->actingAs($stranger)->get(route('affiliates.index'))
        ->assertInertia(fn ($page) => $page->component('affiliates/index')->where('affiliate', null)->where('referrals', []));

    $this->actingAs($stranger)->post(route('affiliates.referrals.review', $referral))->assertNotFound();
    $this->actingAs($stranger)->get(route('admin.affiliates.index'))->assertForbidden();
});
