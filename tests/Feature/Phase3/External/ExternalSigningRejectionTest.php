<?php

use App\Enums\DocumentVersionKind;
use App\Enums\EnvelopeStatus;
use App\Enums\ParticipantSignatureRequestStatus;
use App\Enums\PendingExternalSignatureStatus;
use App\Enums\SignatureStatus;
use App\Models\ParticipantSignature;
use App\Models\ParticipantSignatureRequest;
use App\Models\PendingExternalSignature;
use App\Models\VerificationRecord;
use App\Services\Pdf\PdfToolClient;
use App\Services\Signing\Certificates\IncrementalChain;
use Illuminate\Support\Carbon;

require_once __DIR__.'/Support/ExternalSigningHelpers.php';

/*
|--------------------------------------------------------------------------
| Digest expirado, reutilizado ou de outra revisão; assinatura adulterada; outro certificado
|--------------------------------------------------------------------------
| Toda recusa consome a reserva (uso único) e não grava nada. O aceite eletrônico continua.
*/

beforeEach(fn () => externalBoot($this));
afterEach(fn () => externalTeardown($this));

it('digest expirado é recusado; a reserva vence e pode ser refeita', function () {
    $scenario = externalScenario($this->work);
    $maria = 'maria@exemplo.test';

    externalIntent($this, $scenario, $maria)->assertCreated();
    finalizationRun($scenario['envelope']);
    $prepared = externalSimulatedPrepare($this, $scenario, $maria);

    Carbon::setTestNow(now()->addMinutes(11));

    try {
        externalSimulatorSign($this, $scenario, $maria, $prepared['id'])->assertStatus(409)->assertJsonPath('code', 'expired');
    } finally {
        Carbon::setTestNow();
    }

    $pending = PendingExternalSignature::withoutOrganizationScope()->where('ulid', $prepared['id'])->firstOrFail();
    expect($pending->status)->toBe(PendingExternalSignatureStatus::Expired)
        ->and($pending->reservation_key)->toBeNull()
        ->and(is_dir($this->work.'/externa/'.$pending->ulid))->toBeFalse()
        ->and(participantA1Versions($scenario['document'], DocumentVersionKind::SignedIncremental))->toBe([])
        ->and($scenario['envelope']->refresh()->status)->toBe(EnvelopeStatus::Finalizing);

    // Nova preparação: novo digest, e aí sim a assinatura entra.
    $again = externalSimulatedPrepare($this, $scenario, $maria);
    expect($again['id'])->not->toBe($prepared['id']);
    externalSimulatorSign($this, $scenario, $maria, $again['id'])->assertOk()->assertJsonPath('stage', 'applied');
});

it('digest reutilizado é recusado: a reserva só é consumida uma vez', function () {
    externalTokenBridge();
    $scenario = externalScenario($this->work);
    $maria = 'maria@exemplo.test';
    $token = externalSigner($this->work.'/token', 'Maria Alves Souza');

    externalIntent($this, $scenario, $maria)->assertCreated();
    finalizationRun($scenario['envelope']);
    $prepared = externalTokenPrepare($this, $scenario, $maria, $token)->assertCreated()->json('pending');
    $body = [
        'pending_id' => $prepared['id'],
        'mode' => 'raw',
        'signature' => externalSignRaw($token, $prepared['digest']),
        'certificate' => $token['certificate'],
        'chain' => $token['chain'],
    ];

    externalSubmit($this, $scenario, $maria, $body)->assertOk()->assertJsonPath('stage', 'applied');
    externalSubmit($this, $scenario, $maria, $body)->assertStatus(409)->assertJsonPath('code', 'already_consumed');

    expect(ParticipantSignature::withoutOrganizationScope()->count())->toBe(1)
        ->and(participantA1Versions($scenario['document'], DocumentVersionKind::SignedIncremental))->toHaveCount(1);
});

