<?php

use App\Enums\AuditEventType;
use App\Enums\DeliveryPurpose;
use App\Enums\DeliveryStatus;
use App\Models\DeliveryAttempt;
use App\Models\Envelope;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\PaymentChargeback;
use App\Models\Recipient;
use App\Models\User;
use App\Services\Risk\RiskSignals;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

require_once __DIR__.'/Support/RiskHelpers.php';

/*
|--------------------------------------------------------------------------
| Cada regra dispara NO limiar e não dispara ABAIXO dele (eventos sintéticos)
|--------------------------------------------------------------------------
| Limiares de teste (riskEnable): pico 3 envios; falha de entrega ≥ 4 tentativas e 50%;
| força bruta 3 por link / 5 por rede; destinatários externos 4; contestações 2; cadastros 3.
*/

beforeEach(function (): void {
    $this->withoutVite();
    riskEnable();
    Notification::fake();
});

function riskRecipientOf(Organization $organization, User $owner): Recipient
{
    $envelope = Envelope::factory()->forOrganization($organization, $owner)->inProgress()->create();

    return Recipient::factory()->forEnvelope($envelope)->create(['email' => 'alvo@cliente.test']);
}

function riskDelivery(Organization $organization, Recipient $recipient, DeliveryStatus $status, DeliveryPurpose $purpose = DeliveryPurpose::Invitation): DeliveryAttempt
{
    return DeliveryAttempt::factory()->create([
        'organization_id' => $organization->id,
        'recipient_id' => $recipient->id,
        'envelope_id' => $recipient->envelope_id,
        'purpose' => $purpose,
        'status' => $status,
    ]);
}

test('pico de envios em conta nova: dispara no 3º envio, não no 2º, uma vez por janela', function () {
    riskEnable(['external_recipients_burst' => ['threshold' => 1000]]);
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    riskSentEnvelope($organization, $owner);
    riskSentEnvelope($organization, $owner);
    expect(riskSignalsOf($organization, 'new_org_send_spike'))->toHaveCount(0);

    $third = riskSentEnvelope($organization, $owner);
    $signals = riskSignalsOf($organization, 'new_org_send_spike');

    expect($signals)->toHaveCount(1)
        ->and($signals[0]->envelope_id)->toBe($third->id)
        ->and($signals[0]->score)->toBe(40)
        ->and($signals[0]->evidence)->toMatchArray(['sent_in_window' => 3, 'threshold' => 3, 'organization_age_days' => 0]);

    riskSentEnvelope($organization, $owner);
    expect(riskSignalsOf($organization, 'new_org_send_spike'))->toHaveCount(1);
});

test('pico de envios não vale para conta mais antiga que o limite', function () {
    riskEnable(['external_recipients_burst' => ['threshold' => 1000]]);
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    Organization::query()->whereKey($organization->id)->update(['created_at' => Carbon::now()->subDays(30)]);

    foreach (range(1, 4) as $i) {
        riskSentEnvelope($organization, $owner);
    }

    expect(riskSignalsOf($organization))->toHaveCount(0);
});

test('destinatários externos: conta endereços distintos fora dos domínios da equipe e dispara no 4º', function () {
    riskEnable(['new_org_send_spike' => ['threshold' => 1000]]);
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner(owner: User::factory()->create(['email' => 'dono@acme.test']));

    // colega@acme.test é da equipe; A@FORA.TEST é o mesmo que a@fora.test.
    riskSentEnvelope($organization, $owner, ['colega@acme.test', 'a@fora.test', 'b@fora.test', 'A@FORA.TEST']);
    riskSentEnvelope($organization, $owner, ['c@fora.test']);
    expect(riskSignalsOf($organization, 'external_recipients_burst'))->toHaveCount(0);

    riskSentEnvelope($organization, $owner, ['outro@acme.test', 'd@longe.test']);
    $signals = riskSignalsOf($organization, 'external_recipients_burst');

    expect($signals)->toHaveCount(1)
        ->and($signals[0]->evidence)->toMatchArray(['distinct_external_recipients' => 4, 'envelopes_in_window' => 3, 'threshold' => 4]);
});

