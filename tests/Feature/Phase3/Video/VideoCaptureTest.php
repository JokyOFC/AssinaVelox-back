<?php

use App\Enums\AuditEventType;
use App\Enums\MembershipRole;
use App\Events\EnvelopeReadyForFinalization;
use App\Models\AuditEvent;
use App\Models\SignatureAcceptance;
use App\Services\Identity\CaptureEvidence;
use App\Services\Identity\CaptureKind;
use App\Services\Identity\CapturePurge;
use App\Services\Identity\IdentityVideos;
use App\Services\Identity\Models\IdentityCapture;
use App\Services\Identity\VideoEvidence;
use App\Services\Identity\VideoStep;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/OrganizationHelpers.php';
require_once __DIR__.'/../../Sending/Support/SendingHelpers.php';
require_once __DIR__.'/../../Sign/Support/SignerHelpers.php';
require_once __DIR__.'/../../Phase2/Identity/Support/IdentityHelpers.php';
require_once __DIR__.'/Support/VideoHelpers.php';

/*
|--------------------------------------------------------------------------
| Fase 3 §3.3 (F-VIDEO) — aceite complementado por vídeo curto
|--------------------------------------------------------------------------
| Envio válido, contêiner conferido pelos bytes, tamanho e duração, consentimento, flag,
| isolamento, URL assinada curta, expurgo, evidência só citada e vocabulário. O vídeo é
| captura: nada aqui compara rostos, analisa o vídeo ou verifica identidade.
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

/**
 * Participante com vídeo exigido envia e aceita; devolve [ctx, bytes].
 *
 * @return array{0: array<string, mixed>, 1: string}
 */
function videoAccepted(object $test, ?string $raw = null): array
{
    $ctx = videoEnvelope();
    $props = authenticateSigner($test, $ctx['token']);
    $raw ??= videoWebm(6000.0);

    videoPost($test, $ctx['token'], $raw)->assertCreated();
    identityAccept($test, $ctx['token'], $props)->assertSessionHasNoErrors();

    return [$ctx, $raw];
}

it('envio válido: o vídeo exigido bloqueia o aceite até existir, fica cifrado, referenciado por SHA-256 e vinculado ao aceite', function () {
    $ctx = videoEnvelope();
    $token = $ctx['token'];
    $props = authenticateSigner($this, $token);

    expect($props['identity_video']['upload_url'])->not->toBeNull()
        ->and($props['identity_video']['max_seconds'])->toBe(10)
        ->and($props['identity_video']['audio'])->toBeFalse()
        ->and($props['identity_video']['consent_version'])->toBe(VideoStep::consentVersion())
        ->and($props['identity_video']['complete'])->toBeFalse()
        // A etapa de fotos continua ausente: só o vídeo foi exigido.
        ->and($props['identity_capture'])->toBeNull();

    identityAccept($this, $token, $props)->assertSessionHasErrors('signature');
    expect(session('errors')->first('signature'))->toContain('Vídeo curto')
        ->and(SignatureAcceptance::withoutOrganizationScope()->count())->toBe(0);

    $raw = videoWebm(6000.0);

    videoPost($this, $token, $raw)->assertCreated()
        ->assertJsonPath('identity_video.complete', true)
        ->assertJsonPath('capture.kind', 'video')
        ->assertJsonPath('capture.container', 'webm')
        ->assertJsonPath('capture.duration_ms', 6000);

    identityAccept($this, $token, $props)->assertSessionHasNoErrors();

    $acceptance = SignatureAcceptance::withoutOrganizationScope()->firstOrFail();
    $capture = IdentityCapture::withoutOrganizationScope()->firstOrFail();
    $snapshot = $acceptance->fields_snapshot['identity_captures'];

    expect($capture->kind)->toBe(CaptureKind::Video)
        ->and($capture->signature_acceptance_id)->toBe($acceptance->getKey())
        ->and($capture->sha256)->toBe(hash('sha256', $raw))
        ->and($capture->mime_type)->toBe('video/webm')
        ->and([$capture->width, $capture->height])->toBe([640, 480])
        ->and($capture->size_bytes)->toBe(strlen($raw))
        ->and($capture->source)->toBe('camera')
        ->and($capture->consent_version)->toBe(VideoStep::consentVersion())
        ->and($capture->consented_at)->not->toBeNull()
        ->and($snapshot)->toHaveCount(1)
        ->and($snapshot[0]['kind'])->toBe('video')
        ->and($snapshot[0]['sha256'])->toBe($capture->sha256)
        ->and($snapshot[0]['duration_ms'])->toBe(6000)
        ->and(json_encode($acceptance->fields_snapshot))->not->toContain('identity/');

    // Disco privado da organização, cifrado; guardado como veio (sem transcodificar).
    $stored = (string) Storage::disk('documents')->get((string) $capture->storage_path);

    expect($capture->storage_path)->toStartWith('orgs/'.$ctx['organization']->ulid.'/envelopes/'.$ctx['envelope']->ulid.'/identity/video-')
        ->and(str_contains($stored, substr($raw, 0, 32)))->toBeFalse()
        ->and(Crypt::decryptString($stored))->toBe($raw);

    $event = AuditEvent::query()->withoutGlobalScopes()->where('event_type', AuditEventType::IdentityVideoRecorded->value)->firstOrFail();

    expect($event->payload['sha256'])->toBe($capture->sha256)
        ->and($event->payload['source'])->toBe('camera')
        ->and($event->payload['consent_version'])->toBe(VideoStep::consentVersion())
        ->and(json_encode($event->payload))->not->toContain('identity/');
});

