<?php

use App\Enums\AuthMethod;
use App\Enums\DeliveryChannel;
use App\Enums\DeliveryPurpose;
use App\Events\EnvelopeReadyForFinalization;
use App\Integrations\Sms\FakeSmsProvider;
use App\Models\DeliveryAttempt;
use App\Models\SignatureAcceptance;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;

require_once __DIR__.'/../../Phase2/Channels/Support/ChannelHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão adversarial da Fase 2, onda B — a evidência afirma "Código por SMS" quando nada foi enviado
|--------------------------------------------------------------------------
| Com o simulador, o código "enviado por SMS" só foi gravado no SimulatedOutbox: nada foi
| transmitido (delivery_attempts.provider = `sms_simulado`, meta.simulated = true). O sistema
| SABE disso — mas o dossiê de evidências do remetente (EvidenceDossier::recipients(),
| `auth_method_label`) e a página de evidências do PDF (EvidenceData::participants(),
| `auth_method`) dizem apenas "Código por SMS", sem "simulado". A declaração gravada também diz
| "código de uso único enviado por SMS ao celular informado pela remetente". Em homologação —
| ou em produção com o simulador ligado (ver SimulatedChannelsInProductionTest) — o documento
| final carrega uma afirmação de posse de celular que ninguém provou.
*/

beforeEach(function () {
    $this->work = storage_path('framework/testing/review-2b-simev-'.uniqid());
    File::ensureDirectoryExists($this->work);
    signerDisk($this->work);
    $this->withoutVite();
    $this->emailCodes = signerCaptureCodes();
    Event::fake([EnvelopeReadyForFinalization::class]);
});

afterEach(function () {
    File::deleteDirectory($this->work ?? '');
});

it('o dossiê de evidências não diz que o código por SMS foi simulado', function () {
    $ctx = channelsSignerContext(AuthMethod::SmsOtp);
    channelsEnable($ctx['organization']);
    $token = $ctx['token'];

    $this->post(route('sign.otp.send', ['token' => $token]))->assertRedirect();
    $this->post(route('sign.otp.verify', ['token' => $token]), ['code' => channelsLastCode(DeliveryChannel::Sms)])->assertSessionHas('success');

    $props = $this->get(route('sign.show', ['token' => $token]))->viewData('page')['props'];
    $this->get(route('sign.document', ['token' => $token]));

    $this->post(route('sign.complete', ['token' => $token]), [
        'authorization' => $props['authorization']['token'],
        'consent' => true,
        'signature' => ['method' => 'draw', 'image_base64' => pngDataUri()],
    ])->assertSessionHasNoErrors();

    $acceptance = SignatureAcceptance::withoutOrganizationScope()->sole();
    $attempt = DeliveryAttempt::query()->where('purpose', DeliveryPurpose::Otp->value)->sole();

    // O sistema sabe que o código não saiu de verdade.
    expect($attempt->provider)->toBe(FakeSmsProvider::NAME)
        ->and($attempt->meta['simulated'])->toBeTrue()
        ->and($acceptance->auth_method)->toBe(AuthMethod::SmsOtp);

    $this->flushSession();
    actingAsMember($ctx['owner'], $ctx['organization']);

    $evidence = $this->get(route('envelopes.evidence', ['envelope' => $ctx['envelope']->ulid]))->assertOk()->viewData('page')['props'];
    $row = collect($evidence['recipients'])->firstWhere('email', 'maria@exemplo.test');

    expect($row['auth_method_label'])->toBe('Código por SMS');

    $rowText = mb_strtolower((string) json_encode($row, JSON_UNESCAPED_UNICODE));

    expect(str_contains($rowText, 'simulad'))->toBeTrue('A evidência afirma "Código por SMS" sem dizer que o SMS foi simulado (nada transmitido).');
});
