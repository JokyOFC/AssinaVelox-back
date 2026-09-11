<?php

use App\Enums\AuditEventType;
use App\Enums\AuthMethod;
use App\Enums\DeliveryChannel;
use App\Models\AuditEvent;
use Illuminate\Support\Facades\File;

require_once __DIR__.'/../../Phase2/Channels/Support/ChannelHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão adversarial da Fase 2, onda B — código por SMS apresentado como identidade
|--------------------------------------------------------------------------
| Regra da onda (arquitetura §2, T1; AuthMethod; docs/fase-2/identidade.md §1): o código por
| SMS/WhatsApp prova a POSSE DO CANAL, não a identidade. O próprio IdentityCaptureTest trata
| "identidade confirmada" como termo proibido.
|
| Mesmo assim, para quem confirma o código recebido por SMS (simulador):
|  - o flash da página pública diz "Identidade confirmada. Revise o documento…"
|    (app/Http/Controllers/Sign/OtpController.php:81);
|  - o evento `challenge.verified` — que está em `evidence.timeline_events` e é impresso na
|    linha do tempo da página de evidências anexada ao PDF — tem o rótulo "Identidade
|    confirmada" (app/Enums/AuditEventType.php:229);
|  - o selo "Identidade confirmada" aparece na tela do documento (resources/js/pages/sign/show.tsx).
| O texto nasceu na Fase 1 para o e-mail; a onda B o estendeu a SMS, WhatsApp e PIN sem ajuste.
*/

beforeEach(function () {
    $this->work = storage_path('framework/testing/review-2b-claim-'.uniqid());
    File::ensureDirectoryExists($this->work);
    signerDisk($this->work);
    $this->withoutVite();
    $this->emailCodes = signerCaptureCodes();
});

afterEach(function () {
    File::deleteDirectory($this->work ?? '');
});

it('confirmar o código recebido por SMS não é "identidade confirmada" — nem na tela nem na evidência', function () {
    $ctx = channelsSignerContext(AuthMethod::SmsOtp);
    channelsEnable($ctx['organization']);

    $this->post(route('sign.otp.send', ['token' => $ctx['token']]))->assertRedirect();
    $code = channelsLastCode(DeliveryChannel::Sms);

    $response = $this->post(route('sign.otp.verify', ['token' => $ctx['token']]), ['code' => $code])
        ->assertRedirect()
        ->assertSessionHas('success');

    /** @var AuditEvent $verified */
    $verified = AuditEvent::query()->where('event_type', AuditEventType::ChallengeVerified->value)->sole();

    $flash = (string) session('success');
    $timelineLabel = $verified->event_type->label();

    // O evento entra na linha do tempo impressa no PDF de evidências.
    expect(config('assinavelox.evidence.timeline_events'))->toContain('challenge.verified');

    expect(str_contains(mb_strtolower($flash), 'identidade confirmada'))->toBeFalse('Flash da página pública após o código por SMS: '.$flash)
        ->and(str_contains(mb_strtolower($timelineLabel), 'identidade confirmada'))->toBeFalse('Rótulo impresso na evidência para o código por SMS: '.$timelineLabel);
});

it('a tela do participante mostra o selo "Identidade confirmada" depois do código de qualquer canal', function () {
    $page = (string) file_get_contents(resource_path('js/pages/sign/show.tsx'));

    expect($page)->not->toContain('Identidade confirmada');
});
