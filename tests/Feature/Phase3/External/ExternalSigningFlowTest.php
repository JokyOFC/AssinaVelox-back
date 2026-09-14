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
use App\Services\Signing\External\ExternalSignatureService;
use App\Services\Verification\SignatureNarrative;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/Support/ExternalSigningHelpers.php';

/*
|--------------------------------------------------------------------------
| Assinatura externa por componente local (Fase 3 §3.4) — fluxo completo
|--------------------------------------------------------------------------
| consolidação → evidências → base congelada → preparar (revisão pendente + digest) →
| assinar FORA do servidor → incorporar e validar → operadora por último. pdftool REAL.
*/

describe('com o certificado da operadora', function () {
    beforeEach(fn () => externalBoot($this));
    afterEach(fn () => externalTeardown($this));

    it('fluxo completo com o simulador gera assinatura válida, em revisão incremental, e rotulada como simulada', function () {
        $scenario = externalScenario($this->work);
        $envelope = $scenario['envelope'];
        $document = $scenario['document'];
        $maria = 'maria@exemplo.test';

        // Todos já aceitaram (envelope em finalizing), mas a base congelada ainda não existe.
        externalIntent($this, $scenario, $maria)->assertCreated()
            ->assertJsonPath('method', 'local_component')
            ->assertJsonPath('request.status', 'requested')
            ->assertJsonPath('stage', 'ready_to_sign')
            ->assertJsonPath('documents.0.status', 'waiting_base');

        // A finalização congela a base e ESPERA pela Maria.
        finalizationRun($envelope);
        expect($envelope->refresh()->status)->toBe(EnvelopeStatus::Finalizing)
            ->and(participantA1Versions($document, DocumentVersionKind::PreSignature))->toHaveCount(1);

        $state = externalShow($this, $scenario, $maria)->assertOk()
            ->assertJsonPath('stage', 'ready_to_sign')
            ->assertJsonPath('can_prepare', true)
            ->json();
        $components = collect($state['components'])->keyBy('component');

        expect($state['documents'][0]['status'])->toBe('to_sign')
            ->and($components['simulated']['available'])->toBeTrue()
            ->and($components['simulated']['simulated'])->toBeTrue()
            ->and($components['nexu']['available'])->toBeFalse()
            ->and($components['nexu']['reason'])->toBe('production_disabled')
            ->and($state['local_component']['production_enabled'])->toBeFalse()
            ->and($state['local_component']['missing_for_production'])->not->toBeEmpty()
            ->and($state['chain']['label'])->toContain('não verificada')
            ->and($state['limits']['pending_ttl_minutes'])->toBe(10);

        $prepared = externalSimulatedPrepare($this, $scenario, $maria);

        expect(strlen((string) base64_decode($prepared['digest'], true)))->toBe(32)
            ->and($prepared['hash_function'])->toBe('SHA256')
            ->and($prepared['digest_kind'])->toBe('signed_attributes')
            ->and($prepared['simulated'])->toBeTrue()
            ->and($prepared['certificate']['is_test'])->toBeTrue()
            ->and($prepared['certificate']['kind_label'])->toContain('TESTE')
            ->and($prepared['chain']['trusted'])->toBeFalse()
            ->and($prepared['chain']['revocation'])->toBe('not_checked')
            ->and($prepared['endpoints']['submit'])->toBeNull();

        $pending = PendingExternalSignature::withoutOrganizationScope()->firstOrFail();
        expect($pending->status)->toBe(PendingExternalSignatureStatus::Pending)
            ->and($pending->reservation_key)->toBe($document->getKey())
            ->and($pending->is_simulated)->toBeTrue()
            ->and((int) round(now()->diffInMinutes($pending->expires_at)))->toBe(10);

        // O estado da tela não repete o digest (só a resposta da preparação o traz).
        $again = externalShow($this, $scenario, $maria)->assertOk()->json();
        expect($again['documents'][0]['status'])->toBe('reserved')
            ->and($again['documents'][0]['pending']['digest'])->toBeNull();

        externalSimulatorSign($this, $scenario, $maria, $prepared['id'])->assertOk()
            ->assertJsonPath('stage', 'applied')
            ->assertJsonPath('request.signature_status', 'participant_external')
            ->assertJsonPath('request.documents_signed', 1);

        // O João não optou: com a Maria aplicada, a finalização conclui (operadora por último).
        $envelope->refresh();
        $record = VerificationRecord::query()->where('envelope_id', $envelope->getKey())->firstOrFail();

        expect($envelope->status)->toBe(EnvelopeStatus::Completed)
            ->and($record->signature_status)->toBe(SignatureStatus::ParticipantExternal)
            ->and($record->signature_profile)->toBe('PAdES-B-B')
            ->and($record->validation_result['timestamp'])->toBeNull()
            ->and($record->validation_result['incremental_chain']['ok'])->toBeTrue()
            ->and($record->validation_result['result']['signature_count'])->toBe(2);

        $signature = ParticipantSignature::withoutOrganizationScope()->firstOrFail();
        expect($signature->getAttribute('signature_status'))->toBe('participant_external')
            ->and((bool) $signature->getAttribute('is_simulated'))->toBeTrue()
            ->and($signature->getAttribute('signing_component'))->toBe('simulated')
            ->and($signature->fingerprint_sha256)->toBe($this->simulator['fingerprint'])
            ->and($signature->field_name)->toBe(ExternalSignatureService::fieldName($scenario['recipients'][$maria]))
            ->and($signature->revision_index)->toBe(1)
            ->and($signature->validation_result['chain']['ok'])->toBeTrue()
            ->and(ParticipantSignatureRequest::withoutOrganizationScope()->firstOrFail()->status)->toBe(ParticipantSignatureRequestStatus::Applied);

        // Arquivo final: duas assinaturas íntegras, válidas e — com as raízes de TESTE — confiáveis.
        $final = finalizationDownload($envelope->finalVersion, $this->work.DIRECTORY_SEPARATOR.'final.pdf');
        $validation = app(PdfToolClient::class)->validate($final, [$this->simulator['ca'], $this->operator['pem']]);

        expect($validation->signatureCount)->toBe(2)
            ->and($validation->allIntact)->toBeTrue()
            ->and($validation->allValid)->toBeTrue()
            ->and($validation->allTrusted())->toBeTrue()
            ->and($validation->signatures[0]->fieldName)->toBe($signature->field_name)
            ->and($validation->signatures[0]->coverage)->toBe('ENTIRE_REVISION')
            ->and($validation->signatures[1]->coverage)->toBe('ENTIRE_FILE')
            ->and(IncrementalChain::analyse($validation, 2)['ok'])->toBeTrue()
            ->and($record->final_sha256)->toBe(hash_file('sha256', $final));

        // Revisões encadeadas byte a byte: base ⊂ revisão assinada ⊂ final.
        [$base] = participantA1Versions($document, DocumentVersionKind::PreSignature);
        [$revision] = participantA1Versions($document, DocumentVersionKind::SignedIncremental);
        $bytes = static fn ($version): string => (string) Storage::disk('documents')->get($version->storage_path);

        expect(str_starts_with($bytes($revision), $bytes($base)))->toBeTrue()
            ->and(str_starts_with((string) file_get_contents($final), $bytes($revision)))->toBeTrue()
            ->and($signature->base_document_version_id)->toBe($base->getKey());

        // A reserva foi consumida e os arquivos temporários sumiram.
        expect($pending->fresh()->status)->toBe(PendingExternalSignatureStatus::Applied)
            ->and($pending->fresh()->reservation_key)->toBeNull()
            ->and($pending->fresh()->signed_document_version_id)->toBe($revision->getKey())
            ->and(externalLeftovers($this))->toBe([]);

        // Verificação pública: rótulo do meio, "simulado", nome mascarado, sem CPF/impressão digital.
        $props = $this->get(route('verify.show', ['code' => $envelope->verification_code]))->viewData('page')['props'];
        $result = $props['result'];

        expect($result['signature_status'])->toBe('participant_external')
            ->and($result['status_label'])->toContain('componente externo')->toContain('simulado — nenhum token foi usado')
            ->and($result['signature_statement'])->toContain('SIMULADOR')->toContain('FORA da plataforma')->toContain('operadora')
            ->and($result['signature_statement'])->not->toContain('certificado digital A1')
            ->and($result['validation']['integrity'])->toBe('intact')
            ->and($result['participant_signatures'])->toHaveCount(1)
            ->and($result['participant_signatures'][0]['kind'])->toBe('participant_external')
            ->and($result['participant_signatures'][0]['simulated'])->toBeTrue()
            ->and($result['participant_signatures'][0]['label'])->toContain('simulado — nenhum token foi usado')->not->toContain('A3')
            ->and($result['participant_signatures'][0]['holder_name_masked'])->not->toContain('Alves')
            ->and(json_encode($props))->not->toContain($this->simulator['fingerprint'])->not->toContain('maria@exemplo.test');

        // Evidências (autenticada): mesma lista, com o kind do meio — nunca "participant_a1".
        actingAsMember($scenario['owner'], $scenario['organization']);
        $evidence = $this->get(route('envelopes.evidence', $envelope))->assertOk()->viewData('page')['props'];

        expect($evidence['signature_status'])->toBe('participant_external')
            ->and($evidence['participant_signatures'])->toHaveCount(1)
            ->and($evidence['participant_signatures'][0]['kind'])->toBe('participant_external')
            ->and($evidence['participant_signatures'][0]['simulated'])->toBeTrue()
            ->and($evidence['participant_signatures'][0]['documents'][0]['integrity'])->toBe('intact')
            ->and($evidence['participant_signatures'][0]['label'])->toContain('por componente externo');
    });

    it('componente real habilitado (dublê de teste) + certificado que declara A3: participant_a3 com assinatura BRUTA enviada pelo navegador', function () {
        externalTokenBridge();
        $scenario = externalScenario($this->work);
        $envelope = $scenario['envelope'];
        $maria = 'maria@exemplo.test';
        $token = externalSigner($this->work.DIRECTORY_SEPARATOR.'token-maria', 'Maria Alves Souza', true);

        externalIntent($this, $scenario, $maria)->assertCreated();
        finalizationRun($envelope);

        $prepared = externalTokenPrepare($this, $scenario, $maria, $token)->assertCreated()->json('pending');

        expect($prepared['simulated'])->toBeFalse()
            ->and($prepared['component'])->toBe('nexu')
            ->and($prepared['certificate']['kind_label'])->toContain('TESTE')
            ->and($prepared['endpoints']['submit'])->not->toBeNull();

        // O "token" assina o digest fora do servidor; o navegador devolve assinatura + certificado.
        externalSubmit($this, $scenario, $maria, [
            'pending_id' => $prepared['id'],
            'mode' => 'raw',
            'signature' => externalSignRaw($token, $prepared['digest']),
            'signature_algorithm' => 'RSA_SHA256',
            'certificate' => $token['certificate'],
            'chain' => $token['chain'],
        ])->assertOk()->assertJsonPath('stage', 'applied')->assertJsonPath('request.signature_status', 'participant_a3');

        $envelope->refresh();
        $record = VerificationRecord::query()->where('envelope_id', $envelope->getKey())->firstOrFail();
        $signature = ParticipantSignature::withoutOrganizationScope()->firstOrFail();

        expect($envelope->status)->toBe(EnvelopeStatus::Completed)
            ->and($record->signature_status)->toBe(SignatureStatus::ParticipantA3)
            ->and($signature->getAttribute('signature_status'))->toBe('participant_a3')
            ->and((bool) $signature->getAttribute('is_simulated'))->toBeFalse()
            ->and(SignatureNarrative::statusLabel($envelope, $record))->toContain('A3')
            ->and(SignatureNarrative::completedLabel($record))->toBe('Assinado');

        $final = finalizationDownload($envelope->finalVersion, $this->work.DIRECTORY_SEPARATOR.'final.pdf');
        $validation = app(PdfToolClient::class)->validate($final, [$token['ca_pem'], $this->operator['pem']]);
        expect($validation->signatureCount)->toBe(2)->and($validation->allTrusted())->toBeTrue()
            ->and(IncrementalChain::analyse($validation, 2)['ok'])->toBeTrue();
    });

    it('modo CMS: um CMS pronto sobre o resumo do documento é aceito; certificado sem A3 declarado nunca vira A3', function () {
        externalTokenBridge();
        $scenario = externalScenario($this->work);
        $envelope = $scenario['envelope'];
        $maria = 'maria@exemplo.test';
        $token = externalSigner($this->work.DIRECTORY_SEPARATOR.'token-cms', 'Maria Alves Souza');

        externalIntent($this, $scenario, $maria)->assertCreated();
        finalizationRun($envelope);

        $prepared = externalTokenPrepare($this, $scenario, $maria, $token, 'cms')->assertCreated()->json('pending');
        expect($prepared['digest_kind'])->toBe('document');

        externalSubmit($this, $scenario, $maria, [
            'pending_id' => $prepared['id'],
            'mode' => 'cms',
            'cms' => externalSignCms($token, $prepared['digest']),
        ])->assertOk()->assertJsonPath('stage', 'applied')->assertJsonPath('request.signature_status', 'participant_external');

        $record = VerificationRecord::query()->where('envelope_id', $envelope->getKey())->firstOrFail();
        expect($envelope->refresh()->status)->toBe(EnvelopeStatus::Completed)
            ->and($record->signature_status)->toBe(SignatureStatus::ParticipantExternal)
            ->and(ParticipantSignature::withoutOrganizationScope()->firstOrFail()->validation_result['mode'])->toBe('cms');
    });
});

