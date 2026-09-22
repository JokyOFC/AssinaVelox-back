<?php

use App\Enums\AuditEventType;
use App\Enums\SignatureStatus;
use App\Events\EnvelopeReadyForFinalization;
use App\Models\AuditEvent;
use App\Models\SignatureAcceptance;
use App\Services\Envelopes\Finalization\EvidenceData;
use App\Services\Identity\CaptureEvidence;
use App\Services\Identity\CaptureStep;
use App\Services\Identity\IdentityVerifications;
use App\Services\Identity\Models\IdentityVerification;
use App\Services\Identity\VerificationEvidence;
use App\Services\Identity\VerificationStep;
use App\Services\Signing\ConsentText;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/OrganizationHelpers.php';
require_once __DIR__.'/../../Sending/Support/SendingHelpers.php';
require_once __DIR__.'/../../Sign/Support/SignerHelpers.php';
require_once __DIR__.'/../../Phase2/Identity/Support/IdentityHelpers.php';
require_once __DIR__.'/Support/VerificationHelpers.php';

/*
|--------------------------------------------------------------------------
| Fase 4 §4.1 — aceite, evidência, consentimento e flag desligada
|--------------------------------------------------------------------------
| O aceite é recusado até o provedor aprovar (as fotos desta sessão), e depois grava o resumo
| e vincula a tentativa; o PDF e a página de evidências só repetem o provedor; a declaração
| ganha a cláusula e a versão muda; com a flag desligada, tudo é exatamente como antes.
*/

beforeEach(function () {
    $this->work = storage_path('app/tmp/tests/'.Str::ulid());
    signerDisk($this->work);
    $this->withoutVite();
    $this->codes = signerCaptureCodes();
    config()->set('inertia.ssr.enabled', false);
    Http::preventStrayRequests();
    Event::fake([EnvelopeReadyForFinalization::class]);
});

afterEach(function () {
    File::deleteDirectory($this->work ?? '');
});

