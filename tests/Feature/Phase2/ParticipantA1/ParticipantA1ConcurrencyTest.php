<?php

use App\Enums\DocumentVersionKind;
use App\Enums\EnvelopeStatus;
use App\Enums\ParticipantSignatureRequestStatus;
use App\Enums\SignatureStatus;
use App\Jobs\Envelopes\ApplyParticipantSignature;
use App\Models\ParticipantSignature;
use App\Models\ParticipantSignatureRequest;
use App\Models\VerificationRecord;
use App\Services\Pdf\PdfToolClient;
use App\Services\Signing\Certificates\EnvelopeSigningLock;
use App\Services\Signing\Certificates\Exceptions\StaleRevisionException;
use App\Services\Signing\Certificates\IncrementalChain;
use App\Services\Signing\Certificates\IncrementalRevisions;
use App\Services\Signing\Certificates\ParticipantSignatureApplier;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/Support/ParticipantA1Helpers.php';

/*
|--------------------------------------------------------------------------
| Participação paralela ≠ escrita paralela (roadmap §2.12)
|--------------------------------------------------------------------------
| Dois participantes enviam o certificado "ao mesmo tempo". O aceite é paralelo; a gravação
| criptográfica é serial: um gravador por envelope (lock), e o banco recusa duas assinaturas
| sobre a mesma revisão. Nenhuma revisão irmã é gravada — uma espera a outra.
*/

beforeEach(fn () => participantA1Boot($this));
afterEach(fn () => participantA1Teardown($this));