describe('sem o certificado da operadora', function () {
    beforeEach(fn () => externalBoot($this, false));
    afterEach(fn () => externalTeardown($this));

    it('conclui só com a assinatura externa e diz que a operadora não assinou', function () {
        $scenario = externalScenario($this->work);
        $envelope = $scenario['envelope'];

        externalIntent($this, $scenario, 'maria@exemplo.test')->assertCreated();
        finalizationRun($envelope);
        $prepared = externalSimulatedPrepare($this, $scenario, 'maria@exemplo.test');
        externalSimulatorSign($this, $scenario, 'maria@exemplo.test', $prepared['id'])->assertOk()->assertJsonPath('stage', 'applied');

        $envelope->refresh();
        $record = VerificationRecord::query()->where('envelope_id', $envelope->getKey())->firstOrFail();
        [$revision] = participantA1Versions($scenario['document'], DocumentVersionKind::SignedIncremental);
        $narrative = SignatureNarrative::for($envelope, $record);

        expect($envelope->status)->toBe(EnvelopeStatus::Completed)
            ->and($record->signature_status)->toBe(SignatureStatus::ParticipantExternal)
            ->and($record->certificate_reference_id)->toBeNull()
            ->and($record->final_sha256)->toBe($revision->sha256)
            ->and($narrative['state'])->toBe('participant_external')
            ->and($narrative['statement'])->toContain('A operadora não aplicou assinatura própria')
            ->and($narrative['certificate'])->toBeNull()
            ->and($narrative['validation']['integrity'])->toBe('intact')
            ->and($narrative['validation']['chain_trust'])->toBe('not_verified');
    });
});
