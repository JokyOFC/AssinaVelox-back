<?php

use App\Enums\MembershipRole;
use App\Models\PlatformAuditEvent;
use App\Models\RiskReview;
use App\Services\Risk\Notifications\OrganizationRiskNotice;
use App\Services\Risk\RiskAssessment;
use App\Services\Risk\RiskReviewStatus;
use App\Services\Risk\RiskSignals;
use Illuminate\Support\Facades\Notification;

require_once __DIR__.'/Support/RiskHelpers.php';

/*
|--------------------------------------------------------------------------
| Estado de risco da organização: normal → watch → restricted (roadmap §3.7)
|--------------------------------------------------------------------------
| Transições automáticas só sobem e sempre ficam explicadas (regra + caso) na trilha da
| plataforma. Descer é decisão humana (ReviewQueueTest).
*/

beforeEach(function (): void {
    $this->withoutVite();
    riskEnable();
    Notification::fake();
});

test('pontuação no limiar de observação leva a watch, abre caso e explica a transição na trilha', function () {
    ['organization' => $organization] = createOrganizationWithOwner();

    RiskSignals::record('new_org_send_spike', $organization, ['sent_in_window' => 31, 'window_minutes' => 1440]);

    expect(riskStatusOf($organization))->toBe('watch');

    $review = RiskReview::query()->where('organization_id', $organization->id)->sole();

    expect($review->status)->toBe(RiskReviewStatus::Open)
        ->and($review->trigger)->toBe('signals')
        ->and($review->status_before)->toBe('normal')
        ->and($review->restricted_at)->toBeNull();

    $event = PlatformAuditEvent::query()->where('action', 'risk.status_changed')->sole();

    expect($event->actor_user_id)->toBeNull()
        ->and($event->organization_id)->toBe($organization->id)
        ->and($event->payload)->toMatchArray([
            'from' => 'normal',
            'to' => 'watch',
            'origin' => 'automatic',
            'review' => $review->ulid,
            'rules' => ['new_org_send_spike'],
        ]);

    Notification::assertNothingSent();
});

test('abaixo do limiar de observação nada muda e nenhum caso é aberto', function () {
    riskEnable(['new_org_send_spike' => ['score' => 29]]);
    ['organization' => $organization] = createOrganizationWithOwner();

    RiskSignals::record('new_org_send_spike', $organization, ['sent_in_window' => 31]);

    expect(riskStatusOf($organization))->toBe('normal')
        ->and(riskSignalsOf($organization))->toHaveCount(1)
        ->and(RiskReview::query()->count())->toBe(0)
        ->and(PlatformAuditEvent::query()->count())->toBe(0);
});

test('regras que suspendem envio somando o limiar levam a restricted e avisam owner e admin, não o operador', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $admin = attachMember($organization, MembershipRole::Admin);
    $member = attachMember($organization, MembershipRole::Member);

    $review = riskRestrictViaSignals($organization);

    expect(riskStatusOf($organization))->toBe('restricted')
        ->and($review->restricted_at)->not->toBeNull();

    $transitions = PlatformAuditEvent::query()->where('action', 'risk.status_changed')->orderBy('id')->get();

    expect($transitions->pluck('payload.to')->all())->toBe(['watch', 'restricted'])
        ->and($transitions->last()->payload['rules'])->toBe(['external_recipients_burst', 'new_org_send_spike']);

    Notification::assertSentTo($owner, OrganizationRiskNotice::class, fn (OrganizationRiskNotice $notice) => $notice->kind === 'restricted');
    Notification::assertSentTo($admin, OrganizationRiskNotice::class);
    Notification::assertNotSentTo($member, OrganizationRiskNotice::class);
});

test('regras limitadas a watch (autoindicação, força bruta) nunca suspendem o envio sozinhas', function () {
    ['organization' => $organization] = createOrganizationWithOwner();

    foreach (['a', 'b', 'c'] as $subject) {
        RiskSignals::record('affiliate_self_referral', $organization, ['match' => 'same_user'], null, 'referral:'.$subject);
        RiskSignals::record('code_brute_force', $organization, ['scope' => 'link', 'failures_in_window' => 12], null, 'link:'.$subject);
    }

    expect(riskSignalsOf($organization))->toHaveCount(6)
        ->and(riskSignalsOf($organization)->sum('score'))->toBe(210)
        ->and(riskStatusOf($organization))->toBe('watch');
});

test('com auto_restrict desligado o motor chega no máximo a watch', function () {
    riskEnable(risk: ['auto_restrict' => false]);
    ['organization' => $organization] = createOrganizationWithOwner();

    riskRestrictViaSignals($organization);

    expect(riskStatusOf($organization))->toBe('watch');
    Notification::assertNothingSent();
});

test('organização na lista de confiança tem sinais gravados, mas não muda de estado nem abre caso', function () {
    ['organization' => $organization] = createOrganizationWithOwner();
    riskEnable(risk: ['trusted_organizations' => [$organization->ulid]]);

    RiskSignals::record('new_org_send_spike', $organization, ['sent_in_window' => 40]);
    RiskSignals::record('external_recipients_burst', $organization, ['distinct_external_recipients' => 400]);

    expect(riskSignalsOf($organization))->toHaveCount(2)
        ->and(riskStatusOf($organization))->toBe('normal')
        ->and(RiskReview::query()->count())->toBe(0);
});

test('transição automática nunca rebaixa: restricted continua restricted com novos sinais', function () {
    ['organization' => $organization] = createOrganizationWithOwner();
    riskRestrictViaSignals($organization);

    RiskSignals::record('code_brute_force', $organization, ['scope' => 'link'], null, 'link:1');
    app(RiskAssessment::class)->evaluate($organization);

    expect(riskStatusOf($organization))->toBe('restricted')
        ->and(RiskReview::query()->where('organization_id', $organization->id)->count())->toBe(1);
});

test('o mesmo sinal na mesma janela é idempotente (não soma pontos de novo)', function () {
    ['organization' => $organization] = createOrganizationWithOwner();

    $first = RiskSignals::record('code_brute_force', $organization, ['scope' => 'link'], null, 'link:7');
    $second = RiskSignals::record('code_brute_force', $organization, ['scope' => 'link'], null, 'link:7');

    expect($second->id)->toBe($first->id)
        ->and(riskSignalsOf($organization))->toHaveCount(1);
});
