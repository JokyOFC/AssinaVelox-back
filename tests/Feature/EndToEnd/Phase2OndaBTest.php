<?php

use App\Enums\AuditEventType;
use App\Enums\DeliveryChannel;
use App\Enums\EnvelopeStatus;
use App\Enums\FieldType;
use App\Enums\RecipientStatus;
use App\Enums\SignatureStatus;
use App\Events\EnvelopeReadyForFinalization;
use App\Integrations\Sms\FakeSmsProvider;
use App\Models\AuditEvent;
use App\Models\DocumentVersion;
use App\Models\Envelope;
use App\Models\Organization;
use App\Models\PublicFormSubmission;
use App\Models\SignatureAcceptance;
use App\Models\SigningField;
use App\Models\SigningFieldValue;
use App\Notifications\Envelopes\RecipientInvitationNotification;
use App\Services\Batch\Models\BatchSigningItem;
use App\Services\Branding\BrandingManager;
use App\Services\Envelopes\Finalization\EvidenceData;
use App\Services\Identity\IdentityCaptures;
use App\Services\Identity\Models\IdentityCapture;
use App\Services\InPerson\Models\InPersonTurn;
use App\Services\PublicForms\Notifications\PublicFormConfirmationNotification;
use App\Services\PublicForms\SubmissionStatus;
use App\Services\Signing\Channels\SimulatedChannelEvidence;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Tests\Feature\Pdf\Support\PdfFixtures;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/../Documents/Support/helpers.php';
require_once __DIR__.'/../Sign/Support/SignerHelpers.php';
require_once __DIR__.'/../Phase2/PublicForms/Support/PublicFormHelpers.php';
require_once __DIR__.'/../Phase2/Channels/Support/ChannelHelpers.php';
require_once __DIR__.'/../Phase2/Identity/Support/IdentityHelpers.php';
require_once __DIR__.'/../Phase2/Branding/Support/BrandingHelpers.php';
require_once __DIR__.'/../Phase2/InPerson/Support/PresenceHelpers.php';

/*
|--------------------------------------------------------------------------
| Ponta a ponta da Fase 2, onda B (integração I-2B)
|--------------------------------------------------------------------------
| Com TODAS as flags da onda B ligadas (interruptor global E plano) e os simuladores
| identificados, pelas rotas HTTP reais:
|
|  1. formulário público → confirmação por e-mail → envelope gerado pelo modelo (fila de
|     revisão) → o remetente põe SMS simulado + PIN, campo CPF, carimbo e foto exigida →
|     aprova (envio) → participante: código por SMS simulado, PIN, CPF e selfie → aceite →
|     finalização real (pdftool) com o carimbo desenhado → evidência com "imagem capturada
|     pelo participante", o canal e o PIN → verificação pública sem o CPF completo;
|  2. presencial com dois participantes no mesmo dispositivo (um com foto exigida);
|  3. lote com dois envelopes autorizados item a item.
|
| Segredos (código, PIN, CPF completo) nunca aparecem na trilha nem na verificação pública.
*/

const ONDA_B_PIN = '48291573';
const ONDA_B_CPF = '529.982.247-25';

function ondaBEnableAll(Organization $organization): void
{
    publicFormsEnable($organization);
    channelsEnable($organization, smsWhatsapp: true, pin: true);
    identityEnableFlags($organization, ['cpf_field', 'identity_capture']);
    brandingEnable($organization);
    presenceEnable($organization);
}

/**
 * @return ArrayObject<string, mixed>
 */
function ondaBCaptureMail(): ArrayObject
{
    /** @var ArrayObject<string, mixed> $mail */
    $mail = new ArrayObject(['invites' => [], 'confirmations' => []]);

    Event::listen(NotificationSending::class, function (NotificationSending $event) use ($mail): void {
        $notification = $event->notification;

        if ($notification instanceof RecipientInvitationNotification) {
            $invites = $mail['invites'];
            $invites[$notification->recipient->email] = $notification->signingUrl;
            $mail['invites'] = $invites;
        }

        if ($notification instanceof PublicFormConfirmationNotification) {
            $confirmations = $mail['confirmations'];
            $confirmations[] = $notification->confirmationUrl;
            $mail['confirmations'] = $confirmations;
        }
    });

    return $mail;
}

