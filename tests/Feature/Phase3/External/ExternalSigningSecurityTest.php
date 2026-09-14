<?php

use App\Enums\AuditEventType;
use App\Enums\DocumentVersionKind;
use App\Enums\EnvelopeStatus;
use App\Enums\SignatureStatus;
use App\Integrations\LocalSigner\Exceptions\LocalSignerUnavailable;
use App\Integrations\LocalSigner\FakeLocalSigner;
use App\Integrations\LocalSigner\NexuLocalSigner;
use App\Models\AuditEvent;
use App\Models\ParticipantSignatureRequest;
use App\Models\PendingExternalSignature;
use App\Models\VerificationRecord;
use App\Services\Signing\External\ExternalTrustAnchors;
use App\Services\Signing\External\PendingSignatureFiles;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

require_once __DIR__.'/Support/ExternalSigningHelpers.php';

/*
|--------------------------------------------------------------------------
| Segredos, flag, componentes e âncoras (roadmap T8, T10, T1; viabilidade classe B)
|--------------------------------------------------------------------------
*/

beforeEach(fn () => externalBoot($this));
afterEach(fn () => externalTeardown($this));

it('nenhum estado pendente contém segredo: sem chave, sem senha, e o digest não vai para log nem trilha', function () {
    $logs = [];
    Log::listen(function (MessageLogged $event) use (&$logs): void {
        $logs[] = $event->message.' '.json_encode($event->context, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    });

    externalTokenBridge();
    $scenario = externalScenario($this->work);
    $token = externalSigner($this->work.'/token', 'Maria Alves Souza');
    $keyPem = (string) file_get_contents($token['key_pem']);
    $keyBody = (string) preg_replace('/-----[^-]+-----|\s+/', '', $keyPem);
    $simulatorPfx = (string) file_get_contents($this->simulator['pfx']);
    $needles = [
        EXTERNAL_SIMULATOR_PASSWORD,
        'PRIVATE KEY',
        substr($keyBody, 80, 60),
        bin2hex(substr((string) base64_decode($keyBody), 60, 40)),
        base64_encode(substr($simulatorPfx, 96, 48)),
        bin2hex(substr($simulatorPfx, 96, 48)),
    ];

    externalIntent($this, $scenario, 'maria@exemplo.test')->assertCreated();
    externalIntent($this, $scenario, 'joao@exemplo.test')->assertCreated();
    finalizationRun($scenario['envelope']);

    // Reserva ativa: linha + arquivos (revisão pendente e estado mínimo).
    $prepared = externalTokenPrepare($this, $scenario, 'maria@exemplo.test', $token)->assertCreated()->json('pending');
    $pending = PendingExternalSignature::withoutOrganizationScope()->firstOrFail();
    $files = app(PendingSignatureFiles::class);
    $stateText = (string) file_get_contents($files->path($pending->ulid, PendingSignatureFiles::STATE));
    $state = json_decode($stateText, true);
    $digestHex = bin2hex((string) base64_decode($prepared['digest'], true));

    expect(array_keys($state))->toEqualCanonicalizing([
        'format', 'field_name', 'md_algorithm', 'signature_mechanism', 'prefer_pss', 'document_digest_hex',
        'reserved_region_start', 'reserved_region_end', 'bytes_reserved', 'signed_attrs_der_b64',
        'signed_attrs_digest_hex', 'signer_cert_fingerprint_sha256', 'chain_fingerprints_sha256', 'base_size',
        'base_sha256', 'pending_size', 'pending_sha256', 'previous_signature_count',
    ])
        ->and($pending->toArray())->not->toHaveKey('digest_hex')
        ->and(json_encode($pending->toArray()))->not->toContain($digestHex);

    foreach ($needles as $needle) {
        expect(str_contains($stateText, $needle))->toBeFalse()
            ->and(str_contains(serialize($pending->getAttributes()), $needle))->toBeFalse();
    }

    // Conclui a Maria (componente) e o João (simulador).
    externalSubmit($this, $scenario, 'maria@exemplo.test', [
        'pending_id' => $prepared['id'], 'mode' => 'raw', 'signature' => externalSignRaw($token, $prepared['digest']),
        'certificate' => $token['certificate'], 'chain' => $token['chain'],
    ])->assertOk()->assertJsonPath('stage', 'applied');
    $simulated = externalSimulatedPrepare($this, $scenario, 'joao@exemplo.test');
    externalSimulatorSign($this, $scenario, 'joao@exemplo.test', $simulated['id'])->assertOk()->assertJsonPath('stage', 'applied');

    expect($scenario['envelope']->refresh()->status)->toBe(EnvelopeStatus::Completed);

    // Varredura: TODAS as tabelas, a trilha e os logs.
    $dump = '';

    foreach (DB::select("select name from sqlite_master where type = 'table'") as $table) {
        foreach (DB::table($table->name)->get() as $row) {
            $dump .= serialize((array) $row);
        }
    }

    $events = AuditEvent::withoutOrganizationScope()->get()->map(fn (AuditEvent $event): string => json_encode($event->payload) ?: '')->implode("\n");
    $log = implode("\n", $logs);

    foreach ($needles as $needle) {
        expect(str_contains($dump, $needle))->toBeFalse()
            ->and(str_contains($events, $needle))->toBeFalse()
            ->and(str_contains($log, $needle))->toBeFalse();
    }

    // O digest entregue ao componente não aparece na trilha nem nos logs.
    expect(str_contains($events, $digestHex))->toBeFalse()
        ->and(str_contains($log, $digestHex))->toBeFalse()
        ->and(str_contains($log, $prepared['digest']))->toBeFalse()
        ->and(AuditEvent::withoutOrganizationScope()->where('event_type', AuditEventType::ParticipantCertificateSubmitted->value)->count())->toBe(2)
        ->and(externalLeftovers($this))->toBe([]);
});

it('com a flag desligada nada muda: rotas 404 e a finalização é a de antes', function () {
    $scenario = externalScenario($this->work);
    config()->set('assinavelox.external_signing.enabled', false);

    externalShow($this, $scenario, 'maria@exemplo.test')->assertNotFound();
    externalIntent($this, $scenario, 'maria@exemplo.test')->assertNotFound();
    externalSimulatorCertificate($this, $scenario, 'maria@exemplo.test')->assertNotFound();
    externalPrepare($this, $scenario, 'maria@exemplo.test', [
        'document_id' => $scenario['document']->ulid, 'component' => 'simulated', 'mode' => 'raw', 'certificate' => 'AA==',
    ])->assertNotFound();

    // Interruptor global ligado, mas o plano não inclui o item: também 404.
    externalEnable($scenario['organization'], false);
    externalIntent($this, $scenario, 'maria@exemplo.test')->assertNotFound();

    finalizationRun($scenario['envelope']);

    $envelope = $scenario['envelope']->refresh();
    $record = VerificationRecord::query()->where('envelope_id', $envelope->getKey())->firstOrFail();
    $evidenceEvent = AuditEvent::withoutOrganizationScope()->where('event_type', AuditEventType::EnvelopeEvidenceGenerated->value)->firstOrFail();

    expect($envelope->status)->toBe(EnvelopeStatus::Completed)
        ->and($record->signature_status)->toBe(SignatureStatus::CompanyA1)
        ->and($record->validation_result)->not->toHaveKey('incremental_chain')
        ->and($evidenceEvent->payload)->not->toHaveKey('participant_mode')
        ->and(participantA1Versions($scenario['document'], DocumentVersionKind::PreSignature))->toBe([])
        ->and(ParticipantSignatureRequest::withoutOrganizationScope()->count())->toBe(0)
        ->and(PendingExternalSignature::withoutOrganizationScope()->count())->toBe(0);

    $props = $this->get(route('verify.show', ['code' => $envelope->verification_code]))->viewData('page')['props'];
    expect($props['result'])->not->toHaveKey('participant_signatures');
});

it('o simulador só existe em teste/local e o NexU está com a produção desabilitada', function () {
    $simulator = app(FakeLocalSigner::class);
    expect($simulator->detect()->available)->toBeTrue()
        ->and($simulator->isSimulated())->toBeTrue()
        ->and($simulator->producesTokenSignatures())->toBeFalse();

    // Em produção o simulador não assina nada, mesmo configurado.
    $original = app()['env'];
    app()['env'] = 'production';

    try {
        expect($simulator->detect()->available)->toBeFalse()
            ->and($simulator->detect()->reason)->toBe('environment_not_allowed')
            ->and(fn () => $simulator->signDigest('simulated:x', str_repeat('a', 32), 'SHA256'))->toThrow(LocalSignerUnavailable::class);
    } finally {
        app()['env'] = $original;
    }

    $nexu = app(NexuLocalSigner::class);
    expect(NexuLocalSigner::PRODUCTION_ENABLED)->toBeFalse()
        ->and($nexu->detect()->available)->toBeFalse()
        ->and($nexu->detect()->reason)->toBe('production_disabled')
        ->and($nexu->protocol()['endpoints']['sign']['path'])->toBe('/v1/sign')
        ->and(NexuLocalSigner::missingForProduction())->toHaveCount(5)
        ->and(fn () => $nexu->signDigest('k', str_repeat('a', 32), 'SHA256'))->toThrow(LocalSignerUnavailable::class);

    // Pela rota: o NexU (sem dublê) é recusado.
    $scenario = externalScenario($this->work);
    $token = externalSigner($this->work.'/token', 'Maria Alves Souza');
    externalIntent($this, $scenario, 'maria@exemplo.test')->assertCreated();
    finalizationRun($scenario['envelope']);

    externalTokenPrepare($this, $scenario, 'maria@exemplo.test', $token)->assertStatus(409)
        ->assertJsonPath('code', 'component_unavailable')
        ->assertJsonPath('reason', 'production_disabled');

    // Simulador desligado: as rotas dele somem.
    config()->set('assinavelox.external_signing.components.simulated.enabled', false);
    externalSimulatorCertificate($this, $scenario, 'maria@exemplo.test')->assertNotFound();
});

it('âncoras fixadas por impressão digital: com a âncora certa a cadeia é validada; com a errada, "não verificada"', function () {
    $fingerprint = ExternalTrustAnchors::fingerprint((string) file_get_contents($this->simulator['ca']));
    expect($fingerprint)->toMatch('/^[0-9a-f]{64}$/');

    config()->set('assinavelox.external_signing.trust_anchors', [['path' => $this->simulator['ca'], 'sha256' => str_repeat('0', 64)]]);
    $resolved = app(ExternalTrustAnchors::class)->resolve();
    expect($resolved['pinned'])->toBe(0)->and($resolved['rejected'][0]['reason'])->toBe('fingerprint_mismatch');

    $scenario = externalScenario($this->work);
    externalIntent($this, $scenario, 'maria@exemplo.test')->assertCreated();
    finalizationRun($scenario['envelope']);

    // Âncora com impressão digital errada: ignorada — cadeia "não verificada".
    $prepared = externalSimulatedPrepare($this, $scenario, 'maria@exemplo.test');
    expect($prepared['chain']['trusted'])->toBeFalse()->and($prepared['chain']['label'])->toContain('não verificada');

    // Âncora certa: validada até ela (e ainda assim nunca "ICP-Brasil"; revogação não verificada).
    config()->set('assinavelox.external_signing.trust_anchors', [['path' => $this->simulator['ca'], 'sha256' => $fingerprint]]);
    $prepared = externalSimulatedPrepare($this, $scenario, 'maria@exemplo.test');
    expect($prepared['chain']['trusted'])->toBeTrue()
        ->and($prepared['chain']['label'])->toContain('validada até uma âncora')->not->toContain('ICP-Brasil')
        ->and($prepared['chain']['revocation'])->toBe('not_checked');

    externalSimulatorSign($this, $scenario, 'maria@exemplo.test', $prepared['id'])->assertOk()->assertJsonPath('stage', 'applied');
    expect(PendingExternalSignature::withoutOrganizationScope()->where('status', 'superseded')->count())->toBe(1);
});
