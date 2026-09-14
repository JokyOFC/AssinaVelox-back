<?php

use App\Enums\AuditEventType;
use App\Enums\DeliveryPurpose;
use App\Enums\DeliveryStatus;
use App\Enums\EnvelopeStatus;
use App\Jobs\Risk\EvaluateRiskEvent;
use App\Models\DeliveryAttempt;
use App\Models\RiskReview;
use App\Models\RiskSignal;
use App\Models\User;
use App\Services\Risk\RiskSignals;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/Support/RiskHelpers.php';
require_once __DIR__.'/../../Sending/Support/SendingHelpers.php';

/*
|--------------------------------------------------------------------------
| Flag `antifraud` desligada = nenhum sinal e nenhuma restrição (roadmap T8)
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    $this->withoutVite();
    // Limiares baixos, mas a flag DESLIGADA.
    riskEnable(enabled: false);
});

test('sem a flag, eventos acima de todos os limiares não geram sinal, observação nem caso', function () {
    Queue::fake();
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    foreach (range(1, 5) as $i) {
        riskSentEnvelope($organization, $owner, ["pessoa{$i}@fora.test"]);
        riskAuditEvent($organization, AuditEventType::ChallengeFailed, ['ip_address' => '203.0.113.9']);
        DeliveryAttempt::factory()->create([
            'organization_id' => $organization->id,
            'purpose' => DeliveryPurpose::Invitation,
            'status' => DeliveryStatus::Bounced,
        ]);
    }

    event(new Registered($owner));

    Queue::assertNotPushed(EvaluateRiskEvent::class);

    expect(RiskSignal::query()->count())->toBe(0)
        ->and(RiskReview::query()->count())->toBe(0)
        ->and(DB::table('risk_observations')->count())->toBe(0)
        ->and(riskStatusOf($organization))->toBe('normal');
});

test('sem a flag, record não persiste e devolve um sinal não gravado', function () {
    ['organization' => $organization] = createOrganizationWithOwner();

    $signal = RiskSignals::record('new_org_send_spike', $organization, ['sent_in_window' => 500]);

    expect($signal->exists)->toBeFalse()
        ->and($signal->rule_code)->toBe('new_org_send_spike')
        ->and(RiskSignal::query()->count())->toBe(0)
        ->and(riskStatusOf($organization))->toBe('normal');
});

test('sem a flag, uma organização que ficou restricted volta a enviar normalmente', function () {
    fakeEmailProvider();
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = readyEnvelope($organization, $owner);
    riskSetStatus($organization, 'restricted');

    actingAsMember($owner, $organization);

    $this->post(route('envelopes.send', $envelope))
        ->assertRedirect(route('envelopes.show', ['envelope' => $envelope, 'sent' => 1]));

    expect($envelope->fresh()->status)->toBe(EnvelopeStatus::InProgress);
});

test('sem a flag, o painel e o pedido de revisão respondem 404', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $admin = User::factory()->platformAdmin()->create();

    $this->actingAs($admin)->get(route('admin.risk.index'))->assertNotFound();
    $this->actingAs($admin)->get(route('admin.risk.precision'))->assertNotFound();

    actingAsMember($owner, $organization);
    $this->get(route('risk.appeal.show'))->assertNotFound();
    $this->post(route('risk.appeal.store'), ['message' => 'Pedido de revisão com a flag desligada.'])->assertNotFound();
});
