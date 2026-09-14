<?php

use App\Enums\AccessLinkPurpose;
use App\Enums\DocumentVersionKind;
use App\Enums\EnvelopeStatus;
use App\Enums\FieldType;
use App\Enums\PaymentStatus;
use App\Enums\SignatureStatus;
use App\Jobs\Ltv\ScheduleArchiveTimestampRefreshes;
use App\Models\Commission;
use App\Models\DocumentVersion;
use App\Models\Envelope;
use App\Models\RecipientAccessLink;
use App\Models\VerificationRecord;
use App\Models\VerificationRecordDocument;
use App\Services\Affiliates\CommissionLedger;
use App\Services\Envelopes\Finalization\FinalizationArtifacts;
use App\Services\Envelopes\Sending\SendEnvelope;
use App\Services\Ltv\LtvSigner;
use App\Services\Ltv\LtvState;
use App\Services\Ltv\LtvStatus;
use App\Services\Pdf\PdfToolClient;
use App\Services\Plans\Exceptions\SendingBlockedException;
use App\Services\Signing\Certificates\IncrementalChain;
use App\Services\Signing\GovBr\ExternalSignatureRequestStatus;
use App\Services\Signing\GovBr\GovBrSignatureKind;
use App\Services\Signing\GovBr\Models\ExternalSignatureRequest;
use App\Services\Signing\SignerTokens;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

require_once __DIR__.'/../Phase3/External/Support/ExternalSigningHelpers.php';
require_once __DIR__.'/../Phase3/GovBr/Support/GovBrHelpers.php';
require_once __DIR__.'/../Phase3/Ltv/Support/LtvHelpers.php';
require_once __DIR__.'/../Phase3/Risk/Support/RiskHelpers.php';
require_once __DIR__.'/../Phase3/Affiliates/Support/AffiliateHelpers.php';
require_once __DIR__.'/../Sending/Support/SendingHelpers.php';
require_once __DIR__.'/../Verification/Support/VerificationHelpers.php';

/*
|--------------------------------------------------------------------------
| Ponta a ponta da Fase 3, parte 1 (ondas E e H) — integração I-3A
|--------------------------------------------------------------------------
| Um envelope com TRÊS participantes, cada um por um meio (T1):
|   - Maria assina pelo componente local, com o SIMULADOR (nunca A3);
|   - João devolve o PDF "assinado no portal" — gerado aqui com certificado de TESTE pelo
|     simulador de portal do P3-GOV (não é o portal gov.br); sem âncora configurada o rótulo é
|     "assinatura digital de terceiro, cadeia não verificada", nunca gov.br;
|   - Ana assina com o próprio A1 (arquivo);
| e a operadora por último. Tudo com pdftool REAL; cada revisão é conferida byte a byte.
|
| No meio do caminho a organização recebe sinais de risco, vai para `restricted` e não consegue
| enviar um envelope NOVO — mas este, já enviado, conclui (roadmap §3.7).
|
| Depois: o código de longo prazo (P3-LTV) aplica B-LTA ao arquivo final e o re-carimbo roda com
| o relógio da plataforma avançado; o perfil exibido continua PAdES-B-B e o histórico de resumos
| aparece nas evidências. A finalização ainda NÃO chama o LtvSigner (integração pendente,
| docs/fase-3/longo-prazo.md §11) — este teste aplica o B-LTA como a integração aplicaria.
|
| Um segundo teste cobre o programa de afiliados: indicação → comissão pendente → aprovada após
| o prazo → estorno gera lançamento negativo. O sistema calcula, não paga.
*/

const P3E2E_MARIA = 'maria@exemplo.test';
const P3E2E_JOAO = 'joao@exemplo.test';
const P3E2E_ANA = 'ana@exemplo.test';