it('aceita MP4 e WebM sem duração no arquivo; refazer substitui o anterior e apaga o arquivo antigo', function () {
    $ctx = videoEnvelope();
    authenticateSigner($this, $ctx['token']);

    videoPost($this, $ctx['token'], videoMp4(4000), 'gravacao.mp4')->assertCreated()
        ->assertJsonPath('capture.container', 'mp4')
        ->assertJsonPath('capture.duration_ms', 4000);

    $first = IdentityCapture::withoutOrganizationScope()->firstOrFail();

    // O WebM do MediaRecorder não declara duração: vale a informada pelo navegador.
    videoPost($this, $ctx['token'], videoWebm(null), 'video.webm', ['duration_ms' => 7000])->assertCreated()
        ->assertJsonPath('capture.duration_ms', 7000);

    $second = IdentityCapture::withoutOrganizationScope()->firstOrFail();

    expect(IdentityCapture::withoutOrganizationScope()->count())->toBe(1)
        ->and($second->ulid)->not->toBe($first->ulid)
        ->and($second->duration_ms)->toBeNull()
        ->and($second->declared_duration_ms)->toBe(7000)
        ->and(Storage::disk('documents')->exists((string) $first->storage_path))->toBeFalse()
        ->and(videoDiskFiles())->toHaveCount(1);

    // Matroska (DocType `matroska`) também é aceito, com Content-Type próprio.
    videoPost($this, $ctx['token'], videoWebm(3000.0, docType: 'matroska'))->assertCreated()
        ->assertJsonPath('capture.container', 'matroska');
});

it('recusa contêiner falso: extensão .webm com bytes de outro formato, HTML disfarçado, só áudio e HEIC', function () {
    $ctx = videoEnvelope();
    authenticateSigner($this, $ctx['token']);

    $fakes = [
        'PNG com extensão .webm' => ['foto.webm', identityPng()],
        'HTML disfarçado' => ['video.webm', '<!DOCTYPE html><html><body><script>alert(1)</script></body></html>'],
        'HTML disfarçado de MP4' => ['video.mp4', str_repeat(' ', 4).'ftyp<html><script>alert(1)</script></html>'],
        'MP4 só com áudio' => ['video.mp4', videoMp4(3000, video: false)],
        'WebM só com áudio' => ['video.webm', videoWebm(3000.0, withVideoTrack: false)],
        'HEIC (também usa ftyp)' => ['video.mp4', videoMp4(3000, brand: 'heic', compatible: ['mif1', 'heic'])],
        'EBML truncado' => ['video.webm', "\x1A\x45\xDF\xA3".str_repeat("\x42", 40)],
        'DocType desconhecido' => ['video.webm', videoWebm(3000.0, docType: 'html')],
    ];

    foreach ($fakes as $label => [$name, $bytes]) {
        $response = videoPost($this, $ctx['token'], $bytes, $name);

        expect($response->status())->toBe(422, $label)
            ->and($response->json('code'))->toBe('invalid_video', $label)
            // A mensagem nunca ecoa o conteúdo enviado.
            ->and((string) $response->json('message'))->not->toContain('script');
    }

    expect(IdentityCapture::withoutOrganizationScope()->count())->toBe(0)
        ->and(videoDiskFiles())->toBe([]);
});

