<?php

use App\Enums\AuditEventType;
use App\Enums\DocumentVersionKind;
use App\Enums\EnvelopeStatus;
use App\Enums\ParticipantSignatureRequestStatus;
use App\Enums\RecipientStatus;
use App\Enums\SignatureStatus;
use App\Models\AuditEvent;
use App\Models\DocumentVersion;
use App\Models\ParticipantSignature;
use App\Models\ParticipantSignatureRequest;
use App\Models\VerificationRecord;
use App\Services\Pdf\PdfToolClient;
use App\Services\Signing\Certificates\IncrementalChain;
use App\Services\Signing\Certificates\ParticipantCertificateConsent;
use App\Services\Signing\Certificates\ParticipantSignatureApplier;
use App\Services\Verification\SignatureNarrative;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/Support/ParticipantA1Helpers.php';

/*
|--------------------------------------------------------------------------
| Assinatura com o certificado A1 do PRÓPRIO participante — pipeline completo (K-A1)
|--------------------------------------------------------------------------
| Ordem: consolidação → evidências → base congelada → participantes, um por vez → operadora
| por último → hash final depois da última assinatura. pdftool REAL, certificados de TESTE.
*/

describe('com o certificado da operadora', function () {
    beforeEach(fn () => participantA1Boot($this));
    afterEach(fn () => participantA1Teardown($this));

    it('dois participantes com o próprio A1 e a operadora por último: três assinaturas íntegras em revisões encadeadas', function () {
        $scenario = participantA1Scenario($this->work);
        $envelope = $scenario['envelope'];
        $document = $scenario['document'];
        $recipients = $scenario['recipients'];
        $maria = participantA1Certificate($this->work.'/certs', 'Maria Alves Souza', 'senha-da-Maria-1!', '52998224725');
        $joao = participantA1Certificate($this->work.'/certs', 'Joao Lima', 'senha-do-Joao-2!');

        // Os dois optam — a escolha, sem PFX nenhum.
        participantA1Intent($this, $scenario, 'maria@exemplo.test')->assertCreated()->assertJsonPath('request.status', 'requested');
        participantA1Intent($this, $scenario, 'joao@exemplo.test')->assertCreated();

        // A finalização congela a base (consolidado + evidências) e ESPERA pelos dois.
        finalizationRun($envelope);

        expect($envelope->refresh()->status)->toBe(EnvelopeStatus::Finalizing)
            ->and(participantA1Versions($document, DocumentVersionKind::PreSignature))->toHaveCount(1)
            ->and(participantA1Versions($document, DocumentVersionKind::Final))->toBe([])
            ->and(AuditEvent::withoutOrganizationScope()->where('event_type', AuditEventType::EnvelopeAwaitingParticipantSignatures->value)->count())->toBe(1);

        participantA1Show($this, $scenario, 'maria@exemplo.test')->assertOk()
            ->assertJsonPath('stage', 'ready_to_upload')
            ->assertJsonPath('can_upload', true)
            ->assertJsonPath('consent.version', ParticipantCertificateConsent::VERSION)
            ->assertJsonPath('consent.legal_review_required', true);

        // Prévia → consentimento → envio (Maria).
        $preview = participantA1Preview($this, $scenario, 'maria@exemplo.test', $maria)->assertOk()->json();
        expect($preview['certificate']['fingerprint_sha256'])->toBe($maria['fingerprint'])
            ->and($preview['holder_match']['cpf'])->toBe('unknown')
            ->and($preview['holder_match']['name'])->toBe('match');

        participantA1Submit($this, $scenario, 'maria@exemplo.test', $maria, ['fingerprint' => $preview['certificate']['fingerprint_sha256']])
            ->assertStatus(202)
            ->assertJsonPath('stage', 'applied')
            ->assertJsonPath('request.documents_signed', 1);

        // Ainda falta o João: o envelope continua esperando.
        expect($envelope->refresh()->status)->toBe(EnvelopeStatus::Finalizing)
            ->and(participantA1Versions($document, DocumentVersionKind::SignedIncremental))->toHaveCount(1);

        participantA1Submit($this, $scenario, 'joao@exemplo.test', $joao)->assertStatus(202)->assertJsonPath('stage', 'applied');

        // O João foi o último: a operadora assina por último e o envelope conclui.
        $envelope->refresh();
        $record = VerificationRecord::query()->where('envelope_id', $envelope->getKey())->firstOrFail();

        expect($envelope->status)->toBe(EnvelopeStatus::Completed)
            ->and($record->signature_status)->toBe(SignatureStatus::Mixed)
            ->and($record->signature_profile)->toBe('PAdES-B-B')
            ->and($record->certificate_reference_id)->not->toBeNull()
            ->and($record->validation_result['signed'])->toBeTrue()
            ->and($record->validation_result['timestamp'])->toBeNull()
            ->and($record->validation_result['long_term_validation'])->toBeFalse()
            ->and($record->validation_result['incremental_chain']['ok'])->toBeTrue()
            ->and($record->validation_result['result']['signature_count'])->toBe(3);

        // Arquivo final: três assinaturas íntegras, válidas e — com as raízes de TESTE — confiáveis.
        $final = finalizationDownload($envelope->finalVersion, $this->work.DIRECTORY_SEPARATOR.'final.pdf');
        $validation = app(PdfToolClient::class)->validate($final, [$maria['ca'], $joao['ca'], $this->operator['pem']]);

        expect($record->final_sha256)->toBe(hash_file('sha256', $final))
            ->and($validation->signatureCount)->toBe(3)
            ->and($validation->allIntact)->toBeTrue()
            ->and($validation->allValid)->toBeTrue()
            ->and($validation->allTrusted())->toBeTrue()
            ->and(array_map(fn ($signature) => $signature->fieldName, $validation->signatures))->toBe([
                ParticipantSignatureApplier::fieldName($recipients['maria@exemplo.test']),
                ParticipantSignatureApplier::fieldName($recipients['joao@exemplo.test']),
                'AssinaVelox',
            ])
            ->and($validation->signatures[0]->coverage)->toBe('ENTIRE_REVISION')
            ->and($validation->signatures[2]->coverage)->toBe('ENTIRE_FILE')
            ->and(IncrementalChain::analyse($validation, 3)['ok'])->toBeTrue();

        // Revisões encadeadas byte a byte: base ⊂ revisão 1 ⊂ revisão 2 ⊂ final.
        [$base] = participantA1Versions($document, DocumentVersionKind::PreSignature);
        $revisions = participantA1Versions($document, DocumentVersionKind::SignedIncremental);
        $bytes = static fn (DocumentVersion $version): string => (string) Storage::disk('documents')->get($version->storage_path);

        expect($revisions)->toHaveCount(2)
            ->and(str_starts_with($bytes($revisions[0]), $bytes($base)))->toBeTrue()
            ->and(str_starts_with($bytes($revisions[1]), $bytes($revisions[0])))->toBeTrue()
            ->and(str_starts_with((string) file_get_contents($final), $bytes($revisions[1])))->toBeTrue();

        $signatures = ParticipantSignature::withoutOrganizationScope()->orderBy('revision_index')->get();

        expect($signatures->pluck('revision_index')->all())->toBe([1, 2])
            ->and($signatures[0]->base_document_version_id)->toBe($base->getKey())
            ->and($signatures[1]->base_document_version_id)->toBe($revisions[0]->getKey())
            ->and($signatures[0]->fingerprint_sha256)->toBe($maria['fingerprint'])
            ->and($signatures[0]->validation_result['chain']['ok'])->toBeTrue()
            ->and(participantA1Leftovers($this))->toBe([]);

        // Verificação pública: rótulo próprio por assinatura, nome mascarado, sem CPF.
        $props = $this->get(route('verify.show', ['code' => $envelope->verification_code]))->viewData('page')['props'];
        $result = $props['result'];

        expect($result['signature_status'])->toBe('mixed')
            ->and($result['status_label'])->toBe('Concluído · assinado com certificados dos participantes e da operadora')
            ->and($result['signature_statement'])->toContain('próprio participante')->toContain('operadora')->toContain('TESTE')
            ->and($result['validation']['integrity'])->toBe('intact')
            ->and($result['participant_signatures'])->toHaveCount(2)
            ->and($result['participant_signatures'][0]['label'])->toContain('Assinado com certificado A1 de')->toContain('não é ICP-Brasil')
            ->and($result['participant_signatures'][0]['holder_name_masked'])->not->toContain('Alves')
            ->and($result['participant_signatures'][0]['is_test'])->toBeTrue()
            ->and($result['participant_signatures'][0]['integrity'])->toBe('intact')
            ->and(array_keys($result['participant_signatures'][0]))->not->toContain('holder_cpf_masked')
            ->and(json_encode($props))->not->toContain('52998224725')->not->toContain('982.247')->not->toContain('maria@exemplo.test');

        // Página de evidências (autenticada): titular, CPF mascarado, emissor, série e resultado por documento.
        actingAsMember($scenario['owner'], $scenario['organization']);
        $evidence = $this->get(route('envelopes.evidence', $envelope))->assertOk()->viewData('page')['props'];
        $first = $evidence['participant_signatures'][0];

        expect($evidence['signature_status'])->toBe('mixed')
            ->and($first['kind'])->toBe('participant_a1')
            ->and($first['status'])->toBe('applied')
            ->and($first['label'])->toContain('Maria Alves Souza')
            ->and($first['certificate']['holder_cpf_masked'])->toBe('***.982.247-**')
            ->and($first['certificate']['is_test'])->toBeTrue()
            ->and($first['certificate']['serial'])->toBe($maria['raw']['serial_hex'])
            ->and($first['consent']['version'])->toBe(ParticipantCertificateConsent::VERSION)
            ->and($first['documents'][0]['integrity'])->toBe('intact')
            ->and($first['documents'][0]['revision_index'])->toBe(1)
            ->and(json_encode($evidence))->not->toContain('52998224725');
    });

    it('pedido que não chega no prazo vence e o envelope conclui sem ele — o aceite continua valendo', function () {
        config()->set('assinavelox.participant_a1.application_window_minutes', 5);

        $scenario = participantA1Scenario($this->work);
        $envelope = $scenario['envelope'];

        participantA1Intent($this, $scenario, 'maria@exemplo.test')->assertCreated();
        finalizationRun($envelope);

        $request = ParticipantSignatureRequest::withoutOrganizationScope()->firstOrFail();
        expect($envelope->refresh()->status)->toBe(EnvelopeStatus::Finalizing)
            ->and($request->window_expires_at)->not->toBeNull();

        Carbon::setTestNow(now()->addMinutes(6));

        try {
            finalizationRun($envelope);
        } finally {
            Carbon::setTestNow();
        }

        $envelope->refresh();
        $record = VerificationRecord::query()->where('envelope_id', $envelope->getKey())->firstOrFail();

        expect($envelope->status)->toBe(EnvelopeStatus::Completed)
            ->and($record->signature_status)->toBe(SignatureStatus::CompanyA1)
            ->and($request->fresh()->status)->toBe(ParticipantSignatureRequestStatus::Expired)
            ->and($request->fresh()->failure_code)->toBe('window_expired')
            ->and(AuditEvent::withoutOrganizationScope()->where('event_type', AuditEventType::ParticipantSignatureExpired->value)->exists())->toBeTrue()
            ->and($scenario['recipients']['maria@exemplo.test']->fresh()->status)->toBe(RecipientStatus::Signed);
    });

    it('com a flag desligada nada muda: rotas 404 e a finalização é a da Fase 1', function () {
        $scenario = participantA1Scenario($this->work);
        config()->set('assinavelox.participant_a1.enabled', false);

        participantA1Show($this, $scenario, 'maria@exemplo.test')->assertNotFound();
        participantA1Intent($this, $scenario, 'maria@exemplo.test')->assertNotFound();

        // Interruptor global ligado, mas o plano não inclui o item: também 404.
        participantA1Enable($scenario['organization'], false);
        participantA1Intent($this, $scenario, 'maria@exemplo.test')->assertNotFound();

        finalizationRun($scenario['envelope']);

        $envelope = $scenario['envelope']->refresh();
        $record = VerificationRecord::query()->where('envelope_id', $envelope->getKey())->firstOrFail();
        $evidenceEvent = AuditEvent::withoutOrganizationScope()->where('event_type', AuditEventType::EnvelopeEvidenceGenerated->value)->firstOrFail();

        expect($envelope->status)->toBe(EnvelopeStatus::Completed)
            ->and($record->signature_status)->toBe(SignatureStatus::CompanyA1)
            ->and($record->validation_result)->not->toHaveKey('incremental_chain')
            ->and($evidenceEvent->payload)->not->toHaveKey('participant_mode')
            ->and(participantA1Versions($scenario['document'], DocumentVersionKind::PreSignature))->toBe([])
            ->and(ParticipantSignatureRequest::withoutOrganizationScope()->count())->toBe(0);

        $props = $this->get(route('verify.show', ['code' => $envelope->verification_code]))->viewData('page')['props'];

        expect($props['result'])->not->toHaveKey('participant_signatures');
    });
});

