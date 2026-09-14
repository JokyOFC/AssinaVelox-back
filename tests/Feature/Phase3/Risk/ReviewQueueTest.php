<?php

use App\Enums\MembershipRole;
use App\Models\PlatformAuditEvent;
use App\Models\RiskReview;
use App\Models\RiskSignal;
use App\Models\User;
use App\Services\Risk\Notifications\OrganizationRiskNotice;
use App\Services\Risk\RiskDecision;
use App\Services\Risk\RiskException;
use App\Services\Risk\RiskReviewDecisions;
use App\Services\Risk\RiskReviewStatus;
use App\Services\Risk\RiskSignals;
use App\Services\Risk\SendingRestriction;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/Support/RiskHelpers.php';

/*
|--------------------------------------------------------------------------
| Fila de revisão humana, decisão auditada, pedido de revisão (LGPD art. 20) e precisão
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    $this->withoutVite();
    riskEnable();
    Notification::fake();
});

test('o painel do antifraude é só do platform admin', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $review = riskRestrictViaSignals($organization);
    actingAsMember($owner, $organization);

    $this->get(route('admin.risk.index'))->assertForbidden();
    $this->get(route('admin.risk.show', $review))->assertForbidden();
    $this->get(route('admin.risk.precision'))->assertForbidden();
    $this->post(route('admin.risk.decide', $review), [...riskSeen($review), 'decision' => 'clear', 'reason' => 'Tentativa de liberar a própria conta'])->assertForbidden();

    expect($review->fresh()->status)->toBe(RiskReviewStatus::Open)
        ->and(riskStatusOf($organization))->toBe('restricted');

    $admin = User::factory()->platformAdmin()->create();

    $this->actingAs($admin)->get(route('admin.risk.index'))->assertInertia(fn (Assert $page) => $page
        ->component('admin/risk/index')
        ->has('reviews.data', 1)
        ->where('reviews.data.0.id', $review->ulid)
        ->where('reviews.data.0.organization.risk_status', 'restricted')
        ->where('reviews.data.0.score', 90)
        ->has('reviews.data.0.rules', 2)
        ->where('counts.open', 1));
});

test('o detalhe do caso mostra regra, evidência minimizada e histórico explicável', function () {
    ['organization' => $organization] = createOrganizationWithOwner();
    $review = riskRestrictViaSignals($organization);
    $admin = User::factory()->platformAdmin()->create();

    $this->actingAs($admin)->get(route('admin.risk.show', $review))->assertInertia(fn (Assert $page) => $page
        ->component('admin/risk/show')
        ->where('can_decide', true)
        ->where('organization.risk_status', 'restricted')
        ->has('signals', 2)
        ->where('signals.0.rule', 'external_recipients_burst')
        ->where('signals.0.evidence.0.key', 'distinct_external_recipients')
        ->where('signals.0.evidence.0.value', '200')
        ->has('history', 2)
        ->where('history.0.action', 'risk.status_changed')
        ->where('history.0.actor', 'Sistema (automático)')
        ->has('decisions', 3));
});

test('decisão exige motivo; sem ele nada muda', function () {
    ['organization' => $organization] = createOrganizationWithOwner();
    $review = riskRestrictViaSignals($organization);
    $admin = User::factory()->platformAdmin()->create();

    $this->actingAs($admin)->post(route('admin.risk.decide', $review), [...riskSeen($review), 'decision' => 'clear', 'reason' => ''])
        ->assertSessionHasErrors('reason');
    $this->actingAs($admin)->post(route('admin.risk.decide', $review), [...riskSeen($review), 'decision' => 'clear', 'reason' => 'curto'])
        ->assertSessionHasErrors('reason');
    $this->actingAs($admin)->post(route('admin.risk.decide', $review), [...riskSeen($review), 'decision' => 'apagar', 'reason' => 'Motivo suficientemente longo'])
        ->assertSessionHasErrors('decision');

    expect(fn () => app(RiskReviewDecisions::class)->decide($review, RiskDecision::Clear, '   ', $admin))
        ->toThrow(RiskException::class);

    expect($review->fresh()->status)->toBe(RiskReviewStatus::Open)
        ->and(riskStatusOf($organization))->toBe('restricted')
        ->and(PlatformAuditEvent::query()->where('action', 'risk.review_decided')->count())->toBe(0);
});

test('liberar volta a organização a normal, registra autor e motivo na trilha e reabre o envio', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $review = riskRestrictViaSignals($organization);
    $admin = User::factory()->platformAdmin()->create();
    $reason = 'Cliente legítimo: campanha anual confirmada por telefone.';

    $this->actingAs($admin)->post(route('admin.risk.decide', $review), [...riskSeen($review), 'decision' => 'clear', 'reason' => $reason])
        ->assertRedirect(route('admin.risk.show', $review))
        ->assertSessionHas('success');

    $review->refresh();

    expect($review->status)->toBe(RiskReviewStatus::Cleared)
        ->and($review->decision)->toBe(RiskDecision::Clear)
        ->and($review->decision_reason)->toBe($reason)
        ->and($review->reviewer_id)->toBe($admin->id)
        ->and($review->decided_at)->not->toBeNull()
        ->and($review->through_signal_id)->toBe((int) RiskSignal::query()->max('id'))
        ->and(riskStatusOf($organization))->toBe('normal');

    $decided = PlatformAuditEvent::query()->where('action', 'risk.review_decided')->sole();

    expect($decided->actor_user_id)->toBe($admin->id)
        ->and($decided->payload)->toMatchArray([
            'review' => $review->ulid,
            'decision' => 'clear',
            'reason' => $reason,
            'from' => 'restricted',
            'to' => 'normal',
        ]);

    $changed = PlatformAuditEvent::query()->where('action', 'risk.status_changed')->latest('id')->first();

    expect($changed->actor_user_id)->toBe($admin->id)
        ->and($changed->payload['origin'])->toBe('review');

    app(SendingRestriction::class)->assertCanSend($organization->id);

    Notification::assertSentTo($owner, OrganizationRiskNotice::class, fn ($notice) => $notice->kind === 'decision');
});

test('confirmar restrição mantém restricted; caso decidido não pode ser decidido de novo', function () {
    ['organization' => $organization] = createOrganizationWithOwner();
    $review = riskRestrictViaSignals($organization);
    $admin = User::factory()->platformAdmin()->create();

    $this->actingAs($admin)->post(route('admin.risk.decide', $review), [...riskSeen($review), 'decision' => 'confirm', 'reason' => 'Envio de phishing confirmado por denúncias.'])
        ->assertRedirect();

    expect($review->fresh()->status)->toBe(RiskReviewStatus::Confirmed)
        ->and(riskStatusOf($organization))->toBe('restricted');

    $this->actingAs($admin)->post(route('admin.risk.decide', $review), [...riskSeen($review), 'decision' => 'clear', 'reason' => 'Mudança de ideia depois de decidido'])
        ->assertSessionHas('error');

    expect($review->fresh()->status)->toBe(RiskReviewStatus::Confirmed)
        ->and(PlatformAuditEvent::query()->where('action', 'risk.review_decided')->count())->toBe(1);
});

test('manter em observação libera o envio mas deixa a organização em watch', function () {
    ['organization' => $organization] = createOrganizationWithOwner();
    $review = riskRestrictViaSignals($organization);
    $admin = User::factory()->platformAdmin()->create();

    app(RiskReviewDecisions::class)->decide($review, RiskDecision::Watch, 'Volume alto, mas destinatários parecem clientes reais.', $admin);

    expect($review->fresh()->status)->toBe(RiskReviewStatus::Watching)
        ->and(riskStatusOf($organization))->toBe('watch');

    app(SendingRestriction::class)->assertCanSend($organization->id);
});

test('sinais já liberados não voltam a contar; um sinal novo abre um caso novo', function () {
    ['organization' => $organization] = createOrganizationWithOwner();
    $review = riskRestrictViaSignals($organization);
    $admin = User::factory()->platformAdmin()->create();
    app(RiskReviewDecisions::class)->decide($review, RiskDecision::Clear, 'Liberado depois de conversa com o cliente.', $admin);

    // Mesma regra, mesma janela: idempotente — não há sinal novo, nada muda.
    RiskSignals::record('new_org_send_spike', $organization, ['sent_in_window' => 45]);
    expect(riskStatusOf($organization))->toBe('normal');

    RiskSignals::record('serial_signup', $organization, ['scope' => 'ip', 'signups_in_window' => 5], null, 'ip-key');

    $next = RiskReview::query()->where('organization_id', $organization->id)->where('status', 'open')->sole();

    expect(riskStatusOf($organization))->toBe('watch')
        ->and($next->id)->not->toBe($review->id)
        ->and($next->after_signal_id)->toBe($review->fresh()->through_signal_id)
        ->and($next->signalsQuery()->pluck('rule_code')->all())->toBe(['serial_signup']);
});

test('a organização pede revisão por canal registrado, vê os critérios sem limiares e não pede duas vezes', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $review = riskRestrictViaSignals($organization);
    actingAsMember($owner, $organization);

    $response = $this->get(route('risk.appeal.show'));
    $response->assertInertia(fn (Assert $page) => $page
        ->component('admin/risk-appeal/show')
        ->where('status', 'restricted')
        ->where('can_request', true)
        ->has('rules', 2));

    $props = json_encode($response->viewData('page')['props']);
    expect($props)->not->toContain('threshold')->not->toContain('score')->not->toContain('"200"');

    $this->post(route('risk.appeal.store'), ['message' => 'curto'])->assertSessionHasErrors('message');

    $message = 'Somos uma imobiliária e enviamos a renovação anual dos contratos nesta semana.';
    $this->post(route('risk.appeal.store'), ['message' => $message])->assertSessionHas('success');

    $review->refresh();

    expect($review->appeal_requested_at)->not->toBeNull()
        ->and($review->appeal_requested_by_user_id)->toBe($owner->id)
        ->and($review->appeal_message)->toBe($message);

    $requested = PlatformAuditEvent::query()->where('action', 'risk.review_requested')->sole();

    expect($requested->actor_user_id)->toBe($owner->id)
        ->and($requested->organization_id)->toBe($organization->id)
        ->and($requested->payload)->toBe(['review' => $review->ulid, 'status' => 'restricted']);

    $this->post(route('risk.appeal.store'), ['message' => $message.' (de novo)'])->assertSessionHas('error');

    $admin = User::factory()->platformAdmin()->create();
    $this->actingAs($admin)->get(route('admin.risk.index', ['appeal' => 1]))->assertInertia(fn (Assert $page) => $page
        ->has('reviews.data', 1)
        ->where('reviews.data.0.appeal_requested_at', fn ($value) => $value !== null)
        ->where('counts.appeals', 1));
});

test('só owner e admin pedem revisão; sem restrição ou observação não há o que pedir', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $member = attachMember($organization, MembershipRole::Member);

    actingAsMember($owner, $organization);
    $this->post(route('risk.appeal.store'), ['message' => 'Quero revisão mesmo sem restrição ativa na conta.'])
        ->assertSessionHas('error');
    expect(RiskReview::query()->count())->toBe(0);

    riskRestrictViaSignals($organization);

    actingAsMember($member, $organization);
    $this->get(route('risk.appeal.show'))->assertInertia(fn (Assert $page) => $page->where('can_request', false));
    $this->post(route('risk.appeal.store'), ['message' => 'Operador tentando pedir a revisão da conta.'])->assertForbidden();
});

test('pedido depois de uma restrição confirmada abre um caso novo por pedido de revisão', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $review = riskRestrictViaSignals($organization);
    $admin = User::factory()->platformAdmin()->create();
    app(RiskReviewDecisions::class)->decide($review, RiskDecision::Confirm, 'Denúncias confirmadas pela equipe.', $admin);

    actingAsMember($owner, $organization);
    $this->post(route('risk.appeal.store'), ['message' => 'Corrigimos a lista de contatos e removemos os endereços inválidos.'])
        ->assertSessionHas('success');

    $appeal = RiskReview::query()->where('organization_id', $organization->id)->where('status', 'open')->sole();

    // Revisão adversarial I-3A (LGPD art. 20 §1º): o caso do pedido HERDA o intervalo de sinais
    // do caso confirmado que mantém a restrição — o revisor julga com a mesma evidência e a
    // organização continua vendo os critérios. Antes, começava depois deles e ficava vazio.
    expect($appeal->trigger)->toBe('appeal')
        ->and($appeal->after_signal_id)->toBe($review->fresh()->after_signal_id)
        ->and($appeal->signalsQuery()->count())->toBe(2)
        ->and(riskStatusOf($organization))->toBe('restricted');
});

test('relatório de precisão por regra: confirmados × liberados', function () {
    $admin = User::factory()->platformAdmin()->create();
    $decisions = app(RiskReviewDecisions::class);

    ['organization' => $confirmedOrg] = createOrganizationWithOwner();
    $decisions->decide(riskRestrictViaSignals($confirmedOrg), RiskDecision::Confirm, 'Phishing confirmado por denúncias.', $admin);

    ['organization' => $clearedOrg] = createOrganizationWithOwner();
    RiskSignals::record('new_org_send_spike', $clearedOrg, ['sent_in_window' => 35]);
    $decisions->decide(RiskReview::query()->where('organization_id', $clearedOrg->id)->sole(), RiskDecision::Clear, 'Lançamento legítimo de produto.', $admin);

    ['organization' => $openOrg] = createOrganizationWithOwner();
    RiskSignals::record('external_recipients_burst', $openOrg, ['distinct_external_recipients' => 180]);

    $this->actingAs($admin)->get(route('admin.risk.precision'))->assertInertia(fn (Assert $page) => $page
        ->component('admin/risk/precision')
        ->where('report.rows', function ($rows): bool {
            $byRule = collect($rows)->keyBy('rule');

            return $byRule['new_org_send_spike']['signals'] === 2
                && $byRule['new_org_send_spike']['confirmed'] === 1
                && $byRule['new_org_send_spike']['cleared'] === 1
                && (float) $byRule['new_org_send_spike']['precision'] === 0.5
                && $byRule['external_recipients_burst']['confirmed'] === 1
                && $byRule['external_recipients_burst']['open'] === 1
                && (float) $byRule['external_recipients_burst']['precision'] === 1.0
                && $byRule['serial_signup']['precision'] === null;
        })
        ->where('report.totals.open', 1));
});