it('o aceite é recusado até o provedor aprovar as fotos desta sessão; depois grava o resumo e vincula a tentativa', function () {
    $ctx = verificationEnvelope();
    $token = $ctx['token'];
    $maria = $ctx['recipients']['maria@exemplo.test'];
    $props = authenticateSigner($this, $token);

    // Sem fotos: a captura recusa primeiro (é a etapa anterior).
    identityAccept($this, $token, $props)->assertSessionHasErrors('signature');

    verificationUploadPhotos($this, $token);

    // Fotos ok, verificação não enviada.
    identityAccept($this, $token, $props)->assertSessionHasErrors('signature');
    expect(session('errors')->first('signature'))->toBe('Antes de concluir, envie as fotos para a verificação facial com documento.');

    // Pendente no provedor.
    verificationFake()->simulate('pending');
    verificationPost($this, $token)->assertCreated()->assertJsonPath('identity_verification.status', 'pending');
    identityAccept($this, $token, $props)->assertSessionHasErrors('signature');
    expect(session('errors')->first('signature'))->toContain('ainda está em andamento');

    // Reprovada: o texto repete o provedor e a tentativa conta.
    $this->travel(11)->seconds();
    verificationFake()->simulate('rejected');
    verificationShow($this, $token)->assertOk()->assertJsonPath('identity_verification.status', 'rejected');
    $this->travelBack();

    identityAccept($this, $token, $props)->assertSessionHasErrors('signature');
    expect(session('errors')->first('signature'))->toStartWith('Simulador informou: reprovado.');

    // Aprovada.
    verificationFake()->simulate('approved');
    verificationPost($this, $token)->assertCreated()->assertJsonPath('identity_verification.status', 'approved');

    // …mas uma foto foi refeita depois: o provedor comparou outras imagens.
    identityPostCapture($this, $token, 'document_back', identityPng(300, 200), 'verso2.png')->assertCreated()
        ->assertJsonPath('identity_verification.captures_changed', true);
    identityAccept($this, $token, $props)->assertSessionHasErrors('signature');
    expect(session('errors')->first('signature'))->toContain('As fotos foram refeitas depois da verificação');

    verificationPost($this, $token)->assertCreated()->assertJsonPath('identity_verification.status', 'approved');
    expect(SignatureAcceptance::withoutOrganizationScope()->count())->toBe(0);

    identityAccept($this, $token, $props)->assertSessionHasNoErrors();

    $acceptance = SignatureAcceptance::withoutOrganizationScope()->firstOrFail();
    $approved = IdentityVerification::withoutOrganizationScope()->orderByDesc('id')->firstOrFail();
    $snapshot = $acceptance->fields_snapshot['identity_verification'];

    expect($approved->signature_acceptance_id)->toBe($acceptance->getKey())
        ->and(IdentityVerification::withoutOrganizationScope()->whereNotNull('signature_acceptance_id')->count())->toBe(1)
        ->and($snapshot)->toBe([
            'verification_ulid' => $approved->ulid,
            'provider' => 'verificacao_simulada',
            'provider_label' => 'Simulador',
            'simulated' => true,
            'provider_verification_id' => $approved->provider_verification_id,
            'document_type' => 'cnh',
            'status' => 'approved',
            'completed_at' => $approved->completed_at->toIso8601String(),
            'consent_version' => VerificationStep::consentVersion('Simulador'),
        ])
        ->and(array_column($acceptance->fields_snapshot['identity_captures'], 'kind'))->toBe(['selfie', 'document_front', 'document_back'])
        ->and(json_encode($acceptance->fields_snapshot))->not->toContain('identity/')
        // A declaração gravada traz a cláusula do provedor e a versão com o sufixo próprio.
        ->and($acceptance->consent_statement)->toContain('encaminhadas ao provedor externo Simulador')
        ->and($acceptance->terms_version)->toBe('v1-2026-09-08+vf1');

    // Evidência do remetente (JSON do detalhe e dossiê) e PDF: só o que o provedor informou.
    actingAsMember($ctx['owner'], $ctx['organization']);

    $index = $this->getJson(route('envelopes.identity_verifications.index', ['envelope' => $ctx['envelope']->ulid]))->assertOk();
    $attached = collect($index->json('items'))->firstWhere('attached', true);

    expect($index->json('items'))->toHaveCount(3)
        ->and($attached['id'])->toBe($approved->ulid)
        ->and($attached['recipient_id'])->toBe($maria->ulid)
        ->and($attached['status_label'])->toBe('Aprovada pelo provedor')
        ->and($attached['provider_label'])->toBe('Simulador')
        ->and($attached['simulated'])->toBeTrue()
        ->and($attached['document_type_label'])->toBe('CNH')
        ->and($attached['attempt'])->toBe(3)
        ->and(array_column($attached['captures'], 'kind'))->toBe(['selfie', 'document_front', 'document_back'])
        ->and(json_encode($index->json()))->not->toContain('identity/');

    $dossier = $this->get(route('envelopes.evidence', ['envelope' => $ctx['envelope']->ulid]))->assertOk()->viewData('page')['props'];
    $row = collect($dossier['recipients'])->firstWhere('email', 'maria@exemplo.test');
    $other = collect($dossier['recipients'])->firstWhere('email', 'henrique@exemplo.test');

    expect($row['identity_verifications'])->toHaveCount(3)
        ->and($row['identity_verification_notice'])->toBe(VerificationEvidence::NOTICE)
        ->and($row['identity_capture_notice'])->toBe(CaptureEvidence::verificationNotice('Simulador'))
        ->and($row['identity_capture_notice'])->toContain('Quem comparou as imagens foi o provedor')
        ->and($other)->not->toHaveKey('identity_verifications')
        ->and($other)->not->toHaveKey('identity_capture_notice')
        ->and($dossier['identity_capture_notice'])->toBe(CaptureEvidence::NOTICE);

    $line = app(VerificationEvidence::class)->pdfLines($ctx['envelope']->fresh())[$maria->ulid];
    $when = $approved->completed_at->copy()->setTimezone($ctx['organization']->timezone ?: 'UTC')->format('d/m/Y H:i');

    expect($line)->toBe(sprintf(
        'Verificação facial com documento: Simulador informou aprovado (simulado) — nenhuma imagem foi analisada em %s (identificador %s; documento: CNH)',
        $when,
        $approved->provider_verification_id,
    ));

    $data = app(EvidenceData::class)->build($ctx['envelope']->fresh(), $ctx['version'], ['original' => null, 'sent' => $ctx['version']->sha256, 'consolidated' => null], SignatureStatus::None);
    $participant = collect($data['participants'])->firstWhere('email', 'maria@exemplo.test');
    $html = view('evidence.page', ['evidence' => $data])->render();

    expect($participant['identity_verification_label'])->toBe($line)
        ->and($participant['identity_verification_notice'])->toBe('Quem comparou as imagens foi o provedor; a plataforma enviou as fotos da captura e registrou a resposta.')
        ->and(collect($data['participants'])->firstWhere('email', 'henrique@exemplo.test'))->not->toHaveKey('identity_verification_label')
        ->and($html)->toContain('Simulador informou aprovado')
        ->and($html)->toContain('Quem comparou as imagens foi o provedor')
        // Só a linha: nada de miniatura, protocolo cru do provedor além do citado, ou pontuação.
        ->and(substr_count($html, 'Simulador informou'))->toBe(1);

    // A verificação pública não fala do provedor nem da verificação.
    $public = (string) $this->get(route('verify.show', ['code' => $ctx['envelope']->verification_code]))->assertOk()->getContent();
    $public = (string) preg_replace('/(&quot;|")features\1:\{[^}]*\}/', '', $public);

    expect($public)->not->toContain('identity_verification')
        ->and($public)->not->toContain('Simulador informou')
        ->and($public)->not->toContain((string) $approved->provider_verification_id)
        ->and(mb_strtolower($public))->not->toContain('verificação facial');
});