describe('envelope com componente local, devolução do portal, A1 e operadora', function () {
    beforeEach(function () {
        externalBoot($this);

        config()->set('assinavelox.govbr.trust_roots', []);
        config()->set('assinavelox.govbr.trust_root_fingerprints', []);
        config()->set('assinavelox.govbr.accept_test_certificates', true);
    });

    afterEach(function () {
        putenv(LTV_PASS_ENV);
        externalTeardown($this);
    });

    it('conclui com as quatro assinaturas, cada uma com o seu rótulo; restricted não impede a conclusão; LTV mantém PAdES-B-B', function () {
        $field = static fn (string $key, float $x): array => ['key' => $key, 'type' => FieldType::Signature, 'page' => 1, 'x' => $x, 'y' => 0.60, 'width' => 0.25, 'height' => 0.08];

        $scenario = finalizationEnvelope($this->work, [
            ['name' => 'Maria Alves Souza', 'email' => P3E2E_MARIA, 'accepted' => true, 'fields' => [$field('maria.signature', 0.05)]],
            ['name' => 'Joao Lima', 'email' => P3E2E_JOAO, 'accepted' => true, 'fields' => [$field('joao.signature', 0.37)]],
            ['name' => 'Ana Paula Reis', 'email' => P3E2E_ANA, 'accepted' => true, 'fields' => [$field('ana.signature', 0.69)]],
        ]);

        /** @var Envelope $envelope */
        $envelope = $scenario['envelope'];
        $organization = $scenario['organization'];
        $document = $scenario['document'];

        // As três flags ligadas no plano da organização (com os interruptores globais).
        participantA1Enable($organization);
        externalEnable($organization);
        govbrEnable($organization);

        $scenario['tokens'] = [];

        foreach ($scenario['recipients'] as $email => $recipient) {
            $raw = SignerTokens::generate();

            RecipientAccessLink::query()->create([
                'recipient_id' => $recipient->getKey(),
                'envelope_id' => $envelope->getKey(),
                'document_version_id' => $scenario['version']->getKey(),
                'organization_id' => $organization->getKey(),
                'token_digest' => SignerTokens::digest($raw),
                'purpose' => AccessLinkPurpose::Signing,
                'expires_at' => $envelope->expires_at,
            ]);

            $scenario['tokens'][$email] = $raw;
        }

        // -- 1. Cada participante escolhe o seu meio ------------------------------------------
        externalIntent($this, $scenario, P3E2E_MARIA)->assertCreated()->assertJsonPath('method', 'local_component');
        govbrCall($this, $scenario, P3E2E_JOAO, 'post', 'sign.govbr.intent')->assertCreated();
        participantA1Intent($this, $scenario, P3E2E_ANA)->assertCreated();

        // A finalização congela a base e ESPERA pelos três.
        finalizationRun($envelope);
        expect($envelope->refresh()->status)->toBe(EnvelopeStatus::Finalizing)
            ->and(participantA1Versions($document, DocumentVersionKind::PreSignature))->toHaveCount(1);

        // -- 2. Antifraude: a organização vai para restricted e não envia envelope NOVO -------
        config()->set('assinavelox.features.antifraud', true);
        riskRestrictViaSignals($organization);
        expect(riskStatusOf($organization))->toBe('restricted');

        fakeEmailProvider();
        $fresh = readyEnvelope($organization, $scenario['owner']);

        try {
            app(SendEnvelope::class)->handle($fresh);
            $this->fail('O envio de um envelope novo deveria ter sido bloqueado.');
        } catch (SendingBlockedException $exception) {
            expect($exception->errorCode)->toBe('risk_restricted');
        }

        expect($fresh->fresh()->status)->toBe(EnvelopeStatus::Ready);

        // -- 3. Maria: componente local (SIMULADOR) -------------------------------------------
        $prepared = externalSimulatedPrepare($this, $scenario, P3E2E_MARIA);
        expect($prepared['simulated'])->toBeTrue();

        externalSimulatorSign($this, $scenario, P3E2E_MARIA, $prepared['id'])->assertOk()
            ->assertJsonPath('request.signature_status', 'participant_external');

        expect($envelope->refresh()->status)->toBe(EnvelopeStatus::Finalizing);

        // -- 4. João: reserva, baixa os bytes exatos, "assina no portal" e devolve ------------
        $state = govbrCall($this, $scenario, P3E2E_JOAO, 'post', 'sign.govbr.reserve')->assertOk()
            ->assertJsonPath('stage', 'reserved')
            ->json();
        $requestUlid = (string) $state['documents'][0]['request']['id'];

        $download = govbrCall($this, $scenario, P3E2E_JOAO, 'download', 'sign.govbr.download', ['pedido' => $requestUlid])->assertOk();
        $downloaded = $this->work.DIRECTORY_SEPARATOR.'reservada.pdf';
        file_put_contents($downloaded, $download->streamedContent());
        expect(hash_file('sha256', $downloaded))->toBe($state['documents'][0]['request']['expected_revision']['sha256']);

        $joao = govbrCertificate($this->work, 'Joao Lima');
        $returned = govbrSimulate('sign', $downloaded, $this->work.DIRECTORY_SEPARATOR.'devolvido.pdf', $joao);

        govbrUpload($this, $scenario, P3E2E_JOAO, $requestUlid, $returned)->assertCreated()
            ->assertJsonPath('documents.0.request.status', 'completed')
            ->assertJsonPath('documents.0.request.signature.kind', 'participant_external_unverified');

        // A devolução ENTROU na espera da finalização: sem a Ana, o envelope continua aberto.
        expect($envelope->refresh()->status)->toBe(EnvelopeStatus::Finalizing);

        // -- 5. Ana: A1 por arquivo; a aplicação dela destrava a conclusão (operadora por último)
        $ana = participantA1Certificate($this->work.DIRECTORY_SEPARATOR.'certs-ana', 'Ana Paula Reis', 'senha-da-ana-Kw37!');
        participantA1Submit($this, $scenario, P3E2E_ANA, $ana)->assertSuccessful();

        $envelope->refresh();
        $record = VerificationRecord::query()->where('envelope_id', $envelope->getKey())->firstOrFail();

        // Restricted não toca o que já foi enviado: o envelope conclui.
        expect(riskStatusOf($organization))->toBe('restricted')
            ->and($envelope->status)->toBe(EnvelopeStatus::Completed)
            // Dois meios externos no mesmo arquivo ⇒ o valor mais genérico; a lista traz cada um.
            ->and($record->signature_status)->toBe(SignatureStatus::ParticipantExternal)
            ->and($record->signature_profile)->toBe('PAdES-B-B')
            ->and($record->validation_result['incremental_chain']['ok'])->toBeTrue()
            ->and($record->validation_result['result']['signature_count'])->toBe(4);

        // -- 6. pdftool: as quatro assinaturas e as revisões, byte a byte ----------------------
        $final = finalizationDownload($envelope->finalVersion, $this->work.DIRECTORY_SEPARATOR.'final.pdf');
        $validation = app(PdfToolClient::class)->validate($final, [$this->simulator['ca'], $joao['ca'], $ana['ca'], $this->operator['pem']]);

        expect($validation->signatureCount)->toBe(4)
            ->and($validation->allIntact)->toBeTrue()
            ->and($validation->allValid)->toBeTrue()
            ->and($validation->allTrusted())->toBeTrue()
            ->and(IncrementalChain::analyse($validation, 4)['ok'])->toBeTrue()
            ->and($validation->signatures[3]->coverage)->toBe('ENTIRE_FILE')
            ->and(hash_file('sha256', $final))->toBe($record->final_sha256);

        [$base] = participantA1Versions($document, DocumentVersionKind::PreSignature);
        $revisions = participantA1Versions($document, DocumentVersionKind::SignedIncremental);
        $bytes = static fn (DocumentVersion $version): string => (string) Storage::disk('documents')->get($version->storage_path);
        expect($revisions)->toHaveCount(3);

        $chain = [$bytes($base), ...array_map($bytes, $revisions), (string) file_get_contents($final)];

        for ($i = 1; $i < count($chain); $i++) {
            expect(str_starts_with($chain[$i], $chain[$i - 1]))->toBeTrue("revisão {$i} não começa pela anterior");
        }

        $govbrRow = ExternalSignatureRequest::withoutOrganizationScope()->where('ulid', $requestUlid)->firstOrFail();
        expect($govbrRow->status)->toBe(ExternalSignatureRequestStatus::Completed)
            ->and($govbrRow->signature_kind)->toBe(GovBrSignatureKind::ParticipantExternalUnverified)
            ->and($govbrRow->signed_document_version_id)->toBe($revisions[1]->getKey());

        // -- 7. Páginas: cada meio com o seu rótulo; nada de "gov.br" sem âncora; nunca A3 ----
        $public = verifyProps($this->get(route('verify.show', ['code' => $envelope->verification_code])))['result'];
        $kinds = collect($public['participant_signatures'])->pluck('kind')->all();

        expect($kinds)->toEqualCanonicalizing(['participant_a1', 'participant_external', 'participant_external_unverified'])
            ->and(collect($public['participant_signatures'])->firstWhere('kind', 'participant_external')['simulated'])->toBeTrue()
            ->and(collect($public['participant_signatures'])->firstWhere('kind', 'participant_external_unverified')['label'])->toStartWith('Assinatura digital de terceiro, cadeia não verificada');

        $publicJson = json_encode($public, JSON_UNESCAPED_UNICODE);
        expect($publicJson)->not->toContain('Assinatura gov.br')
            ->and($publicJson)->not->toContain('participant_a3')
            ->and($publicJson)->not->toContain('@exemplo.test');

        actingAsMember($scenario['owner'], $organization);
        $this->get(route('envelopes.evidence', $envelope))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('signature_status', 'participant_external')
                ->where('signature_profile', 'PAdES-B-B')
                ->has('participant_signatures', 3)
                ->missing('ltv')
                ->missing('hash_history'));

        // -- 8. Longo prazo: B-LTA pelo código do P3-LTV, re-carimbo com relógio avançado ----
        $pki = ltvSetup($this->work);
        $signedPath = $this->work.DIRECTORY_SEPARATOR.'final-lta.pdf';
        $ltv = app(LtvSigner::class)->sign(
            $final,
            $signedPath,
            $pki['signer_pfx'],
            LTV_PASS_ENV,
            'B-LTA',
            ['reason' => 'Carimbo de arquivamento AssinaVelox (teste)'],
            (int) $organization->getKey(),
            (int) $envelope->getKey(),
        );

        expect($ltv['declared_profile'])->toBe('PAdES-B-B')
            ->and($ltv['effective_level'])->toBe('B-LTA')
            ->and($ltv['degraded'])->toBeFalse()
            ->and($ltv['report']['icp_brasil'])->toBeFalse();

        $lta = app(FinalizationArtifacts::class)->store($envelope, $document, DocumentVersionKind::Final, $signedPath);
        $document->newQuery()->withoutGlobalScopes()->whereKey($document->getKey())->update(['final_version_id' => $lta->getKey()]);
        Envelope::withoutOrganizationScope()->whereKey($envelope->getKey())->update(['final_document_version_id' => $lta->getKey()]);
        VerificationRecordDocument::query()->where('verification_record_id', $record->getKey())->where('document_id', $document->getKey())
            ->update(['final_document_version_id' => $lta->getKey(), 'final_sha256' => $lta->sha256]);
        $record->forceFill(['final_document_version_id' => $lta->getKey(), 'final_sha256' => $lta->sha256])->save();
        app(LtvState::class)->apply($record->refresh(), $ltv['report']);

        // Revisão adversarial I-3A (roadmap T2): o estado técnico é o nível do ARQUIVO — as
        // assinaturas dos participantes não têm carimbo próprio, então ele não é B-LTA; a camada
        // de arquivamento da operadora existe à parte e é o que o agendador renova.
        expect(LtvState::status($record->refresh()))->toBe(LtvStatus::NotApplicable)
            ->and(LtvState::archiveLayer($record))->toBeTrue()
            ->and($record->getAttribute('ltv_next_refresh_at'))->not->toBeNull()
            ->and($record->signature_profile)->toBe('PAdES-B-B')
            ->and($record->signature_status)->toBe(SignatureStatus::ParticipantExternal);

        // Relógio da plataforma avançado até depois da próxima renovação: o agendador despacha.
        $this->travelTo(Carbon::parse((string) $record->getAttribute('ltv_next_refresh_at'))->addDay());
        app()->call([new ScheduleArchiveTimestampRefreshes, 'handle']);

        $record->refresh();
        $refreshed = DocumentVersion::withoutOrganizationScope()->findOrFail($record->final_document_version_id);

        expect($refreshed->getKey())->not->toBe($lta->getKey())
            ->and($record->final_sha256)->toBe($refreshed->sha256)
            ->and($record->signature_profile)->toBe('PAdES-B-B')
            ->and(LtvState::status($record))->toBe(LtvStatus::NotApplicable)
            ->and(LtvState::archiveLayer($record))->toBeTrue();

        $refreshedPath = finalizationDownload($refreshed, $this->work.DIRECTORY_SEPARATOR.'final-renovado.pdf');
        expect(str_starts_with((string) file_get_contents($refreshedPath), (string) file_get_contents($signedPath)))->toBeTrue();

        $report = ltvValidate($refreshedPath, $pki);
        expect($report['document_timestamp_count'])->toBe(2)
            ->and($report['timestamp_chain_valid'])->toBeTrue()
            // Honesto: as assinaturas dos participantes não têm carimbo próprio, então o nível
            // efetivo do ARQUIVO continua B-B — o B-LTA é da camada de arquivamento.
            ->and($report['effective_level'])->toBe('B-B');

        // O histórico de resumos aparece nas evidências; o perfil exibido continua PAdES-B-B.
        actingAsMember($scenario['owner'], $organization);
        $this->get(route('envelopes.evidence', $envelope))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('signature_profile', 'PAdES-B-B')
                // T2 (revisão adversarial I-3A): nível do arquivo B-B ⇒ not_applicable; a camada de
                // arquivamento da operadora aparece à parte.
                ->where('ltv.status', 'not_applicable')
                ->where('ltv.archive_layer', true)
                ->where('ltv.announced', false)
                ->where('ltv.announced_profile', 'PAdES-B-B')
                // O arquivo B-LTA (substituído) e o renovado (vigente).
                ->has('hash_history', 2)
                ->where('hash_history.0.sha256', $lta->sha256)
                ->where('hash_history.0.current', false)
                ->where('hash_history.1.current', true)
                ->where('hash_history.1.sha256', $refreshed->sha256)
                ->has('hash_history_notice'));

        $after = verifyProps($this->get(route('verify.show', ['code' => $envelope->verification_code])))['result'];
        expect(json_encode($after, JSON_UNESCAPED_UNICODE))->not->toContain('PAdES-B-LT')
            ->and(json_encode($after, JSON_UNESCAPED_UNICODE))->not->toContain('B-LTA');
    });
});

