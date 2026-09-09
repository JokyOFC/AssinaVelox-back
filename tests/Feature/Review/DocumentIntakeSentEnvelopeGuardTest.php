<?php

/*
|--------------------------------------------------------------------------
| Revisão adversarial final — integridade de domínio
|--------------------------------------------------------------------------
|
| DEFEITO: `App\Services\Documents\DocumentIntake::remove()` NÃO verifica o status do
| envelope. `store()` verifica (`acceptsUpload()`, linha 64), mas `remove()` (linha 225)
| vai direto para `removeDocument()`.
|
| A única barreira real é `EnvelopeDocumentController::destroy()`, que consulta
| `acceptsUpload()` sobre o model do route binding — fora de transação e sem
| `lockForUpdate`. É EXATAMENTE o buraco que `App\Services\Envelopes\PreparationGuard`
| foi criado para fechar em `FieldSync` e `RecipientSync`
| (ver tests/Feature/Review/PreparationServicesStateGuardTest.php); o intake documental
| ficou de fora — `grep -rn PreparationGuard app/` só encontra FieldSync e RecipientSync.
|
| O estrago de `purgeDocumentRecords()` (DocumentIntake.php:283) é maior que o dos syncs:
|
|   SigningField::query()->where('envelope_id', ...)->delete();
|   $envelope->forceFill(['sent_document_version_id' => null,
|                         'final_document_version_id' => null]);   // sem olhar o status
|   $document->delete();                                           // cascata
|
| e as cascatas do esquema (2026_09_08_100006 / 100013) fazem o resto:
|   documents ──cascade──▶ document_versions ──cascade──▶ signature_acceptances
|                                            └──cascade──▶ signing_fields
| seguido de `purgeVersionFiles()`, que apaga os BYTES do disco.
|
| Ou seja: uma chamada ao serviço sobre um envelope `in_progress` ou `completed` destrói
| os aceites eletrônicos já registrados, a versão congelada apresentada aos signatários e
| o PDF final assinado — a prova inteira, banco e disco, sem nenhuma recusa.
|
| Correção esperada: `remove()` (e a substituição em `store()`) recarregando o envelope
| sob `lockForUpdate()` dentro da transação e recusando fora de `draft|preparing|ready`,
| como `PreparationGuard::lockForPreparation()` já faz.
*/

use App\Enums\EnvelopeStatus;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\SignatureAcceptance;
use App\Models\SigningField;
use App\Services\Documents\DocumentIntake;
use App\Services\Envelopes\Sending\SendEnvelope;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/../Sending/Support/SendingHelpers.php';

beforeEach(function (): void {
    $this->withoutVite();
    fakeEmailProvider();
});

it('recusa remover o documento de um envelope já enviado, em vez de destruir a versão congelada e o aceite', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = readyEnvelope($organization, $owner);

    actingAsMember($owner, $organization);
    app(SendEnvelope::class)->handle($envelope);
    $envelope = $envelope->fresh();

    expect($envelope->status)->toBe(EnvelopeStatus::InProgress)
        ->and($envelope->sent_document_version_id)->not->toBeNull();

    $sentVersionId = (int) $envelope->sent_document_version_id;
    $recipient = $envelope->recipients()->firstOrFail();

    $acceptance = SignatureAcceptance::query()->create([
        'recipient_id' => $recipient->id,
        'envelope_id' => $envelope->id,
        'document_version_id' => $sentVersionId,
        'organization_id' => $organization->id,
        'accepted_at' => now(),
        'auth_method' => 'email_otp',
        'consent_statement' => 'Declaração de aceite.',
        'document_sha256' => str_repeat('a', 64),
    ]);

    // O serviço é o contrato público do passo 1 do wizard; ele precisa recusar aqui.
    $threw = false;

    try {
        app(DocumentIntake::class)->remove($envelope, $owner);
    } catch (Throwable) {
        $threw = true;
    }

    expect($threw)->toBeTrue('DocumentIntake::remove() aceitou apagar o documento de um envelope in_progress');

    // E, sobretudo: nada da prova pode ter sumido.
    expect(SignatureAcceptance::withoutOrganizationScope()->whereKey($acceptance->id)->exists())->toBeTrue()
        ->and(DocumentVersion::withoutOrganizationScope()->whereKey($sentVersionId)->exists())->toBeTrue()
        ->and(Document::withoutOrganizationScope()->where('envelope_id', $envelope->id)->exists())->toBeTrue()
        ->and(SigningField::withoutOrganizationScope()->where('envelope_id', $envelope->id)->count())->toBe(1)
        ->and((int) $envelope->fresh()->sent_document_version_id)->toBe($sentVersionId);
});

it('recusa remover o documento de um envelope concluído, em vez de apagar o arquivo final e os aceites', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = readyEnvelope($organization, $owner);

    actingAsMember($owner, $organization);
    app(SendEnvelope::class)->handle($envelope);
    $envelope = $envelope->fresh();

    $sentVersionId = (int) $envelope->sent_document_version_id;
    $recipient = $envelope->recipients()->firstOrFail();

    SignatureAcceptance::query()->create([
        'recipient_id' => $recipient->id,
        'envelope_id' => $envelope->id,
        'document_version_id' => $sentVersionId,
        'organization_id' => $organization->id,
        'accepted_at' => now(),
        'auth_method' => 'email_otp',
        'consent_statement' => 'Declaração de aceite.',
        'document_sha256' => str_repeat('b', 64),
    ]);

    // Envelope concluído, com uma versão `final` apontada — o arquivo que a página pública
    // de verificação publica e que o download entrega.
    $document = $envelope->document()->firstOrFail();
    $final = DocumentVersion::factory()->forDocument($document)->create(['kind' => 'final']);

    $envelope->forceFill([
        'status' => EnvelopeStatus::Completed,
        'completed_at' => now(),
        'final_document_version_id' => $final->id,
    ])->save();

    $envelope = $envelope->fresh();

    $threw = false;

    try {
        app(DocumentIntake::class)->remove($envelope, $owner);
    } catch (Throwable) {
        $threw = true;
    }

    expect($threw)->toBeTrue('DocumentIntake::remove() aceitou apagar o documento de um envelope completed');

    expect(DocumentVersion::withoutOrganizationScope()->whereKey($final->id)->exists())->toBeTrue()
        ->and(DocumentVersion::withoutOrganizationScope()->whereKey($sentVersionId)->exists())->toBeTrue()
        ->and(SignatureAcceptance::withoutOrganizationScope()->where('envelope_id', $envelope->id)->count())->toBe(1)
        ->and((int) $envelope->fresh()->final_document_version_id)->toBe($final->id);
});