it('com o provedor real e reprovação por rosto diferente, a mensagem e a evidência repetem o provedor, sem "(simulado)"', function () {
    $ctx = verificationEnvelope('verifiky');
    $token = $ctx['token'];
    $props = authenticateSigner($this, $token);
    verificationUploadPhotos($this, $token);

    verificationDouble('rejected', ['provider_status' => 'approved', 'face_match' => false, 'reason_code' => 'face_mismatch'], 'vk-9');

    verificationPost($this, $token)->assertCreated()
        ->assertJsonPath('identity_verification.status', 'rejected')
        ->assertJsonPath('identity_verification.simulated', false)
        ->assertJsonPath('identity_verification.message', 'Verifiky informou: reprovado — a foto tirada na hora não corresponde à do documento.');

    identityAccept($this, $token, $props)->assertSessionHasErrors('signature');
    expect(session('errors')->first('signature'))->toBe('Verifiky informou: reprovado. Refaça as fotos e envie de novo para a verificação; sem uma aprovação do provedor não é possível concluir.');

    verificationDouble('approved', ['provider_status' => 'approved', 'face_match' => true, 'face_match_approved' => true, 'face_score' => 0.97], 'vk-10');
    verificationPost($this, $token)->assertCreated()->assertJsonPath('identity_verification.status', 'approved');
    identityAccept($this, $token, $props)->assertSessionHasNoErrors();

    $acceptance = SignatureAcceptance::withoutOrganizationScope()->firstOrFail();

    expect($acceptance->fields_snapshot['identity_verification'])->toMatchArray([
        'provider' => 'verifiky',
        'provider_label' => 'Verifiky',
        'simulated' => false,
        'provider_verification_id' => 'vk-10',
        'face_match' => true,
        'face_score' => 0.97,
    ]);

    $line = app(VerificationEvidence::class)->pdfLines($ctx['envelope']->fresh())[$ctx['recipients']['maria@exemplo.test']->ulid];

    expect($line)->toStartWith('Verificação facial com documento: Verifiky informou aprovado em ')
        ->and($line)->toEndWith('(identificador vk-10; documento: CNH)')
        ->and($line)->not->toContain('simulado')
        ->and($line)->not->toContain('0.97');
});