function ondaBTokenFromUrl(string $url): string
{
    $segments = array_values(array_filter(explode('/', (string) parse_url($url, PHP_URL_PATH))));

    return (string) end($segments);
}

it('formulário público → SMS simulado + PIN → CPF e selfie → aceite → evidência e verificação sem CPF completo', function () {
    if (! PdfFixtures::available()) {
        $this->markTestSkipped(PdfFixtures::skipMessage());
    }

    $this->withoutVite();
    $work = PdfFixtures::workspace();
    config()->set('pdftool.tmp_path', $work.DIRECTORY_SEPARATOR.'pdftool-tmp');
    config()->set('pdftool.company_certificate.enabled', false);
    config()->set('pdftool.trust_roots', []);
    fakeDocumentsDisk($work.DIRECTORY_SEPARATOR.'disco-documents');

    try {
        ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner(['name' => 'Horizonte Onda B']);
        setPlanQuota($organization, 10);
        ondaBEnableAll($organization);
        $mail = ondaBCaptureMail();
        $logs = channelsCaptureLogs();

        // Marca da organização (C-BRAND): nome de exibição e logo.
        $branding = app(BrandingManager::class);
        $branding->update($organization, $owner, ['display_name' => 'Horizonte Imóveis']);
        $logo = $work.DIRECTORY_SEPARATOR.'logo.png';
        file_put_contents($logo, brandingPng());
        $branding->replaceLogo($organization, $owner, $logo);

        // -- 1. Modelo PDF com um signatário e formulário publicado em modo revisão -----------
        $template = publicFormPdfTemplate($organization, $owner, $work);
        $form = publicFormFrom($organization, $owner, $template, ['destination' => 'review']);

        // -- 2. O público preenche (sem login) e confirma pelo e-mail ------------------------
        $this->flushSession();
        app('auth')->forgetGuards();

        publicFormSubmit($form, [], ['name' => 'Ana Souza', 'email' => 'ana@example.com'])->assertSessionHasNoErrors();
        expect(Envelope::withoutOrganizationScope()->count())->toBe(0)
            ->and($mail['confirmations'])->toHaveCount(1);

        $confirmation = ondaBTokenFromUrl($mail['confirmations'][0]);
        publicFormConfirm($form, $confirmation)->assertSessionHasNoErrors();

        /** @var Envelope $envelope */
        $envelope = Envelope::withoutOrganizationScope()->sole();
        $submission = PublicFormSubmission::withoutOrganizationScope()->sole();
        $recipient = $envelope->recipients()->withoutGlobalScopes()->sole();
        $document = $envelope->documents()->withoutGlobalScopes()->orderBy('position')->firstOrFail();

        expect($envelope->status->isDraftLike())->toBeTrue()
            ->and($submission->status)->toBe(SubmissionStatus::PendingReview)
            ->and($recipient->email)->toBe('ana@example.com')
            ->and($mail['invites'])->toBe([]);

        // -- 3. O remetente prepara: SMS simulado + PIN, CPF, carimbo e selfie exigida ---------
        actingAsMember($owner, $organization);

        $wizard = $this->get(route('envelopes.edit', ['envelope' => $envelope->ulid, 'step' => 2]))->assertOk()->viewData('page')['props'];
        expect($wizard['channels']['enabled'])->toBeTrue()
            ->and($wizard['channels']['pin']['enabled'])->toBeTrue()
            ->and($wizard['channels']['channels']['sms']['simulated'])->toBeTrue()
            ->and($wizard['capture_requirements'])->toBe([]);

        $this->put(route('envelopes.recipients.sync', ['envelope' => $envelope->ulid]), [
            'signing_order' => 'sequential',
            'recipients' => [[
                'id' => $recipient->ulid,
                'name' => 'Ana Souza',
                'email' => 'ana@example.com',
                'participant_role' => 'signer',
                'phone' => '(11) 91234-5678',
                'channel' => 'sms',
                'auth_method' => 'sms_otp',
                'pin' => ONDA_B_PIN,
            ]],
        ])->assertSessionHasNoErrors();

        $recipient->refresh();
        expect($recipient->phone)->toBe('+5511912345678')
            ->and($recipient->auth_method->value)->toBe('sms_otp');

        $signature = SigningField::withoutOrganizationScope()->where('envelope_id', $envelope->id)->where('type', FieldType::Signature->value)->sole();

        $this->put(route('envelopes.fields.sync', ['envelope' => $envelope->ulid]), [
            'initials_on_all_pages' => false,
            'fields' => [
                ['id' => $signature->ulid, 'client_id' => 'sig', 'recipient_id' => $recipient->ulid, 'document_id' => $document->ulid, 'type' => 'signature', 'page' => 1, 'x' => (float) $signature->x, 'y' => (float) $signature->y, 'w' => (float) $signature->width, 'h' => (float) $signature->height, 'required' => true],
                ['client_id' => 'cpf', 'recipient_id' => $recipient->ulid, 'document_id' => $document->ulid, 'type' => 'cpf', 'page' => 1, 'x' => 0.10, 'y' => 0.50, 'w' => 0.30, 'h' => 0.04, 'required' => true, 'label' => 'CPF'],
                ['client_id' => 'stamp', 'recipient_id' => $recipient->ulid, 'document_id' => $document->ulid, 'type' => 'stamp', 'page' => 1, 'x' => 0.55, 'y' => 0.80, 'w' => 0.36, 'h' => 0.12, 'required' => false],
            ],
        ])->assertSessionHasNoErrors();

        $this->putJson(route('envelopes.recipients.identity_capture', ['envelope' => $envelope->ulid, 'recipient' => $recipient->ulid]), ['kinds' => ['selfie']])
            ->assertOk();

        $wizard = $this->get(route('envelopes.edit', ['envelope' => $envelope->ulid, 'step' => 2]))->assertOk()->viewData('page')['props'];
        expect($wizard['capture_requirements'])->toBe([$recipient->ulid => ['selfie']])
            ->and($wizard['recipients'][0]['has_pin'])->toBeTrue()
            ->and($wizard['recipients'][0]['auth_method'])->toBe('sms_otp')
            ->and($wizard['recipients'][0]['channel'])->toBe('sms')
            ->and(json_encode($wizard))->not->toContain(ONDA_B_PIN);

        // -- 4. Aprovar na fila envia: e-mail sempre + aviso extra por SMS simulado ----------
        $this->post(route('public_forms.submissions.approve', $submission))->assertSessionHasNoErrors();

        $envelope->refresh();
        expect($envelope->status)->toBe(EnvelopeStatus::InProgress)
            ->and($submission->fresh()->status)->toBe(SubmissionStatus::Sent)
            ->and(isset($mail['invites']['ana@example.com']))->toBeTrue()
            ->and(channelsOutbox()->last(DeliveryChannel::Sms))->not->toBeNull();

        $token = ondaBTokenFromUrl($mail['invites']['ana@example.com']);

        // -- 5. Participante: código por SMS (simulado), PIN, CPF, selfie, aceite -----------
        $this->flushSession();
        app('auth')->forgetGuards();

        $identify = $this->get(route('sign.show', ['token' => $token]))->assertOk()->viewData('page')['props'];
        expect($identify['screen'])->toBe('identify')
            ->and($identify['auth_methods'])->toBe(['sms_otp', 'sender_pin'])
            ->and($identify['signer_auth']['method'])->toBe('sms_otp')
            ->and($identify['signer_auth']['simulated'])->toBeTrue()
            ->and($identify['signer_auth']['destination'])->not->toContain('91234')
            ->and($identify['sender']['brand']['display_name'])->toBe('Horizonte Imóveis')
            ->and($identify['identity_capture']['items'][0]['upload_url'])->toBeNull();

        $this->post(route('sign.otp.send', ['token' => $token]))->assertSessionHasNoErrors();
        $otp = channelsLastCode(DeliveryChannel::Sms);
        expect(channelsOutbox()->last(DeliveryChannel::Sms)['provider'] ?? FakeSmsProvider::NAME)->toBe(FakeSmsProvider::NAME);

        $this->post(route('sign.otp.verify', ['token' => $token]), ['code' => $otp])->assertSessionHasNoErrors();

        // O código sozinho não autentica: falta o PIN.
        $pinStep = $this->get(route('sign.show', ['token' => $token]))->viewData('page')['props'];
        expect($pinStep['screen'])->toBe('identify')
            ->and($pinStep['signer_auth']['step'])->toBe('pin');

        $this->post(route('sign.pin.verify', ['token' => $token]), ['pin' => ONDA_B_PIN])->assertSessionHasNoErrors();

        $props = $this->get(route('sign.show', ['token' => $token]))->assertOk()->viewData('page')['props'];
        expect($props['screen'])->toBe('sign')
            ->and($props['identity_capture']['complete'])->toBeFalse()
            ->and(collect($props['my_fields'])->pluck('type')->sort()->values()->all())->toBe(['cpf', 'signature', 'stamp']);

        $this->get(route('sign.document', ['token' => $token]))->assertOk();

        identityPostCapture($this, $token, 'selfie', identityJpegWithGps())->assertCreated();

        $cpfField = collect($props['my_fields'])->firstWhere('type', 'cpf');
        identityAccept($this, $token, $props, [$cpfField['id'] => ONDA_B_CPF])->assertSessionHasNoErrors();

        // -- 6. Finalização real: carimbo congelado e desenhado, CPF no documento -------------
        $envelope->refresh();
        expect($envelope->status)->toBe(EnvelopeStatus::Completed)
            ->and($envelope->final_document_version_id)->not->toBeNull();

        $acceptance = SignatureAcceptance::withoutOrganizationScope()->sole();
        $stampValue = SigningFieldValue::withoutOrganizationScope()
            ->whereHas('field', fn ($q) => $q->where('type', FieldType::Stamp->value))
            ->sole();
        $cpfValue = SigningFieldValue::withoutOrganizationScope()
            ->whereHas('field', fn ($q) => $q->where('type', FieldType::Cpf->value))
            ->sole();

        expect($acceptance->auth_method->value)->toBe('sms_otp')
            ->and($stampValue->image_path)->not->toBeNull()
            ->and($cpfValue->value_text)->toBe(ONDA_B_CPF)
            ->and(IdentityCapture::withoutOrganizationScope()->sole()->signature_acceptance_id)->toBe($acceptance->id)
            ->and($recipient->fresh()->status)->toBe(RecipientStatus::Signed);

        // -- 7. Evidência (remetente): captura simples, canal e PIN --------------------------
        actingAsMember($owner, $organization);
        $evidence = $this->get(route('envelopes.evidence', ['envelope' => $envelope->ulid]))->assertOk()->viewData('page')['props'];
        $row = $evidence['recipients'][0];

        expect($row['auth_methods'])->toBe(['sms_otp', 'sender_pin'])
            ->and($row['auth_method_label'])->toBe('Código por SMS')
            ->and($row['delivery_channel'])->toBe('sms')
            ->and($row['identity_captures'])->toHaveCount(1)
            ->and($row['identity_captures'][0]['label'])->toBe('Imagem enviada pelo participante — origem informada pelo navegador: câmera')
            ->and($evidence['identity_capture_notice'])->toContain('Não houve verificação de identidade');

        $sent = DocumentVersion::withoutOrganizationScope()->findOrFail($envelope->sent_document_version_id);
        $data = app(EvidenceData::class)->build($envelope->fresh(), $sent, ['original' => null, 'sent' => $sent->sha256, 'consolidated' => null], SignatureStatus::None);

        // Revisão adversarial da onda B: o SMS deste fluxo sai pelo SIMULADOR (nada transmitido)
        // e a página de evidências do PDF agora diz isso (T1: descrever o meio de fato usado).
        expect($data['participants'][0]['auth_method'])->toBe('Código por SMS'.SimulatedChannelEvidence::LABEL_SUFFIX.' + PIN do remetente')
            ->and($data['branding']['display_name'])->toBe('Horizonte Imóveis')
            ->and($data['stamp']['description'])->toContain('representação visual, não prova');

        // -- 8. Verificação pública: sem CPF completo, sem PIN, sem foto --------------------
        $code = (string) $envelope->verification_code;
        $this->post(route('logout'));
        $public = (string) $this->get(route('verify.show', ['code' => $code]))->assertOk()->getContent();

        expect($public)->not->toContain(ONDA_B_CPF)
            ->and($public)->not->toContain('52998224725')
            ->and($public)->not->toContain(ONDA_B_PIN)
            ->and($public)->not->toContain(IdentityCapture::withoutOrganizationScope()->sole()->sha256);

        // -- 9. Trilha e logs sem segredo -------------------------------------------------
        $trail = identityAuditPayloads();
        $logText = implode("\n", $logs->getArrayCopy());

        foreach ([ONDA_B_PIN, ONDA_B_CPF, '52998224725', $otp, $token] as $secret) {
            expect($trail)->not->toContain($secret)
                ->and($logText)->not->toContain($secret);
        }

        expect(AuditEvent::withoutGlobalScopes()->where('event_type', AuditEventType::ChallengePinVerified->value)->count())->toBe(1)
            ->and(AuditEvent::withoutGlobalScopes()->where('event_type', AuditEventType::PublicFormSubmissionApproved->value)->count())->toBe(1)
            ->and(AuditEvent::withoutGlobalScopes()->where('event_type', AuditEventType::IdentityCaptureRecorded->value)->count())->toBe(1);

        // -- 10. As props compartilhadas refletem as flags da onda B ---------------------
        actingAsMember($owner, $organization);
        $features = $this->get(route('dashboard'))->viewData('page')['props']['features'];
        expect($features)->toMatchArray([
            'sms_whatsapp' => true,
            'pin_auth' => true,
            'branding' => true,
            'cpf_field' => true,
            'identity_capture' => true,
            'in_person' => true,
            'batch_signing' => true,
            'public_forms' => true,
        ]);
    } finally {
        PdfFixtures::cleanup($work);
    }
});