it('digest de outra revisão é recusado: outro participante assinou depois da preparação (compare-and-set)', function () {
    externalTokenBridge();
    $scenario = externalScenario($this->work);
    participantA1Enable($scenario['organization']);
    $envelope = $scenario['envelope'];
    $document = $scenario['document'];
    $token = externalSigner($this->work.'/token', 'Maria Alves Souza');
    $joao = participantA1Certificate($this->work.'/certs', 'Joao Lima', 'senha-do-Joao-Stale-3!');

    // Maria assina por componente local; João com o próprio A1 (arquivo).
    externalIntent($this, $scenario, 'maria@exemplo.test')->assertCreated();
    participantA1Intent($this, $scenario, 'joao@exemplo.test')->assertCreated();
    finalizationRun($envelope);

    $prepared = externalTokenPrepare($this, $scenario, 'maria@exemplo.test', $token)->assertCreated()->json('pending');
    [$base] = participantA1Versions($document, DocumentVersionKind::PreSignature);

    // Enquanto a Maria está no token, o João aplica o A1: a revisão mais recente muda.
    participantA1Submit($this, $scenario, 'joao@exemplo.test', $joao)->assertStatus(202)->assertJsonPath('stage', 'applied');
    $afterJoao = participantA1Versions($document, DocumentVersionKind::SignedIncremental);
    expect($afterJoao)->toHaveCount(1);

    // A assinatura da Maria é sobre o digest da revisão ANTERIOR: recusada, nada gravado.
    externalSubmit($this, $scenario, 'maria@exemplo.test', [
        'pending_id' => $prepared['id'],
        'mode' => 'raw',
        'signature' => externalSignRaw($token, $prepared['digest']),
        'certificate' => $token['certificate'],
        'chain' => $token['chain'],
    ])->assertStatus(409)->assertJsonPath('code', 'stale_revision');

    $pending = PendingExternalSignature::withoutOrganizationScope()->where('ulid', $prepared['id'])->firstOrFail();
    expect($pending->status)->toBe(PendingExternalSignatureStatus::Rejected)
        ->and($pending->failure_code)->toBe('stale_revision')
        ->and($pending->base_document_version_id)->toBe($base->getKey())
        ->and(participantA1Versions($document, DocumentVersionKind::SignedIncremental))->toHaveCount(1)
        ->and(ParticipantSignatureRequest::withoutOrganizationScope()->where('signature_method', 'local_component')->firstOrFail()->status)
        ->toBe(ParticipantSignatureRequestStatus::Requested);

    // Preparando de novo, a base é a revisão do João — e a Maria assina por cima dela.
    $again = externalTokenPrepare($this, $scenario, 'maria@exemplo.test', $token)->assertCreated()->json('pending');
    expect(PendingExternalSignature::withoutOrganizationScope()->where('ulid', $again['id'])->value('base_document_version_id'))->toBe($afterJoao[0]->getKey());

    externalSubmit($this, $scenario, 'maria@exemplo.test', [
        'pending_id' => $again['id'],
        'mode' => 'raw',
        'signature' => externalSignRaw($token, $again['digest']),
        'certificate' => $token['certificate'],
        'chain' => $token['chain'],
    ])->assertOk()->assertJsonPath('stage', 'applied');

    $envelope->refresh();
    $record = VerificationRecord::query()->where('envelope_id', $envelope->getKey())->firstOrFail();
    $final = finalizationDownload($envelope->finalVersion, $this->work.DIRECTORY_SEPARATOR.'final.pdf');
    $validation = app(PdfToolClient::class)->validate($final, [$token['ca_pem'], $joao['ca'], $this->operator['pem']]);

    // A1 (João) + externa (Maria) + operadora: cadeia íntegra; o status diz que há assinatura externa.
    expect($envelope->status)->toBe(EnvelopeStatus::Completed)
        ->and($record->signature_status)->toBe(SignatureStatus::ParticipantExternal)
        ->and($validation->signatureCount)->toBe(3)
        ->and($validation->allTrusted())->toBeTrue()
        ->and(IncrementalChain::analyse($validation, 3)['ok'])->toBeTrue();
});