describe('sem o certificado da operadora', function () {
    beforeEach(fn () => participantA1Boot($this, false));
    afterEach(fn () => participantA1Teardown($this));

    it('conclui só com a assinatura do participante (participants_a1) e diz que a operadora não assinou', function () {
        $scenario = participantA1Scenario($this->work);
        $envelope = $scenario['envelope'];
        $maria = participantA1Certificate($this->work.'/certs', 'Maria Alves Souza', 'senha-da-Maria-6!');

        participantA1Intent($this, $scenario, 'maria@exemplo.test')->assertCreated();
        finalizationRun($envelope);
        participantA1Submit($this, $scenario, 'maria@exemplo.test', $maria)->assertStatus(202)->assertJsonPath('stage', 'applied');

        $envelope->refresh();
        $record = VerificationRecord::query()->where('envelope_id', $envelope->getKey())->firstOrFail();
        [$revision] = participantA1Versions($scenario['document'], DocumentVersionKind::SignedIncremental);
        $final = finalizationDownload($envelope->finalVersion, $this->work.DIRECTORY_SEPARATOR.'final.pdf');
        $validation = app(PdfToolClient::class)->validate($final, [$maria['ca']]);
        $narrative = SignatureNarrative::for($envelope, $record);

        expect($envelope->status)->toBe(EnvelopeStatus::Completed)
            ->and($record->signature_status)->toBe(SignatureStatus::ParticipantsA1)
            ->and($record->signature_profile)->toBe('PAdES-B-B')
            ->and($record->certificate_reference_id)->toBeNull()
            // Sem a operadora, o arquivo final É a revisão assinada pelo participante; o hash é o dela.
            ->and($record->final_sha256)->toBe($revision->sha256)
            ->and(hash_file('sha256', $final))->toBe($revision->sha256)
            ->and($validation->signatureCount)->toBe(1)
            ->and($validation->allTrusted())->toBeTrue()
            ->and($narrative['state'])->toBe('participants_a1')
            ->and($narrative['statement'])->toContain('A operadora não aplicou assinatura própria')
            ->and($narrative['certificate'])->toBeNull()
            ->and($narrative['validation']['integrity'])->toBe('intact')
            ->and(SignatureNarrative::statusLabel($envelope, $record))->toBe('Concluído · assinado com certificado dos participantes')
            ->and(SignatureNarrative::completedLabel($record))->toBe('Assinado');
    });
});