it('presencial: dois participantes no mesmo dispositivo, um com foto exigida, com a marca da organização', function () {
    $work = storage_path('framework/testing/onda-b-in-person-'.uniqid());
    File::ensureDirectoryExists($work);
    signerDisk($work);
    $this->withoutVite();
    $this->codes = signerCaptureCodes();
    Event::fake([EnvelopeReadyForFinalization::class]);

    try {
        $ctx = presenceTwoSigners();
        ondaBEnableAll($ctx['organization']);
        app(BrandingManager::class)->update($ctx['organization'], $ctx['owner'], ['display_name' => 'Horizonte Imóveis']);
        app(IdentityCaptures::class)->setRequirement($ctx['envelope'], $ctx['joao'], ['selfie'], $ctx['owner']);

        $props = presenceStartKiosk($this, $ctx['organization'], $ctx['owner'], $ctx['envelope']);
        expect($props['screen'])->toBe('queue')
            ->and($props['sender']['brand']['display_name'])->toBe('Horizonte Imóveis');

        // Maria: sem foto exigida.
        $maria = presenceAuthenticate($this, $ctx['maria']);
        expect($maria['participant']['identity_capture'])->toBeNull();
        presenceAccept($this, $maria)->assertSessionHasNoErrors();

        // João: foto exigida — a câmera é liberada NO dispositivo presencial.
        $joao = presenceAuthenticate($this, $ctx['joao']);
        expect($joao['participant']['identity_capture']['items'][0]['upload_url'])->toBe(route('in_person.kiosk.capture.store', ['kind' => 'selfie']));
        expect((string) $this->get(route('in_person.kiosk.show'))->headers->get('Permissions-Policy'))->toContain('camera=(self)');

        $text = collect($joao['participant']['signing']['my_fields'])->firstWhere('type', 'text');

        // Sem a foto, o aceite é recusado.
        presenceAccept($this, $joao, [$text['id'] => 'Vistoria conferida'])->assertSessionHasErrors();

        $this->post(route('in_person.kiosk.capture.store', ['kind' => 'selfie']), ['image' => identityUpload(identityPng()), 'source' => 'camera'], ['Accept' => 'application/json'])
            ->assertCreated();

        $joao = presenceKiosk($this);
        presenceAccept($this, $joao, [$text['id'] => 'Vistoria conferida'])->assertSessionHasNoErrors();

        $acceptances = SignatureAcceptance::withoutOrganizationScope()->orderBy('id')->get();
        expect($acceptances)->toHaveCount(2)
            ->and($acceptances->pluck('recipient_id')->all())->toBe([$ctx['maria']->id, $ctx['joao']->id])
            ->and($acceptances[0]->signing_session_id)->not->toBe($acceptances[1]->signing_session_id)
            ->and(InPersonTurn::withoutOrganizationScope()->where('status', InPersonTurn::STATUS_ACCEPTED)->count())->toBe(2)
            ->and(IdentityCapture::withoutOrganizationScope()->sole()->signature_acceptance_id)->toBe($acceptances[1]->id)
            ->and($ctx['envelope']->fresh()->status)->toBe(EnvelopeStatus::Finalizing);

        // Evidência do remetente: modo presencial, sem trocar o autor.
        actingAsMember($ctx['owner'], $ctx['organization']);
        $evidence = $this->get(route('envelopes.evidence', ['envelope' => $ctx['envelope']->ulid]))->assertOk()->viewData('page')['props'];
        $byEmail = collect($evidence['recipients'])->keyBy('email');

        expect($byEmail['maria@exemplo.test']['in_person']['label'])->toBe('Presencial, na presença de '.$ctx['owner']->name)
            ->and($byEmail['joao@exemplo.test']['identity_captures'])->toHaveCount(1)
            ->and($evidence['in_person'])->toHaveCount(2);
    } finally {
        File::deleteDirectory($work);
    }
});