it('a declaração e o aviso de privacidade ganham a cláusula do provedor e a versão muda; sem a exigência, nada muda', function () {
    $ctx = verificationEnvelope();
    $maria = $ctx['recipients']['maria@exemplo.test'];
    $henrique = $ctx['recipients']['henrique@exemplo.test'];

    $page = $this->get(route('sign.show', ['token' => $ctx['token']]))->assertOk()->viewData('page')['props'];

    expect($page['privacy']['version'])->toBe(ConsentText::PRIVACY_NOTICE_VERSION.'+vf1')
        ->and($page['privacy']['notice'])->toContain('encaminhadas ao provedor externo Simulador só para comparar a foto tirada na hora com a foto do documento')
        ->and($page['privacy']['notice'])->not->toContain('sem comparação de rostos')
        ->and(ConsentText::versionFor($ctx['envelope'], $maria))->toBe('v1-2026-09-08+vf1')
        ->and(ConsentText::statement($ctx['envelope'], $maria, $ctx['organization'], 'abc', false))->toContain('encaminhadas ao provedor externo Simulador')
        // Quem não tem a exigência continua com o texto de sempre.
        ->and(ConsentText::versionFor($ctx['envelope'], $henrique))->toBe('v1-2026-09-08')
        ->and(ConsentText::statement($ctx['envelope'], $henrique, $ctx['organization'], 'abc', false))->not->toContain('provedor');

    // Só fotos exigidas (sem verificação): a cláusula da onda B, com o sufixo `+b1`.
    app(IdentityVerifications::class)->setRequirement($ctx['envelope'], $maria, false, $ctx['owner']);

    expect(ConsentText::versionFor($ctx['envelope']->fresh(), $maria->fresh()))->toBe('v1-2026-09-08+b1')
        ->and(ConsentText::statement($ctx['envelope'], $maria, $ctx['organization'], 'abc', false))->toContain('guardadas como registro, sem verificação de identidade');

    // A versão do sufixo cabe nos 32 caracteres de `terms_version` mesmo na variante mais longa.
    expect(strlen(ConsentText::WITNESS_MULTI_TERMS_VERSION.ConsentText::VERIFICATION_REVIEW_VERSION_SUFFIX))->toBeLessThanOrEqual(32);
});