it('recusa acima do tamanho ou da duração (lida do arquivo ou informada pelo navegador)', function () {
    config()->set('assinavelox.capture_video.max_upload_kb', 256);

    $ctx = videoEnvelope(maxSeconds: 5);
    authenticateSigner($this, $ctx['token']);

    videoPost($this, $ctx['token'], videoWebm(3000.0, clusterBytes: 300 * 1024))
        ->assertStatus(422)
        ->assertJsonValidationErrors('video');

    videoPost($this, $ctx['token'], videoWebm(8000.0))->assertStatus(422)->assertJsonPath('code', 'video_too_long');
    videoPost($this, $ctx['token'], videoMp4(12000), 'video.mp4')->assertStatus(422)->assertJsonPath('code', 'video_too_long');
    videoPost($this, $ctx['token'], videoWebm(null), 'video.webm', ['duration_ms' => 9000])->assertStatus(422)->assertJsonPath('code', 'video_too_long');

    expect(IdentityCapture::withoutOrganizationScope()->count())->toBe(0);

    // Dentro da folga (5 s + 1,5 s): aceito.
    videoPost($this, $ctx['token'], videoWebm(6200.0))->assertCreated();
});

it('sem consentimento o vídeo não é recebido', function () {
    $ctx = videoEnvelope();
    authenticateSigner($this, $ctx['token']);

    videoPost($this, $ctx['token'], videoWebm(3000.0), 'video.webm', ['consent' => null])
        ->assertStatus(422)
        ->assertJsonValidationErrors('consent');

    videoPost($this, $ctx['token'], videoWebm(3000.0), 'video.webm', ['consent' => '0'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('consent');

    expect(session('errors'))->toBeNull()
        ->and(IdentityCapture::withoutOrganizationScope()->count())->toBe(0)
        ->and(videoDiskFiles())->toBe([]);
});

it('flag desligada: 404 no envio, na exigência, na lista e no arquivo; página, câmera e aceite ficam como antes', function () {
    [$ctx] = videoAccepted($this);
    $capture = IdentityCapture::withoutOrganizationScope()->firstOrFail();

    // Segunda pessoa, com exigência gravada enquanto a flag estava ligada.
    app(IdentityVideos::class)->setRequirement($ctx['envelope'], $ctx['recipients']['henrique@exemplo.test'], true, null, $ctx['owner']);
    $this->flushSession();

    config()->set('assinavelox.features.identity_video', false);

    $henrique = $ctx['tokens']['henrique@exemplo.test'];
    $page = $this->get(route('sign.show', ['token' => $henrique]))->assertOk();

    expect($page->viewData('page')['props'])->not->toHaveKey('identity_video')
        ->and((string) $page->headers->get('Permissions-Policy'))->toContain('camera=()');

    $props = authenticateSigner($this, $henrique);

    expect($props)->not->toHaveKey('identity_video');
    videoPost($this, $henrique, videoWebm(3000.0))->assertNotFound();

    identityAccept($this, $henrique, $props)->assertSessionHasNoErrors();

    $acceptance = SignatureAcceptance::withoutOrganizationScope()->where('recipient_id', $ctx['recipients']['henrique@exemplo.test']->getKey())->firstOrFail();
    expect($acceptance->fields_snapshot)->not->toHaveKey('identity_captures');

    actingAsMember($ctx['owner'], $ctx['organization']);

    $this->getJson(route('envelopes.identity_videos.index', ['envelope' => $ctx['envelope']->ulid]))->assertNotFound();
    $this->get(URL::temporarySignedRoute('envelopes.identity_videos.file', now()->addMinute(), [
        'envelope' => $ctx['envelope']->ulid, 'video' => $capture->ulid, 'mode' => 'play', 'u' => $ctx['owner']->getKey(), 'n' => 'x',
    ]))->assertNotFound();
    $this->putJson(route('envelopes.recipients.identity_video', [
        'envelope' => $ctx['envelope']->ulid,
        'recipient' => $ctx['recipients']['maria@exemplo.test']->ulid,
    ]), ['required' => false])->assertNotFound();
});

it('reprodução e download por URL assinada curta, com Content-Type fixo, nosniff e trilha; vencida, adulterada ou de outro usuário não abre', function () {
    [$ctx, $raw] = videoAccepted($this);

    actingAsMember($ctx['owner'], $ctx['organization']);

    $index = $this->getJson(route('envelopes.identity_videos.index', ['envelope' => $ctx['envelope']->ulid]))->assertOk();
    $item = $index->json('items.0');

    expect($index->json('items'))->toHaveCount(1)
        ->and($index->json('notice'))->toContain('Não houve verificação de identidade')
        ->and($item['label'])->toBe('Vídeo enviado pelo participante — origem informada pelo navegador: câmera')
        ->and($item['recipient_id'])->toBe($ctx['recipients']['maria@exemplo.test']->ulid)
        ->and($item['sha256'])->toBe(hash('sha256', $raw))
        ->and($item['mime_type'])->toBe('video/webm')
        ->and($item['play_url'])->toContain('signature=')
        ->and($item['play_url'])->toContain('expires=');

    $play = $this->get($item['play_url'])->assertOk();

    expect($play->headers->get('Content-Type'))->toBe('video/webm')
        ->and($play->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and((string) $play->headers->get('Content-Disposition'))->toStartWith('inline;')
        ->and((string) $play->headers->get('Cache-Control'))->toContain('no-store')
        ->and($play->getContent())->toBe($raw);

    // O player pede o arquivo de novo com a mesma URL: um único registro na trilha.
    $this->get($item['play_url'])->assertOk();

    $download = $this->get($item['download_url'])->assertOk();
    expect((string) $download->headers->get('Content-Disposition'))->toStartWith('attachment;')->toContain('.webm');

    $accesses = AuditEvent::query()->withoutGlobalScopes()
        ->where('event_type', AuditEventType::IdentityVideoAccessed->value)
        ->get()->map(fn (AuditEvent $event): string => $event->payload['mode'])->all();

    expect($accesses)->toEqualCanonicalizing(['play', 'download']);

    // Adulterada: outro modo ou outro parâmetro invalida a assinatura.
    $this->get(str_replace('mode=play', 'mode=download', $item['play_url']))->assertForbidden();
    $this->get($item['play_url'].'&extra=1')->assertForbidden();

    // Outro usuário da MESMA organização, com acesso ao envelope, não usa a URL de quem pediu.
    $admin = attachMember($ctx['organization'], MembershipRole::Admin);
    actingAsMember($admin, $ctx['organization']);
    $this->get($item['play_url'])->assertForbidden();

    // Vencida.
    actingAsMember($ctx['owner'], $ctx['organization']);
    $this->travel(VideoEvidence::playbackTtlSeconds() + 5)->seconds();
    $this->get($item['download_url'])->assertForbidden();
    $this->travelBack();

    expect(AuditEvent::query()->withoutGlobalScopes()->where('event_type', AuditEventType::IdentityVideoAccessed->value)->count())->toBe(2);
});

it('outra organização nunca vê nem baixa o vídeo; linha adulterada não vaza entre envelopes', function () {
    [$a] = videoAccepted($this);
    $this->flushSession();
    [$b] = videoAccepted($this, videoMp4(3000));

    $videoA = IdentityCapture::withoutOrganizationScope()->where('organization_id', $a['organization']->getKey())->firstOrFail();
    $videoB = IdentityCapture::withoutOrganizationScope()->where('organization_id', $b['organization']->getKey())->firstOrFail();

    actingAsMember($a['owner'], $a['organization']);
    $playA = $this->getJson(route('envelopes.identity_videos.index', ['envelope' => $a['envelope']->ulid]))->json('items.0.play_url');

    // B não vê a lista de A nem abre a URL emitida para A.
    actingAsMember($b['owner'], $b['organization']);
    $this->getJson(route('envelopes.identity_videos.index', ['envelope' => $a['envelope']->ulid]))->assertNotFound();
    $this->get($playA)->assertNotFound();

    // URL assinada para o envelope de B apontando para o vídeo de A: não encontra.
    $this->get(URL::temporarySignedRoute('envelopes.identity_videos.file', now()->addMinute(), [
        'envelope' => $b['envelope']->ulid, 'video' => $videoA->ulid, 'mode' => 'play', 'u' => $b['owner']->getKey(), 'n' => 'y',
    ]))->assertNotFound();

    // Linha adulterada: vídeo de B apontando para o envelope de A continua fora da lista de A.
    $videoB->forceFill(['envelope_id' => $a['envelope']->getKey()])->save();
    $ids = collect(app(VideoEvidence::class)->forEnvelope($a['envelope'], $a['owner'])['items'])->pluck('id')->all();

    expect($ids)->toBe([$videoA->ulid])
        ->and(array_keys(app(VideoEvidence::class)->pdfLines($a['envelope'])))->toBe([$a['recipients']['maria@exemplo.test']->ulid]);
});

it('retenção e expurgo iguais aos da foto: o arquivo sai, a linha e o resumo ficam; vídeo sem aceite sai inteiro', function () {
    config()->set('assinavelox.capture.retention_days', 180);
    config()->set('assinavelox.capture.orphan_retention_hours', 48);

    [$ctx] = videoAccepted($this);
    $this->flushSession();

    // Vídeo de quem abandonou a assinatura (sem aceite).
    app(IdentityVideos::class)->setRequirement($ctx['envelope'], $ctx['recipients']['henrique@exemplo.test'], true, null, $ctx['owner']);
    authenticateSigner($this, $ctx['tokens']['henrique@exemplo.test']);
    videoPost($this, $ctx['tokens']['henrique@exemplo.test'], videoWebm(3000.0))->assertCreated();

    $linked = IdentityCapture::withoutOrganizationScope()->whereNotNull('signature_acceptance_id')->firstOrFail();
    $orphan = IdentityCapture::withoutOrganizationScope()->whereNull('signature_acceptance_id')->firstOrFail();

    expect(app(CapturePurge::class)->run(now()->addHours(49)))->toBe(['expired' => 0, 'orphans' => 1])
        ->and(IdentityCapture::withoutOrganizationScope()->whereKey($orphan->getKey())->exists())->toBeFalse()
        ->and(Storage::disk('documents')->exists((string) $orphan->storage_path))->toBeFalse();

    expect(app(CapturePurge::class)->run(now()->addDays(181)))->toBe(['expired' => 1, 'orphans' => 0]);

    $purged = $linked->fresh();

    expect($purged->storage_path)->toBeNull()
        ->and($purged->purged_at)->not->toBeNull()
        ->and($purged->sha256)->toBe($linked->sha256)
        ->and(videoDiskFiles())->toBe([]);

    actingAsMember($ctx['owner'], $ctx['organization']);
    $item = $this->getJson(route('envelopes.identity_videos.index', ['envelope' => $ctx['envelope']->ulid]))->assertOk()->json('items.0');

    expect($item['available'])->toBeFalse()
        ->and($item['purged_at'])->not->toBeNull()
        ->and($item['play_url'])->toBeNull()
        ->and($item['download_url'])->toBeNull()
        ->and(app(VideoEvidence::class)->pdfLines($ctx['envelope'])[$ctx['recipients']['maria@exemplo.test']->ulid])
        ->toContain('Arquivo excluído pela política de retenção');

    $this->get(URL::temporarySignedRoute('envelopes.identity_videos.file', now()->addMinute(), [
        'envelope' => $ctx['envelope']->ulid, 'video' => $linked->ulid, 'mode' => 'play', 'u' => $ctx['owner']->getKey(), 'n' => 'z',
    ]))->assertNotFound();

    $reasons = AuditEvent::query()->withoutGlobalScopes()
        ->where('event_type', AuditEventType::IdentityCapturePurged->value)
        ->get()->map(fn (AuditEvent $event): array => $event->payload)->all();

    expect($reasons)->toEqualCanonicalizing([['count' => 1, 'reason' => 'orphan'], ['count' => 1, 'reason' => 'retention']]);
});

it('a evidência só cita o vídeo (tipo, SHA-256, origem declarada), nunca o embute; fotos e verificação pública não mudam', function () {
    [$ctx, $raw] = videoAccepted($this);
    $sha = hash('sha256', $raw);
    $maria = $ctx['recipients']['maria@exemplo.test'];

    $line = app(VideoEvidence::class)->pdfLines($ctx['envelope'])[$maria->ulid];

    expect($line)->toContain('Vídeo enviado pelo participante — origem informada pelo navegador: câmera')
        ->toContain('WebM')
        ->toContain('6,0 s')
        ->toContain($sha)
        ->toContain('não faz parte deste PDF')
        ->not->toContain('identity/');

    // A lista de fotos da página de evidências não ganha o vídeo (sem miniatura "indisponível").
    expect(app(CaptureEvidence::class)->forEnvelope($ctx['envelope'])['recipients'])->toBe([]);

    // HTML do relatório de evidências: a linha aparece; bytes do vídeo, não.
    $data = videoEvidenceData($ctx);
    $html = view('evidence.page', ['evidence' => $data])->render();
    $row = collect($data['participants'])->firstWhere('email', 'maria@exemplo.test');
    $other = collect($data['participants'])->firstWhere('email', 'henrique@exemplo.test');

    expect($row['identity_video_label'])->toBe($line)
        // Sem vídeo, a linha do participante é idêntica à de antes (a chave nem existe).
        ->and($other)->not->toHaveKey('identity_video_label');

    expect($html)->toContain($sha)
        ->toContain('não faz parte deste PDF')
        ->not->toContain(base64_encode(substr($raw, 0, 48)))
        ->not->toContain('<video')
        ->not->toContain('data:video');

    // A verificação pública não fala do vídeo nem expõe o resumo.
    $public = (string) $this->get(route('verify.show', ['code' => $ctx['envelope']->verification_code]))->assertOk()->getContent();
    $public = (string) preg_replace('/(&quot;|")features\1:\{[^}]*\}/', '', $public);

    expect($public)->not->toContain($sha)
        ->and(mb_strtolower($public))->not->toContain('vídeo')
        ->and($public)->not->toContain('identity_video');
});

it('vocabulário: vídeo é captura — nunca verificação de identidade, biometria ou prova de vida', function () {
    $labels = collect(AuditEventType::cases())
        ->filter(fn (AuditEventType $type): bool => str_starts_with($type->value, 'identity_video.'))
        ->map->label()->implode(' ');

    $front = collect(['video-capture-step.tsx', 'video-requirement-control.tsx', 'identity-video-panel.tsx', 'video-types.ts'])
        ->map(fn (string $file): string => (string) File::get(resource_path('js/components/identity/'.$file)))
        ->implode("\n");

    $texts = mb_strtolower(implode(' ', [
        VideoStep::TITLE, VideoStep::PURPOSE, VideoStep::AUDIENCE, VideoStep::NOTICE, VideoStep::CONSENT, VideoStep::FALLBACK,
        VideoEvidence::NOTICE, VideoEvidence::label('camera'), VideoEvidence::label('upload'), VideoEvidence::label(null), VideoEvidence::PDF_SUFFIX,
        CaptureKind::Video->label(), CaptureKind::Video->instructions(), $labels, $front,
    ]));

    foreach (['identidade verificada', 'identidade confirmada', 'biometria', 'biométric', 'liveness', 'prova de vida', 'reconhecimento facial', 'face match', 'assinatura avançada', 'qualificada', 'reconhecimento de firma', 'em nome de'] as $forbidden) {
        expect($texts)->not->toContain($forbidden);
    }

    expect(VideoStep::NOTICE)->toContain('não é usado para verificar sua identidade')
        ->and(VideoEvidence::NOTICE)->toContain('Não houve verificação de identidade');
});
