<?php

use App\Enums\AuditEventType;
use App\Events\EnvelopeReadyForFinalization;
use App\Integrations\Contracts\IdentityVerificationProvider;
use App\Models\AuditEvent;
use App\Services\Identity\IdentityVerifications;
use App\Services\Identity\Jobs\SubmitIdentityVerification;
use App\Services\Identity\Models\IdentityVerification;
use App\Services\Identity\VerificationStep;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/OrganizationHelpers.php';
require_once __DIR__.'/../../Sending/Support/SendingHelpers.php';
require_once __DIR__.'/../../Sign/Support/SignerHelpers.php';
require_once __DIR__.'/../../Phase2/Identity/Support/IdentityHelpers.php';
require_once __DIR__.'/Support/VerificationHelpers.php';

/*
|--------------------------------------------------------------------------
| Fase 4 §4.1 — o participante envia as fotos ao provedor
|--------------------------------------------------------------------------
| A ordem das recusas do envio, o job com o simulador (aprovado, reprovado, pendente), a
| consulta do resultado, o prazo de espera, a falha técnica que não consome tentativa e a
| trilha — sempre sem imagem e sem caminho no disco.
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

it('a página traz o bloco só para quem tem a exigência, com o aviso das fotos trocado e sem URLs antes do código', function () {
    $ctx = verificationEnvelope();

    $page = $this->get(route('sign.show', ['token' => $ctx['token']]))->assertOk()->viewData('page')['props'];

    expect($page['identity_verification']['required'])->toBeTrue()
        ->and($page['identity_verification']['status'])->toBe('none')
        ->and($page['identity_verification']['status_label'])->toBe('Ainda não enviada')
        ->and($page['identity_verification']['message'])->toBeNull()
        ->and($page['identity_verification']['provider_label'])->toBe('Simulador')
        ->and($page['identity_verification']['simulated'])->toBeTrue()
        ->and($page['identity_verification']['document_types'])->toBe([
            ['value' => 'rg', 'label' => 'RG'], ['value' => 'cnh', 'label' => 'CNH'], ['value' => 'passaporte', 'label' => 'Passaporte'],
        ])
        ->and($page['identity_verification']['attempts_used'])->toBe(0)
        ->and($page['identity_verification']['max_attempts'])->toBe(3)
        ->and($page['identity_verification']['attempts_left'])->toBe(3)
        ->and($page['identity_verification']['captures_complete'])->toBeFalse()
        ->and($page['identity_verification']['can_submit'])->toBeFalse()
        ->and($page['identity_verification']['urls'])->toBe(['store' => null, 'show' => null])
        ->and($page['identity_verification']['poll_interval_ms'])->toBe(3000)
        ->and($page['identity_verification']['consent']['version'])->toBe(VerificationStep::consentVersion('Simulador'))
        ->and($page['identity_verification']['consent']['text'])->toContain('ao provedor Simulador')
        ->and($page['identity_verification']['notice'])->toContain('A plataforma não compara as imagens')
        // As três fotos foram exigidas junto, e o aviso delas passa a dizer que saem para o provedor.
        ->and(array_column($page['identity_capture']['items'], 'kind'))->toBe(['selfie', 'document_front', 'document_back'])
        ->and($page['identity_capture']['notice'])->toContain('são enviadas ao provedor Simulador')
        ->and($page['identity_capture']['notice'])->not->toContain('não há comparação entre rostos');

    // A outra pessoa, sem a exigência: nada muda para ela.
    $other = $this->get(route('sign.show', ['token' => $ctx['tokens']['henrique@exemplo.test']]))->assertOk()->viewData('page')['props'];
    expect($other)->not->toHaveKey('identity_verification')
        ->and($other['identity_capture'])->toBeNull();

    // Depois do código: URLs presentes e o envio de cada foto devolve o bloco atualizado.
    $props = authenticateSigner($this, $ctx['token']);
    expect($props['identity_verification']['urls']['store'])->toBe(route('sign.identity_verification.store', ['token' => $ctx['token']]))
        ->and($props['identity_verification']['urls']['show'])->toBe(route('sign.identity_verification.show', ['token' => $ctx['token']]));

    identityPostCapture($this, $ctx['token'], 'selfie', identityPng())->assertCreated()
        ->assertJsonPath('identity_verification.captures_complete', false)
        ->assertJsonPath('identity_verification.can_submit', false);
});

it('recusa o envio na ordem: fotos, tipo do documento, consentimento — e nunca sem a exigência', function () {
    $ctx = verificationEnvelope();
    $token = $ctx['token'];

    // Sem sessão: a rota exige o código.
    $this->postJson(route('sign.identity_verification.store', ['token' => $token]), ['document_type' => 'cnh', 'consent' => true])->assertStatus(302);

    authenticateSigner($this, $token);

    // Tipo inválido é conferido antes das fotos.
    verificationPost($this, $token, ['document_type' => 'titulo'])->assertStatus(422)->assertJsonPath('code', 'invalid_document_type');

    // Faltam fotos.
    verificationPost($this, $token)->assertStatus(422)
        ->assertJsonPath('code', 'captures_missing')
        ->assertJsonPath('message', 'Antes de enviar para a verificação, tire: Foto do rosto, Foto do documento (frente), Foto do documento (verso).');

    identityPostCapture($this, $token, 'selfie', identityPng())->assertCreated();
    identityPostCapture($this, $token, 'document_front', identityPng(), 'frente.png')->assertCreated();

    verificationPost($this, $token)->assertStatus(422)
        ->assertJsonPath('message', 'Antes de enviar para a verificação, tire: Foto do documento (verso).');

    identityPostCapture($this, $token, 'document_back', identityPng(), 'verso.png')->assertCreated()
        ->assertJsonPath('identity_verification.captures_complete', true)
        ->assertJsonPath('identity_verification.can_submit', true);

    // Consentimento é conferido por último, com as fotos já certas.
    verificationPost($this, $token, ['consent' => false])->assertStatus(422)->assertJsonPath('code', 'consent_required');
    verificationPost($this, $token, ['consent' => null])->assertStatus(422)->assertJsonPath('code', 'consent_required');

    expect(IdentityVerification::withoutOrganizationScope()->count())->toBe(0);

    // A outra pessoa (sem exigência): 404, mesmo autenticada.
    $this->flushSession();
    authenticateSigner($this, $ctx['tokens']['henrique@exemplo.test']);
    verificationPost($this, $ctx['tokens']['henrique@exemplo.test'])->assertNotFound();
    verificationShow($this, $ctx['tokens']['henrique@exemplo.test'])->assertNotFound();
});

it('simulador aprova: a linha, a resposta e a trilha só repetem o provedor, sem imagem', function () {
    Queue::fake([SubmitIdentityVerification::class]);

    $ctx = verificationEnvelope();
    $token = $ctx['token'];
    authenticateSigner($this, $token);
    verificationUploadPhotos($this, $token);

    // A fila recebe só o id; a linha nasce `queued`.
    $response = verificationPost($this, $token)->assertCreated()
        ->assertJsonPath('identity_verification.status', 'queued')
        ->assertJsonPath('identity_verification.can_submit', false)
        ->assertJsonPath('identity_verification.document_type', 'cnh')
        ->assertJsonPath('identity_verification.message', 'Fotos recebidas. O envio ao provedor Simulador está na fila.');

    $verification = IdentityVerification::withoutOrganizationScope()->firstOrFail();

    Queue::assertPushed(SubmitIdentityVerification::class, fn (SubmitIdentityVerification $job): bool => $job->verificationId === $verification->getKey()
        && $job->tries === 1
        && $job->timeout === 240
        && $job->queue === 'default');

    expect($verification->reference)->toHaveLength(26)
        ->and($verification->provider)->toBe('verificacao_simulada')
        ->and($verification->attempt)->toBe(1)
        ->and($verification->consent_version)->toBe(VerificationStep::consentVersion('Simulador'))
        ->and($verification->consented_at)->not->toBeNull()
        ->and(array_column($verification->captures, 'kind'))->toBe(['selfie', 'document_front', 'document_back'])
        ->and(json_encode($verification->captures))->not->toContain('identity/');

    // Uma segunda tentativa enquanto a primeira está na fila: recusada.
    verificationPost($this, $token)->assertStatus(409)->assertJsonPath('code', 'verification_in_progress');

    // O job roda: o simulador aprova.
    (new SubmitIdentityVerification((int) $verification->getKey()))->handle(app(IdentityVerifications::class));

    $verification->refresh();

    expect($verification->status->value)->toBe('approved')
        ->and($verification->submitted_at)->not->toBeNull()
        ->and($verification->completed_at)->not->toBeNull()
        ->and($verification->provider_verification_id)->toStartWith('sim-')
        ->and($verification->isSimulated())->toBeTrue()
        ->and($verification->reason_code)->toBe('simulated');

    verificationShow($this, $token)->assertOk()
        ->assertJsonPath('identity_verification.status', 'approved')
        ->assertJsonPath('identity_verification.status_label', 'Aprovada pelo provedor')
        ->assertJsonPath('identity_verification.message', 'Simulador informou: aprovado — a foto tirada na hora corresponde à do documento. (simulado) — nenhuma imagem foi analisada')
        ->assertJsonPath('identity_verification.simulated', true)
        ->assertJsonPath('identity_verification.attempts_used', 1)
        ->assertJsonPath('identity_verification.attempts_left', 2)
        ->assertJsonPath('identity_verification.captures_changed', false)
        ->assertJsonPath('identity_verification.can_submit', false);

    $events = AuditEvent::query()->withoutGlobalScopes()
        ->whereIn('event_type', [AuditEventType::IdentityVerificationSubmitted->value, AuditEventType::IdentityVerificationCompleted->value])
        ->orderBy('id')->get();

    expect($events->pluck('event_type')->map(fn ($type) => $type->value)->all())->toBe(['identity_verification.submitted', 'identity_verification.completed'])
        ->and($events[0]->payload['attempt'])->toBe(1)
        ->and($events[0]->payload['document_type'])->toBe('cnh')
        ->and(array_column($events[0]->payload['captures'], 'kind'))->toBe(['selfie', 'document_front', 'document_back'])
        ->and($events[1]->payload)->toBe([
            'status' => 'approved',
            'provider' => 'verificacao_simulada',
            'simulated' => true,
            'provider_verification_id' => $verification->provider_verification_id,
            'document_type' => 'cnh',
            'reason_code' => 'simulated',
            'source' => 'submit',
        ])
        ->and($events[1]->actor_type->value)->toBe('system');

    $trail = identityAuditPayloads();
    expect($trail)->not->toContain('identity/')
        ->and($trail)->not->toContain('data:image')
        ->and($trail)->not->toContain(base64_encode(substr(identityPng(320, 240), 0, 24)));

    // Refazer uma foto depois da aprovação: o provedor comparou outras imagens.
    identityPostCapture($this, $token, 'selfie', identityPng(300, 300))->assertCreated()
        ->assertJsonPath('identity_verification.status', 'approved')
        ->assertJsonPath('identity_verification.captures_changed', true)
        ->assertJsonPath('identity_verification.can_submit', true);
});

it('simulador reprova: a tentativa conta e o texto repete o provedor; três reprovações esgotam', function () {
    $ctx = verificationEnvelope();
    $token = $ctx['token'];
    authenticateSigner($this, $token);
    verificationUploadPhotos($this, $token);

    verificationFake()->simulate('rejected');

    // Fila síncrona: o job roda no commit e a resposta já traz o resultado.
    verificationPost($this, $token)->assertCreated()
        ->assertJsonPath('identity_verification.status', 'rejected')
        ->assertJsonPath('identity_verification.status_label', 'Reprovada pelo provedor')
        ->assertJsonPath('identity_verification.message', 'Simulador informou: reprovado. (simulado) — nenhuma imagem foi analisada')
        ->assertJsonPath('identity_verification.attempts_used', 1)
        ->assertJsonPath('identity_verification.attempts_left', 2)
        ->assertJsonPath('identity_verification.can_submit', true);

    verificationPost($this, $token, ['document_type' => 'rg'])->assertCreated()->assertJsonPath('identity_verification.attempts_left', 1);
    verificationPost($this, $token, ['document_type' => 'passaporte'])->assertCreated()
        ->assertJsonPath('identity_verification.attempts_left', 0)
        ->assertJsonPath('identity_verification.can_submit', false);

    verificationPost($this, $token)->assertStatus(409)->assertJsonPath('code', 'no_attempts_left');

    expect(IdentityVerification::withoutOrganizationScope()->count())->toBe(3)
        ->and(IdentityVerification::withoutOrganizationScope()->pluck('attempt')->all())->toBe([1, 2, 3]);
});

it('simulador pendente: o GET consulta o provedor no máximo a cada 10 s e aplica o prazo de espera', function () {
    $ctx = verificationEnvelope();
    $token = $ctx['token'];
    authenticateSigner($this, $token);
    verificationUploadPhotos($this, $token);

    verificationFake()->simulate('pending');

    verificationPost($this, $token)->assertCreated()
        ->assertJsonPath('identity_verification.status', 'pending')
        ->assertJsonPath('identity_verification.status_label', 'Em análise pelo provedor')
        ->assertJsonPath('identity_verification.message', 'Simulador está analisando as fotos. O resultado aparece aqui em instantes.')
        ->assertJsonPath('identity_verification.can_submit', false);

    $verification = IdentityVerification::withoutOrganizationScope()->firstOrFail();

    // O provedor passa a aprovar, mas a consulta anterior foi há menos de 10 s: continua pendente.
    verificationShow($this, $token)->assertOk()->assertJsonPath('identity_verification.status', 'pending');
    verificationFake()->simulate('approved');
    verificationShow($this, $token)->assertOk()->assertJsonPath('identity_verification.status', 'pending');

    $this->travel(11)->seconds();

    verificationShow($this, $token)->assertOk()
        ->assertJsonPath('identity_verification.status', 'approved')
        ->assertJsonPath('identity_verification.attempts_used', 1);

    $verification->refresh();
    expect($verification->completed_at)->not->toBeNull()
        ->and(AuditEvent::query()->withoutGlobalScopes()->where('event_type', AuditEventType::IdentityVerificationCompleted->value)->value('payload')['source'] ?? null)->toBe('poll');

    $this->travelBack();
});

it('pendente além do prazo vira inconclusiva, sem consumir tentativa; a linha na fila que ninguém pegou também', function () {
    $ctx = verificationEnvelope();
    $token = $ctx['token'];
    authenticateSigner($this, $token);
    verificationUploadPhotos($this, $token);

    verificationFake()->simulate('pending');
    verificationPost($this, $token)->assertCreated()->assertJsonPath('identity_verification.status', 'pending');

    $this->travel(21)->minutes();

    verificationShow($this, $token)->assertOk()
        ->assertJsonPath('identity_verification.status', 'inconclusive')
        ->assertJsonPath('identity_verification.status_label', 'Inconclusiva')
        ->assertJsonPath('identity_verification.message', 'Não foi possível concluir a verificação. Isso não é uma reprovação: você pode enviar as fotos de novo.')
        ->assertJsonPath('identity_verification.provider_message', 'O provedor não respondeu no prazo. Isso não é uma reprovação: envie as fotos de novo.')
        ->assertJsonPath('identity_verification.attempts_used', 0)
        ->assertJsonPath('identity_verification.can_submit', true);

    $timedOut = IdentityVerification::withoutOrganizationScope()->firstOrFail();
    expect($timedOut->reason_code)->toBe('timeout')
        // A marca do simulador não se perde quando o prazo esgota.
        ->and($timedOut->isSimulated())->toBeTrue();

    $this->travelBack();

    // Linha `queued` que o worker nunca pegou (fila parada): o mesmo prazo, via refresh().
    Queue::fake([SubmitIdentityVerification::class]);
    $this->travel(1)->minutes();
    verificationPost($this, $token)->assertCreated()->assertJsonPath('identity_verification.status', 'queued');
    $queued = IdentityVerification::withoutOrganizationScope()->orderByDesc('id')->firstOrFail();

    expect(app(IdentityVerifications::class)->refresh($queued)->status->value)->toBe('queued');

    $this->travel(21)->minutes();

    $refreshed = app(IdentityVerifications::class)->refresh($queued->fresh());
    expect($refreshed->status->value)->toBe('inconclusive')
        ->and($refreshed->reason_code)->toBe('timeout')
        ->and(app(IdentityVerifications::class)->attemptsUsed($ctx['recipients']['maria@exemplo.test']))->toBe(0);

    // O job que finalmente rodar encontra a linha já encerrada e não envia nada.
    (new SubmitIdentityVerification((int) $queued->getKey()))->handle(app(IdentityVerifications::class));
    expect($queued->fresh()->status->value)->toBe('inconclusive');

    $this->travelBack();
});

it('falha técnica do provedor não consome tentativa; um erro inesperado também vira inconclusiva', function () {
    $ctx = verificationEnvelope('verifiky');
    $token = $ctx['token'];
    authenticateSigner($this, $token);
    verificationUploadPhotos($this, $token);

    $double = verificationDouble('inconclusive', ['reason_code' => 'provider_unavailable', 'message' => 'O provedor demorou a responder. Isso não é uma reprovação: tente de novo em alguns instantes.'], null);

    verificationPost($this, $token)->assertCreated()
        ->assertJsonPath('identity_verification.status', 'inconclusive')
        ->assertJsonPath('identity_verification.provider_label', 'Verifiky')
        ->assertJsonPath('identity_verification.simulated', false)
        ->assertJsonPath('identity_verification.provider_message', 'O provedor demorou a responder. Isso não é uma reprovação: tente de novo em alguns instantes.')
        ->assertJsonPath('identity_verification.attempts_used', 0)
        ->assertJsonPath('identity_verification.attempts_left', 3)
        ->assertJsonPath('identity_verification.can_submit', true);

    // O provedor recebeu as três imagens, com os nomes de arquivo combinados e o nosso `reference`.
    $verification = IdentityVerification::withoutOrganizationScope()->firstOrFail();
    $call = $double->calls[0];

    expect($call['method'])->toBe('start')
        ->and($call['recipient'])->toBe($ctx['recipients']['maria@exemplo.test']->ulid)
        ->and($call['options']['reference'])->toBe($verification->reference)
        ->and($call['options']['document_type'])->toBe('cnh')
        ->and(array_keys($call['options']['images']))->toBe(['selfie', 'document_front', 'document_back'])
        ->and($call['options']['images']['selfie']->filename)->toBe('selfie.jpg')
        ->and($call['options']['images']['document_front']->filename)->toBe('documento-frente.jpg')
        ->and($call['options']['images']['document_back']->filename)->toBe('documento-verso.jpg')
        ->and($call['options']['images']['selfie']->mimeType)->toBe('image/jpeg')
        ->and(str_starts_with($call['options']['images']['selfie']->bytes, "\xFF\xD8"))->toBeTrue()
        ->and($call['correlation_id'])->toBe($verification->correlation_id)
        ->and($verification->reason_code)->toBe('provider_unavailable');

    // Provedor que lança: nunca deixa a linha presa em `queued`.
    app()->instance(IdentityVerificationProvider::class, new class implements IdentityVerificationProvider
    {
        public function start(string $recipientUlid, array $options = [], ?string $correlationId = null): array
        {
            throw new RuntimeException('boom');
        }

        public function result(string $verificationId, ?string $correlationId = null): array
        {
            throw new RuntimeException('boom');
        }

        public function isConfigured(): bool
        {
            return true;
        }

        public function name(): string
        {
            return 'verifiky';
        }

        public function label(): string
        {
            return 'Verifiky';
        }

        public function isSimulated(): bool
        {
            return false;
        }
    });

    verificationPost($this, $token)->assertCreated()
        ->assertJsonPath('identity_verification.status', 'inconclusive')
        ->assertJsonPath('identity_verification.attempts_used', 0);

    expect(IdentityVerification::withoutOrganizationScope()->orderByDesc('id')->value('reason_code'))->toBe('provider_error');

    // Sem imagem no disco (fotos refeitas e a linha antiga apagada): inconclusiva `missing_images`.
    verificationDouble('approved');
    Queue::fake([SubmitIdentityVerification::class]);
    verificationPost($this, $token)->assertCreated()->assertJsonPath('identity_verification.status', 'queued');
    $queued = IdentityVerification::withoutOrganizationScope()->orderByDesc('id')->firstOrFail();

    identityPostCapture($this, $token, 'selfie', identityPng(200, 200))->assertCreated();

    (new SubmitIdentityVerification((int) $queued->getKey()))->handle(app(IdentityVerifications::class));

    expect($queued->fresh()->status->value)->toBe('inconclusive')
        ->and($queued->fresh()->reason_code)->toBe('missing_images')
        ->and(app(IdentityVerifications::class)->attemptsUsed($ctx['recipients']['maria@exemplo.test']))->toBe(0);
});