test('força bruta de código ou PIN por link: dispara na 3ª tentativa errada, não na 2ª', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $recipient = riskRecipientOf($organization, $owner);
    $attributes = ['envelope_id' => $recipient->envelope_id, 'recipient_id' => $recipient->id];

    riskAuditEvent($organization, AuditEventType::ChallengeFailed, $attributes);
    riskAuditEvent($organization, AuditEventType::ChallengeFailed, $attributes);
    expect(riskSignalsOf($organization, 'code_brute_force'))->toHaveCount(0);

    riskAuditEvent($organization, AuditEventType::ChallengePinFailed, $attributes);
    $signals = riskSignalsOf($organization, 'code_brute_force');

    expect($signals)->toHaveCount(1)
        ->and($signals[0]->evidence)->toMatchArray(['scope' => 'link', 'failures_in_window' => 3, 'recipient' => $recipient->ulid])
        ->and($signals[0]->envelope_id)->toBe($recipient->envelope_id);
});

test('força bruta por rede: dispara na 5ª falha do mesmo IP, guarda só o prefixo', function () {
    ['organization' => $organization] = createOrganizationWithOwner();

    foreach (range(1, 4) as $i) {
        riskAuditEvent($organization, AuditEventType::ChallengeFailed, ['ip_address' => '203.0.113.50']);
    }
    riskAuditEvent($organization, AuditEventType::ChallengeFailed, ['ip_address' => '203.0.113.51']);
    expect(riskSignalsOf($organization, 'code_brute_force'))->toHaveCount(0);

    riskAuditEvent($organization, AuditEventType::ChallengeFailed, ['ip_address' => '203.0.113.50']);
    $signals = riskSignalsOf($organization, 'code_brute_force');

    expect($signals)->toHaveCount(1)
        ->and($signals[0]->evidence)->toMatchArray(['scope' => 'ip', 'failures_in_window' => 5, 'ip_prefix' => '203.0.113.0/24']);
});

test('eventos que não são falha de código não contam como força bruta', function () {
    ['organization' => $organization] = createOrganizationWithOwner();

    foreach (range(1, 8) as $i) {
        riskAuditEvent($organization, AuditEventType::ChallengeVerified, ['ip_address' => '203.0.113.50']);
    }

    expect(riskSignalsOf($organization))->toHaveCount(0);
});

test('taxa de falha de entrega: exige volume mínimo e dispara ao atingir 50%', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $recipient = riskRecipientOf($organization, $owner);

    // Falhas de OTP não contam (a regra olha convite, reenvio e lembrete).
    foreach (range(1, 6) as $i) {
        riskDelivery($organization, $recipient, DeliveryStatus::Failed, DeliveryPurpose::Otp);
    }

    riskDelivery($organization, $recipient, DeliveryStatus::Sent);
    riskDelivery($organization, $recipient, DeliveryStatus::Sent);
    riskDelivery($organization, $recipient, DeliveryStatus::Failed);          // 1/3: abaixo do volume mínimo
    expect(riskSignalsOf($organization, 'delivery_failure_rate'))->toHaveCount(0);

    $pending = riskDelivery($organization, $recipient, DeliveryStatus::Sent);
    riskDelivery($organization, $recipient, DeliveryStatus::Bounced);         // 2/5 = 40%: abaixo da taxa
    expect(riskSignalsOf($organization, 'delivery_failure_rate'))->toHaveCount(0);

    $pending->forceFill(['status' => DeliveryStatus::Bounced])->save();       // 3/5 = 60%
    $signals = riskSignalsOf($organization, 'delivery_failure_rate');

    expect($signals)->toHaveCount(1)
        ->and($signals[0]->evidence)->toMatchArray(['attempts_in_window' => 5, 'failed_in_window' => 3, 'failure_rate' => 0.6]);
});