it('flag desligada: props, resposta da captura, rotas e aceite são exatamente os de antes', function () {
    $ctx = verificationEnvelope();
    $token = $ctx['token'];
    $maria = $ctx['recipients']['maria@exemplo.test'];

    // Exigência gravada enquanto a flag esteve ligada: sem a flag, não vale (nem consulta).
    config()->set('assinavelox.features.identity_verification', false);

    $page = $this->get(route('sign.show', ['token' => $token]))->assertOk()->viewData('page')['props'];

    expect($page)->not->toHaveKey('identity_verification')
        ->and($page['identity_capture']['notice'])->toBe(CaptureStep::NOTICE)
        ->and($page['privacy']['version'])->toBe(ConsentText::PRIVACY_NOTICE_VERSION.'+b1')
        ->and(ConsentText::versionFor($ctx['envelope'], $maria))->toBe('v1-2026-09-08+b1');

    $props = authenticateSigner($this, $token);
    expect($props)->not->toHaveKey('identity_verification');

    verificationPost($this, $token)->assertNotFound();
    verificationShow($this, $token)->assertNotFound();

    identityPostCapture($this, $token, 'selfie', identityPng())->assertCreated()->assertJsonMissingPath('identity_verification');
    identityPostCapture($this, $token, 'document_front', identityPng(), 'frente.png')->assertCreated();
    identityPostCapture($this, $token, 'document_back', identityPng(), 'verso.png')->assertCreated();

    // O aceite é o da captura simples: sem chave nova no snapshot, sem evento novo.
    identityAccept($this, $token, $props)->assertSessionHasNoErrors();

    $acceptance = SignatureAcceptance::withoutOrganizationScope()->firstOrFail();

    expect($acceptance->fields_snapshot)->not->toHaveKey('identity_verification')
        ->and($acceptance->fields_snapshot)->toHaveKey('identity_captures')
        ->and($acceptance->terms_version)->toBe('v1-2026-09-08+b1')
        ->and($acceptance->consent_statement)->not->toContain('provedor')
        ->and(IdentityVerification::withoutOrganizationScope()->count())->toBe(0)
        // A exigência foi gravada com a flag ligada (um evento); sem a flag, nenhum envio nem resposta.
        ->and(AuditEvent::query()->withoutGlobalScopes()->whereIn('event_type', [
            AuditEventType::IdentityVerificationSubmitted->value,
            AuditEventType::IdentityVerificationCompleted->value,
        ])->count())->toBe(0);

    // Só a flag da captura desligada também desliga a verificação (as fotos vêm dela).
    config()->set('assinavelox.features.identity_verification', true);
    config()->set('assinavelox.features.identity_capture', false);
    $this->flushSession();

    app(IdentityVerifications::class)->setRequirement($ctx['envelope'], $ctx['recipients']['henrique@exemplo.test'], true, $ctx['owner']);
    $other = $this->get(route('sign.show', ['token' => $ctx['tokens']['henrique@exemplo.test']]))->assertOk()->viewData('page')['props'];

    expect($other)->not->toHaveKey('identity_verification')
        ->and($other['identity_capture'])->toBeNull();

    // Remetente: as rotas também somem com a flag desligada.
    actingAsMember($ctx['owner'], $ctx['organization']);
    $this->getJson(route('envelopes.identity_verifications.index', ['envelope' => $ctx['envelope']->ulid]))->assertNotFound();
    $this->putJson(route('envelopes.recipients.identity_verification', ['envelope' => $ctx['envelope']->ulid, 'recipient' => $maria->ulid]), ['required' => false])->assertNotFound();
});

it('vocabulário: a interface, a trilha e a evidência só repetem o provedor e o nomeiam', function () {
    $labels = collect(AuditEventType::cases())
        ->filter(fn (AuditEventType $type): bool => str_starts_with($type->value, 'identity_verification.'))
        ->map->label()->implode(' ');

    $texts = mb_strtolower(implode(' ', [
        VerificationStep::TITLE, VerificationStep::consentText('Verifiky'), VerificationStep::notice('Verifiky'), VerificationStep::SIMULATED_SUFFIX,
        VerificationEvidence::LABEL, VerificationEvidence::NOTICE, CaptureStep::verificationNotice('Verifiky'), CaptureEvidence::verificationNotice('Verifiky'),
        $labels,
    ]));

    foreach (['identidade verificada', 'identidade confirmada', 'biometria', 'biométric', 'liveness', 'prova de vida', 'reconhecimento facial', 'face match', 'assinatura avançada', 'qualificada', 'reconhecimento de firma'] as $forbidden) {
        expect($texts)->not->toContain($forbidden);
    }

    expect(VerificationEvidence::NOTICE)->toBe('Quem comparou as imagens foi o provedor; a plataforma enviou as fotos da captura e registrou a resposta.')
        ->and(VerificationStep::notice('Verifiky'))->toContain('A plataforma não compara as imagens')
        // As constantes originais da captura continuam byte a byte as mesmas.
        ->and(CaptureStep::NOTICE)->toContain('não há comparação entre rostos')
        ->and(CaptureEvidence::NOTICE)->toContain('Não houve verificação de identidade');
});