it('assinatura adulterada, certificado diferente do anunciado e CMS de outro conteúdo são recusados sem gravar nada', function () {
    externalTokenBridge();
    $scenario = externalScenario($this->work);
    $maria = 'maria@exemplo.test';
    $token = externalSigner($this->work.'/token', 'Maria Alves Souza');
    $other = externalSigner($this->work.'/outro', 'Outra Pessoa');

    externalIntent($this, $scenario, $maria)->assertCreated();
    finalizationRun($scenario['envelope']);

    // 1. Assinatura adulterada.
    $prepared = externalTokenPrepare($this, $scenario, $maria, $token)->assertCreated()->json('pending');
    $signature = base64_decode(externalSignRaw($token, $prepared['digest']), true);
    $signature[10] = chr(ord($signature[10]) ^ 0x01);

    externalSubmit($this, $scenario, $maria, [
        'pending_id' => $prepared['id'], 'mode' => 'raw', 'signature' => base64_encode($signature),
        'certificate' => $token['certificate'], 'chain' => $token['chain'],
    ])->assertStatus(422)->assertJsonPath('code', 'signature_invalid');

    expect(PendingExternalSignature::withoutOrganizationScope()->where('ulid', $prepared['id'])->firstOrFail()->status->value)->toBe('rejected');

    // 2. Outro titular assina com a própria chave e o próprio certificado.
    $prepared = externalTokenPrepare($this, $scenario, $maria, $token)->assertCreated()->json('pending');
    externalSubmit($this, $scenario, $maria, [
        'pending_id' => $prepared['id'], 'mode' => 'raw', 'signature' => externalSignRaw($other, $prepared['digest']),
        'certificate' => $other['certificate'], 'chain' => $other['chain'],
    ])->assertStatus(422)->assertJsonPath('code', 'certificate_mismatch');

    // 3. CMS sobre outro conteúdo (outra revisão).
    $prepared = externalTokenPrepare($this, $scenario, $maria, $token, 'cms')->assertCreated()->json('pending');
    externalSubmit($this, $scenario, $maria, [
        'pending_id' => $prepared['id'], 'mode' => 'cms', 'cms' => externalSignCms($token, base64_encode(random_bytes(32))),
    ])->assertStatus(422)->assertJsonPath('code', 'digest_mismatch');

    // 4. Simulador fora do simulador: recusado.
    expect(participantA1Versions($scenario['document'], DocumentVersionKind::SignedIncremental))->toBe([])
        ->and(ParticipantSignature::withoutOrganizationScope()->count())->toBe(0)
        ->and(PendingExternalSignature::withoutOrganizationScope()->where('status', 'rejected')->count())->toBe(3)
        ->and(ParticipantSignatureRequest::withoutOrganizationScope()->firstOrFail()->status)->toBe(ParticipantSignatureRequestStatus::Requested)
        ->and($scenario['envelope']->refresh()->status)->toBe(EnvelopeStatus::Finalizing)
        ->and(externalLeftovers($this))->toBe([]);
});

it('uma preparação do simulador não é concluída pelo envio comum, e o simulador não assina com outro certificado', function () {
    $scenario = externalScenario($this->work);
    $maria = 'maria@exemplo.test';
    $token = externalSigner($this->work.'/token', 'Maria Alves Souza');

    externalIntent($this, $scenario, $maria)->assertCreated();
    finalizationRun($scenario['envelope']);

    externalPrepare($this, $scenario, $maria, [
        'document_id' => $scenario['document']->ulid, 'component' => 'simulated', 'mode' => 'raw',
        'certificate' => $token['certificate'], 'chain' => $token['chain'],
    ])->assertStatus(422)->assertJsonPath('code', 'certificate_mismatch');

    $prepared = externalSimulatedPrepare($this, $scenario, $maria);
    externalSubmit($this, $scenario, $maria, [
        'pending_id' => $prepared['id'], 'mode' => 'raw', 'signature' => base64_encode(random_bytes(256)),
        'certificate' => $token['certificate'],
    ])->assertStatus(409)->assertJsonPath('code', 'simulator_only');

    expect(PendingExternalSignature::withoutOrganizationScope()->where('ulid', $prepared['id'])->firstOrFail()->status->value)->toBe('pending');
});
