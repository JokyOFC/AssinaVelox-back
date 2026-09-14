<?php

use App\Enums\DocumentVersionKind;
use App\Enums\EnvelopeStatus;
use App\Enums\PendingExternalSignatureStatus;
use App\Models\ParticipantSignature;
use App\Models\PendingExternalSignature;
use App\Services\Pdf\PdfToolClient;
use App\Services\Signing\Certificates\EnvelopeSigningLock;
use App\Services\Signing\Certificates\IncrementalChain;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

require_once __DIR__.'/Support/ExternalSigningHelpers.php';

/*
|--------------------------------------------------------------------------
| Participação paralela ≠ escrita paralela (roadmap §2.12; viabilidade R10)
|--------------------------------------------------------------------------
| A revisão reservada para uma assinatura externa não pode ganhar uma irmã: lock do envelope
| (o mesmo do A1), no máximo uma reserva ativa por documento (índice único) e gravação
| compare-and-set. Uma espera a outra.
*/

beforeEach(fn () => externalBoot($this));
afterEach(fn () => externalTeardown($this));

it('corrida de duas preparações no mesmo envelope não gera revisões irmãs: uma espera a outra', function () {
    externalTokenBridge();
    $scenario = externalScenario($this->work);
    $document = $scenario['document'];
    $maria = externalSigner($this->work.'/token-maria', 'Maria Alves Souza');
    $joao = externalSigner($this->work.'/token-joao', 'Joao Lima');

    externalIntent($this, $scenario, 'maria@exemplo.test')->assertCreated();
    externalIntent($this, $scenario, 'joao@exemplo.test')->assertCreated();
    finalizationRun($scenario['envelope']);

    $first = externalTokenPrepare($this, $scenario, 'maria@exemplo.test', $maria)->assertCreated()->json('pending');
    $second = externalTokenPrepare($this, $scenario, 'joao@exemplo.test', $joao)->assertStatus(409)->assertJsonPath('code', 'document_reserved');

    expect($second->json('retry_after'))->not->toBeNull()
        ->and(PendingExternalSignature::withoutOrganizationScope()->count())->toBe(1)
        ->and(externalShow($this, $scenario, 'joao@exemplo.test')->json('documents.0.status'))->toBe('busy');

    externalSubmit($this, $scenario, 'maria@exemplo.test', [
        'pending_id' => $first['id'], 'mode' => 'raw', 'signature' => externalSignRaw($maria, $first['digest']),
        'certificate' => $maria['certificate'], 'chain' => $maria['chain'],
    ])->assertOk()->assertJsonPath('stage', 'applied');

    // Liberada a reserva, o João prepara SOBRE a revisão da Maria.
    $second = externalTokenPrepare($this, $scenario, 'joao@exemplo.test', $joao)->assertCreated()->json('pending');
    $revisions = participantA1Versions($document, DocumentVersionKind::SignedIncremental);
    expect(PendingExternalSignature::withoutOrganizationScope()->where('ulid', $second['id'])->value('base_document_version_id'))->toBe($revisions[0]->getKey());

    externalSubmit($this, $scenario, 'joao@exemplo.test', [
        'pending_id' => $second['id'], 'mode' => 'raw', 'signature' => externalSignRaw($joao, $second['digest']),
        'certificate' => $joao['certificate'], 'chain' => $joao['chain'],
    ])->assertOk()->assertJsonPath('stage', 'applied');

    [$base] = participantA1Versions($document, DocumentVersionKind::PreSignature);
    $revisions = participantA1Versions($document, DocumentVersionKind::SignedIncremental);
    $signatures = ParticipantSignature::withoutOrganizationScope()->orderBy('revision_index')->get();

    expect($revisions)->toHaveCount(2)
        ->and($signatures->pluck('revision_index')->all())->toBe([1, 2])
        ->and($signatures[0]->base_document_version_id)->toBe($base->getKey())
        ->and($signatures[1]->base_document_version_id)->toBe($revisions[0]->getKey())
        ->and($signatures[1]->signed_document_version_id)->toBe($revisions[1]->getKey());

    $envelope = $scenario['envelope']->refresh();
    $final = finalizationDownload($envelope->finalVersion, $this->work.DIRECTORY_SEPARATOR.'final.pdf');
    $validation = app(PdfToolClient::class)->validate($final, [$maria['ca_pem'], $joao['ca_pem'], $this->operator['pem']]);

    expect($envelope->status)->toBe(EnvelopeStatus::Completed)
        ->and($validation->signatureCount)->toBe(3)
        ->and(IncrementalChain::analyse($validation, 3)['ok'])->toBeTrue()
        ->and(externalLeftovers($this))->toBe([]);
});

it('com o lock do envelope ocupado, preparar e enviar não gravam nada e a reserva não é consumida', function () {
    externalTokenBridge();
    config()->set('assinavelox.external_signing.lock_wait_seconds', 0);
    $scenario = externalScenario($this->work);
    $maria = externalSigner($this->work.'/token', 'Maria Alves Souza');
    $key = EnvelopeSigningLock::key((int) $scenario['envelope']->getKey());

    externalIntent($this, $scenario, 'maria@exemplo.test')->assertCreated();
    finalizationRun($scenario['envelope']);

    $held = Cache::lock($key, 60);
    expect($held->get())->toBeTrue();

    externalTokenPrepare($this, $scenario, 'maria@exemplo.test', $maria)->assertStatus(409)->assertJsonPath('code', 'busy');
    expect(PendingExternalSignature::withoutOrganizationScope()->count())->toBe(0)
        ->and(externalLeftovers($this))->toBe([]);

    $held->release();
    $prepared = externalTokenPrepare($this, $scenario, 'maria@exemplo.test', $maria)->assertCreated()->json('pending');
    $body = [
        'pending_id' => $prepared['id'], 'mode' => 'raw', 'signature' => externalSignRaw($maria, $prepared['digest']),
        'certificate' => $maria['certificate'], 'chain' => $maria['chain'],
    ];

    $held = Cache::lock($key, 60);
    expect($held->get())->toBeTrue();
    externalSubmit($this, $scenario, 'maria@exemplo.test', $body)->assertStatus(409)->assertJsonPath('code', 'busy');
    expect(PendingExternalSignature::withoutOrganizationScope()->firstOrFail()->status)->toBe(PendingExternalSignatureStatus::Pending)
        ->and(participantA1Versions($scenario['document'], DocumentVersionKind::SignedIncremental))->toBe([]);
    $held->release();

    externalSubmit($this, $scenario, 'maria@exemplo.test', $body)->assertOk()->assertJsonPath('stage', 'applied');
});

it('o banco recusa duas reservas ativas no mesmo documento', function () {
    $scenario = externalScenario($this->work);

    externalIntent($this, $scenario, 'maria@exemplo.test')->assertCreated();
    finalizationRun($scenario['envelope']);
    externalSimulatedPrepare($this, $scenario, 'maria@exemplo.test');

    $first = PendingExternalSignature::withoutOrganizationScope()->firstOrFail();

    expect(function () use ($first): void {
        $twin = $first->replicate();
        $twin->forceFill(['ulid' => (string) Str::ulid(), 'recipient_id' => $first->recipient_id])->save();
    })->toThrow(QueryException::class);
});