test('pagamento contestado: dispara na 2ª contestação da janela, não na 1ª', function () {
    ['organization' => $organization] = createOrganizationWithOwner();

    $chargeback = function (string $id) use ($organization): PaymentChargeback {
        $payment = Payment::factory()->approved()->create(['organization_id' => $organization->id]);

        return PaymentChargeback::withoutOrganizationScope()->create([
            'organization_id' => $organization->id,
            'payment_id' => $payment->id,
            'provider' => 'mercadopago',
            'provider_chargeback_id' => $id,
            'amount_cents' => 4990,
            'currency' => 'BRL',
            'reason' => 'fraud',
            'live_mode' => false,
            'received_at' => Carbon::now(),
        ]);
    };

    $chargeback('cb-1');
    expect(riskSignalsOf($organization, 'payment_chargeback'))->toHaveCount(0);

    $second = $chargeback('cb-2');
    $signals = riskSignalsOf($organization, 'payment_chargeback');

    expect($signals)->toHaveCount(1)
        ->and($signals[0]->evidence)->toMatchArray(['chargebacks_in_window' => 2, 'window_days' => 90, 'payment' => $second->payment->ulid]);
});

/**
 * Cadastro pelo formulário público real (Fortify dispara `Registered`).
 *
 * @param  array<string, string>  $server
 * @param  array<string, string>  $headers
 */
function riskRegister(object $test, int $index, array $server, array $headers = []): ?Organization
{
    if (auth()->guard('web')->check()) {
        auth()->guard('web')->logout();
    }

    $test->withServerVariables($server)->withHeaders($headers)->post(route('register'), [
        'name' => "Pessoa Série {$index}",
        'email' => "serie{$index}@exemplo.test",
        'organization_name' => "Empresa Série {$index}",
        'password' => 'Senha@Forte123',
        'password_confirmation' => 'Senha@Forte123',
        'terms' => true,
    ])->assertRedirect();

    $user = User::query()->where('email', "serie{$index}@exemplo.test")->firstOrFail();

    return Organization::query()->find($user->current_organization_id);
}

test('cadastro em série da mesma rede: dispara no 3º cadastro, não no 2º', function () {
    $first = riskRegister($this, 1, ['REMOTE_ADDR' => '198.51.100.23']);
    $second = riskRegister($this, 2, ['REMOTE_ADDR' => '198.51.100.99']); // mesma /24

    expect(riskSignalsOf($first))->toHaveCount(0)
        ->and(riskSignalsOf($second))->toHaveCount(0);

    $third = riskRegister($this, 3, ['REMOTE_ADDR' => '198.51.100.7']);
    $signals = riskSignalsOf($third, 'serial_signup');

    expect($signals)->toHaveCount(1)
        ->and($signals[0]->evidence)->toMatchArray(['scope' => 'ip', 'signups_in_window' => 3, 'ip_prefix' => '198.51.100.0/24']);
});

test('cadastro em série do mesmo dispositivo declarado, de redes diferentes', function () {
    riskRegister($this, 1, ['REMOTE_ADDR' => '192.0.2.10'], ['X-Device-Id' => 'aparelho-1']);
    riskRegister($this, 2, ['REMOTE_ADDR' => '203.0.113.10'], ['X-Device-Id' => 'aparelho-1']);
    $third = riskRegister($this, 3, ['REMOTE_ADDR' => '198.51.100.10'], ['X-Device-Id' => 'aparelho-1']);

    $signals = riskSignalsOf($third, 'serial_signup');

    expect($signals)->toHaveCount(1)
        ->and($signals[0]->evidence)->toMatchArray(['scope' => 'device', 'signups_in_window' => 3])
        ->and($signals[0]->evidence)->not->toHaveKey('ip_prefix');
});

test('autoindicação de afiliado entra pelo contrato RiskSignals::record e fica limitada a observação', function () {
    ['organization' => $organization] = createOrganizationWithOwner();

    $signal = app(RiskSignals::class)->record('affiliate_self_referral', $organization, [
        'affiliate' => '01K7AAAAAAAAAAAAAAAAAAAAAA',
        'referral' => '01K7BBBBBBBBBBBBBBBBBBBBBB',
        'match' => 'same_user',
        'same_user' => true,
    ], null, 'referral:01K7BBBBBBBBBBBBBBBBBBBBBB');

    expect($signal->exists)->toBeTrue()
        ->and($signal->score)->toBe(50)
        ->and($signal->evidence)->toMatchArray(['match' => 'same_user', 'same_user' => true])
        ->and(riskStatusOf($organization))->toBe('watch');
});
