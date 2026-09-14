<?php

use App\Enums\EnvelopeStatus;
use App\Models\AuditEvent;
use App\Models\PlanConsumption;
use App\Models\SignatureAcceptance;
use App\Services\Envelopes\Sending\SendEnvelope;
use App\Services\Plans\Exceptions\SendingBlockedException;
use Illuminate\Support\Facades\File;

require_once __DIR__.'/Support/RiskHelpers.php';
require_once __DIR__.'/../../Sending/Support/SendingHelpers.php';
require_once __DIR__.'/../../Sign/Support/SignerHelpers.php';

/*
|--------------------------------------------------------------------------
| `restricted` bloqueia SÓ o envio de novos envelopes (roadmap §3.7)
|--------------------------------------------------------------------------
| Leitura, aceite, assinatura e download de envelopes já enviados continuam; aceites e
| evidências já registrados não são tocados.
*/

// Sem Notification::fake(): o fluxo do signatário lê o OTP pelo evento NotificationSending
// (signerCaptureCodes), que o fake suprime. Os avisos do antifraude saem pelo mailer `array`.
beforeEach(function (): void {
    $this->withoutVite();
    riskEnable();
});

test('organização restricted não envia: mensagem clara com o canal de revisão, envelope continua pronto e o plano não é debitado', function () {
    fakeEmailProvider();
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = readyEnvelope($organization, $owner);
    riskSetStatus($organization, 'restricted');

    actingAsMember($owner, $organization);

    $this->post(route('envelopes.send', $envelope))
        ->assertRedirect()
        ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'suspenso')
            && str_contains($message, 'Documentos já enviados continuam disponíveis')
            // Revisão adversarial I-3A (roadmap §3.7, "mensagem clara"): o canal é nomeado pela
            // página, não por um caminho cru de URL no meio do toast; o link fica na faixa do app.
            && str_contains($message, 'Revisão de segurança da conta')
            && ! str_contains($message, route('risk.appeal.show', [], false)));

    $envelope->refresh();

    expect($envelope->status)->toBe(EnvelopeStatus::Ready)
        ->and($envelope->sent_at)->toBeNull()
        ->and(PlanConsumption::withoutOrganizationScope()->where('envelope_id', $envelope->id)->count())->toBe(0);
});

test('o serviço de envio (usado também por API, agendamento e formulário público) lança SendingBlockedException risk_restricted', function () {
    fakeEmailProvider();
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = readyEnvelope($organization, $owner);
    riskSetStatus($organization, 'restricted');

    try {
        app(SendEnvelope::class)->handle($envelope);
        $this->fail('O envio deveria ter sido bloqueado.');
    } catch (SendingBlockedException $exception) {
        expect($exception->errorCode)->toBe('risk_restricted');
    }

    expect($envelope->fresh()->status)->toBe(EnvelopeStatus::Ready);
});

test('watch não bloqueia o envio', function () {
    fakeEmailProvider();
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = readyEnvelope($organization, $owner);
    riskSetStatus($organization, 'watch');

    actingAsMember($owner, $organization);

    $this->post(route('envelopes.send', $envelope))
        ->assertRedirect(route('envelopes.show', ['envelope' => $envelope, 'sent' => 1]));

    expect($envelope->fresh()->status)->toBe(EnvelopeStatus::InProgress);
});

describe('envelopes já enviados de uma organização restricted', function () {
    beforeEach(function (): void {
        $this->work = storage_path('framework/testing/risk-restricted-'.uniqid());
        File::ensureDirectoryExists($this->work);
        signerDisk($this->work);
        $this->codes = signerCaptureCodes();
    });

    afterEach(function (): void {
        File::deleteDirectory($this->work ?? '');
    });

    test('continuam legíveis pelo remetente e o participante ainda aceita, assina e baixa o comprovante', function () {
        ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
        $ctx = signerEnvelope(organization: $organization, owner: $owner);
        riskSetStatus($organization, 'restricted');

        actingAsMember($owner, $organization);
        $this->get(route('envelopes.show', $ctx['envelope']))->assertOk();

        $token = $ctx['tokens']['maria@exemplo.test'];
        $props = authenticateSigner($this, $token);

        $this->post(route('sign.complete', ['token' => $token]), [
            'authorization' => $props['authorization']['token'],
            'consent' => true,
            'signature' => ['method' => 'draw', 'image_base64' => pngDataUri()],
        ])->assertRedirect();

        expect(SignatureAcceptance::withoutOrganizationScope()->where('recipient_id', $ctx['recipients']['maria@exemplo.test']->id)->exists())->toBeTrue();

        $this->get(route('sign.download', ['token' => $token, 'type' => 'evidence']))->assertOk();

        expect(riskStatusOf($organization))->toBe('restricted');
    });

    test('aceites e trilha registrados antes da restrição continuam intactos depois dela', function () {
        ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
        $ctx = signerEnvelope(organization: $organization, owner: $owner);
        $token = $ctx['tokens']['maria@exemplo.test'];
        $props = authenticateSigner($this, $token);

        $this->post(route('sign.complete', ['token' => $token]), [
            'authorization' => $props['authorization']['token'],
            'consent' => true,
            'signature' => ['method' => 'draw', 'image_base64' => pngDataUri()],
        ])->assertRedirect();

        $acceptance = SignatureAcceptance::withoutOrganizationScope()->where('envelope_id', $ctx['envelope']->id)->sole();
        $trail = AuditEvent::withoutOrganizationScope()->where('envelope_id', $ctx['envelope']->id)->orderBy('id')->pluck('id')->all();

        riskRestrictViaSignals($organization);
        expect(riskStatusOf($organization))->toBe('restricted');

        expect(SignatureAcceptance::withoutOrganizationScope()->whereKey($acceptance->id)->sole()->toArray())->toBe($acceptance->toArray())
            ->and(AuditEvent::withoutOrganizationScope()->where('envelope_id', $ctx['envelope']->id)->whereIn('id', $trail)->count())->toBe(count($trail))
            ->and($ctx['envelope']->fresh()->status)->not->toBe(EnvelopeStatus::Canceled);
    });
});