describe('programa de afiliados', function () {
    beforeEach(function () {
        enableAffiliates();
    });

    it('indicação → comissão pendente → aprovada depois do prazo → estorno gera lançamento negativo; nada é pago', function () {
        $affiliate = makeAffiliate();
        ['organization' => $organization] = referOrganization($affiliate);

        $payment = paymentFor($organization, 20_000, paidAt: now());

        $commission = Commission::query()->sole();
        expect($commission->status)->toBe(Commission::STATUS_PENDING)
            ->and($commission->amount_cents)->toBe(2_000)
            ->and($commission->currency)->toBe('BRL');

        $this->travel(29)->days();
        expect(app(CommissionLedger::class)->approveDue())->toBe(0);

        $this->travel(2)->days();
        $this->artisan('affiliates:settle')->assertSuccessful();
        expect($commission->fresh()->status)->toBe(Commission::STATUS_APPROVED);

        movePayment($payment, PaymentStatus::Refunded, ['status_detail' => 'refunded', 'refunded_cents' => 20_000]);

        $reversal = Commission::query()->where('kind', Commission::KIND_REVERSAL)->sole();
        expect($reversal->amount_cents)->toBe(-2_000)
            ->and($commission->fresh()->status)->toBe(Commission::STATUS_APPROVED)
            ->and($commission->fresh()->paid_at)->toBeNull()
            ->and(Commission::query()->whereNotNull('paid_at')->count())->toBe(0);
    });
});