it('lote: dois envelopes autorizados item a item, cada um com o próprio aceite', function () {
    $work = storage_path('framework/testing/onda-b-batch-'.uniqid());
    File::ensureDirectoryExists($work);
    signerDisk($work);
    $this->withoutVite();
    $this->codes = signerCaptureCodes();
    $this->batchCodes = presenceCaptureBatchCodes();
    $this->batchLinks = presenceCaptureBatchLinks();
    Event::fake([EnvelopeReadyForFinalization::class]);

    try {
        $s = batchScenario();
        ondaBEnableAll($s['organization']);

        batchIssue($this, $s['organization'], $s['owner'], $s['one']['envelope'], $s['maria_one'])->assertSessionHas('success');

        batchAsParticipant($this);
        $props = batchAuthenticate($this, $this->batchLinks[0]);
        expect($props['items'])->toHaveCount(3);

        [$first, $second] = collect($props['items'])->pluck('id')->all();

        $current = batchOpen($this, $first);
        batchAuthorize($this, $first, $current)->assertSessionHasNoErrors();

        $current = batchOpen($this, $second);
        $text = collect($current['my_fields'])->firstWhere('type', 'text');
        batchAuthorize($this, $second, $current, [$text['id'] => 'Imóvel conferido'])->assertSessionHasNoErrors();

        $acceptances = SignatureAcceptance::withoutOrganizationScope()->orderBy('id')->get();

        expect($acceptances)->toHaveCount(2)
            ->and($acceptances->pluck('envelope_id')->all())->toBe([$s['one']['envelope']->id, $s['two']['envelope']->id])
            ->and($acceptances[0]->signing_session_id)->not->toBe($acceptances[1]->signing_session_id)
            ->and(BatchSigningItem::withoutOrganizationScope()->whereNotNull('signature_acceptance_id')->count())->toBe(2)
            // O terceiro documento continua pendente: nada de "autorizar todos".
            ->and($s['maria_three']->fresh()->status)->not->toBe(RecipientStatus::Signed)
            ->and($s['maria_foreign']->fresh()->status)->not->toBe(RecipientStatus::Signed);
    } finally {
        File::deleteDirectory($work);
    }
});