it('duas aplicações simultâneas no mesmo envelope não gravam revisões irmãs: uma espera a outra', function () {
    // Os jobs ficam na fila (worker simulado abaixo), como numa fila real com dois workers.
    Queue::fake([ApplyParticipantSignature::class]);

    $scenario = participantA1Scenario($this->work);
    $envelope = $scenario['envelope'];
    $document = $scenario['document'];
    $maria = participantA1Certificate($this->work.'/certs', 'Maria Alves Souza', 'senha-da-Maria-Concorrente-1!');
    $joao = participantA1Certificate($this->work.'/certs', 'Joao Lima', 'senha-do-Joao-Concorrente-2!');

    participantA1Intent($this, $scenario, 'maria@exemplo.test')->assertCreated();
    participantA1Intent($this, $scenario, 'joao@exemplo.test')->assertCreated();
    finalizationRun($envelope);

    participantA1Submit($this, $scenario, 'maria@exemplo.test', $maria)->assertStatus(202)->assertJsonPath('stage', 'queued');
    participantA1Submit($this, $scenario, 'joao@exemplo.test', $joao)->assertStatus(202)->assertJsonPath('stage', 'queued');

    Queue::assertPushed(ApplyParticipantSignature::class, 2);

    $requests = ParticipantSignatureRequest::withoutOrganizationScope()->orderBy('id')->get();
    expect($requests)->toHaveCount(2)
        ->and($requests->pluck('status')->all())->toBe([ParticipantSignatureRequestStatus::Queued, ParticipantSignatureRequestStatus::Queued]);

    // Um worker segura o lock do envelope: o outro NÃO grava nada — espera (aqui, sem espera: falha de lock).
    $held = Cache::lock(EnvelopeSigningLock::key((int) $envelope->getKey()), 60);
    expect($held->get())->toBeTrue();

    $applier = app(ParticipantSignatureApplier::class);

    expect(fn () => $applier->apply((int) $requests[0]->getKey(), null, 0))->toThrow(LockTimeoutException::class)
        ->and(participantA1Versions($document, DocumentVersionKind::SignedIncremental))->toBe([])
        ->and($requests[0]->fresh()->status)->toBe(ParticipantSignatureRequestStatus::Queued)
        ->and(glob($this->work.'/selado/*.sealed'))->toHaveCount(2);

    $held->release();

    // Liberado o lock, cada um grava na sua vez, e o segundo parte da revisão do primeiro.
    expect($applier->apply((int) $requests[0]->getKey()))->toBe(ParticipantSignatureApplier::APPLIED)
        ->and($applier->apply((int) $requests[1]->getKey()))->toBe(ParticipantSignatureApplier::APPLIED);

    $revisions = participantA1Versions($document, DocumentVersionKind::SignedIncremental);
    [$base] = participantA1Versions($document, DocumentVersionKind::PreSignature);
    $signatures = ParticipantSignature::withoutOrganizationScope()->orderBy('revision_index')->get();

    expect($revisions)->toHaveCount(2)
        ->and($signatures)->toHaveCount(2)
        ->and($signatures->pluck('revision_index')->all())->toBe([1, 2])
        ->and($signatures[0]->base_document_version_id)->toBe($base->getKey())
        ->and($signatures[1]->base_document_version_id)->toBe($revisions[0]->getKey())
        ->and($signatures[1]->signed_document_version_id)->toBe($revisions[1]->getKey());

    // Compare-and-set: uma saída calculada sobre a base ANTIGA seria irmã da revisão 1 — não entra.
    $stale = $this->work.DIRECTORY_SEPARATOR.'saida-sobre-base-antiga.pdf';
    file_put_contents($stale, finalizationDownload($revisions[0], $this->work.DIRECTORY_SEPARATOR.'r1.pdf') ? file_get_contents($this->work.DIRECTORY_SEPARATOR.'r1.pdf') : '');

    expect(fn () => app(IncrementalRevisions::class)->storeSigned($envelope, $document, $base, $stale, function (): never {
        throw new RuntimeException('Nunca deveria chegar a gravar a linha.');
    }))->toThrow(StaleRevisionException::class)
        ->and(participantA1Versions($document, DocumentVersionKind::SignedIncremental))->toHaveCount(2);

    // E o banco recusa duas assinaturas sobre a mesma revisão-base.
    expect(function () use ($signatures, $revisions): void {
        $duplicate = new ParticipantSignature;
        $duplicate->forceFill([
            'organization_id' => $signatures[0]->organization_id,
            'envelope_id' => $signatures[0]->envelope_id,
            'participant_signature_request_id' => $signatures[1]->participant_signature_request_id,
            'recipient_id' => $signatures[1]->recipient_id,
            'document_id' => $signatures[0]->document_id,
            'base_document_version_id' => $signatures[0]->base_document_version_id,
            'signed_document_version_id' => $revisions[1]->getKey(),
            'revision_index' => 99,
            'field_name' => 'IRMA',
            'signed_at' => now(),
        ])->save();
    })->toThrow(QueryException::class);

    // A finalização segue sobre a revisão 2 e a operadora assina por último.
    finalizationRun($envelope);

    $envelope->refresh();
    $record = VerificationRecord::query()->where('envelope_id', $envelope->getKey())->firstOrFail();
    $final = finalizationDownload($envelope->finalVersion, $this->work.DIRECTORY_SEPARATOR.'final.pdf');
    $validation = app(PdfToolClient::class)->validate($final, [$maria['ca'], $joao['ca'], $this->operator['pem']]);

    expect($envelope->status)->toBe(EnvelopeStatus::Completed)
        ->and($record->signature_status)->toBe(SignatureStatus::Mixed)
        ->and($validation->signatureCount)->toBe(3)
        ->and(IncrementalChain::analyse($validation)['ok'])->toBeTrue()
        ->and(participantA1Leftovers($this))->toBe([]);
});

it('o job carrega só identificadores: nem senha nem bytes do PFX na fila', function () {
    Queue::fake([ApplyParticipantSignature::class]);

    $scenario = participantA1Scenario($this->work);
    $maria = participantA1Certificate($this->work.'/certs', 'Maria Alves Souza', 'Senha-Na-Fila-Nunca-4412!');
    $bytes = (string) file_get_contents($maria['pfx']);
    $fragment = substr($bytes, 64, 48);

    participantA1Submit($this, $scenario, 'maria@exemplo.test', $maria)->assertStatus(202);

    Queue::assertPushed(ApplyParticipantSignature::class, function (ApplyParticipantSignature $job) use ($maria, $fragment): bool {
        $payload = serialize($job);

        return ! str_contains($payload, $maria['password'])
            && ! str_contains($payload, $fragment)
            && ! str_contains($payload, base64_encode($fragment))
            && ! str_contains($payload, bin2hex($fragment));
    });
});
