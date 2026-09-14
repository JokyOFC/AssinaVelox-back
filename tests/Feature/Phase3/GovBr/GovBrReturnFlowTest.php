<?php

use App\Enums\DocumentVersionKind;
use App\Enums\RecipientRole;
use App\Jobs\Envelopes\FinalizeEnvelope;
use App\Models\DocumentVersion;
use App\Services\Envelopes\Finalization\FinalizationArtifacts;
use App\Services\Signing\GovBr\ExternalSignatureRequestStatus;
use App\Services\Signing\GovBr\GovBrSignatureKind;
use App\Services\Signing\GovBr\GovBrTrustAnchors;
use App\Services\Signing\GovBr\Models\ExternalSignatureRequest;
use App\Services\Signing\GovBr\Models\ExternalSignatureReturn;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Pdf\Support\PdfFixtures;

require_once __DIR__.'/Support/GovBrHelpers.php';

/*
|--------------------------------------------------------------------------
| Devolução do PDF assinado no portal gov.br — fluxo HTTP completo (P3-GOV)
|--------------------------------------------------------------------------
| intenção → reserva da revisão (hash registrado) → download dos bytes exatos → assinatura
| FORA (simulador de portal, certificado de TESTE) → devolução → conferência → aceite ou
| recusa. pdftool REAL. Nada disto é o portal gov.br: o formato real é NÃO CONFIRMADO.
*/

beforeEach(fn () => govbrBoot($this));
afterEach(fn () => govbrTeardown($this));

it('aceita a devolução correta com o rótulo honesto: sem âncora, "cadeia não verificada", nunca gov.br', function () {
    $scenario = govbrScenario($this->work);
    $maria = govbrCertificate($this->work, 'Maria Alves Souza');

    govbrCall($this, $scenario, 'maria@exemplo.test', 'get', 'sign.govbr.show')->assertOk()
        ->assertJsonPath('stage', 'ready_to_reserve')
        ->assertJsonPath('trust.anchors_configured', false)
        ->assertJsonPath('trust.accepted_kind', 'participant_external_unverified');

    govbrCall($this, $scenario, 'maria@exemplo.test', 'post', 'sign.govbr.intent')->assertCreated()
        ->assertJsonPath('documents.0.request.status', 'requested');

    $state = govbrCall($this, $scenario, 'maria@exemplo.test', 'post', 'sign.govbr.reserve')->assertOk()
        ->assertJsonPath('stage', 'reserved')
        ->assertJsonPath('can_upload', true)
        ->json();

    $requestUlid = $state['documents'][0]['request']['id'];
    $base = $scenario['base'];
    $row = ExternalSignatureRequest::withoutOrganizationScope()->where('ulid', $requestUlid)->firstOrFail();

    // A revisão esperada fica registrada: recipient, hash, estado e expiração.
    expect($row->status)->toBe(ExternalSignatureRequestStatus::Pending)
        ->and($row->recipient_id)->toBe($scenario['recipients']['maria@exemplo.test']->getKey())
        ->and($row->expected_document_version_id)->toBe($base->getKey())
        ->and($row->expected_revision_sha256)->toBe($base->sha256)
        ->and($row->expires_at->isFuture())->toBeTrue()
        ->and($state['documents'][0]['request']['expected_revision']['sha256'])->toBe($base->sha256);

    // O download entrega exatamente os bytes reservados.
    $download = govbrCall($this, $scenario, 'maria@exemplo.test', 'download', 'sign.govbr.download', ['pedido' => $requestUlid])->assertOk();
    $downloaded = $this->work.DIRECTORY_SEPARATOR.'baixado.pdf';
    file_put_contents($downloaded, $download->streamedContent());
    expect(hash_file('sha256', $downloaded))->toBe($base->sha256);

    $returned = govbrSimulate('sign', $downloaded, $this->work.DIRECTORY_SEPARATOR.'devolvido.pdf', $maria);

    $response = govbrUpload($this, $scenario, 'maria@exemplo.test', $requestUlid, $returned)->assertCreated()
        ->assertJsonPath('stage', 'completed')
        ->assertJsonPath('documents.0.request.status', 'completed')
        ->assertJsonPath('documents.0.request.signature.kind', 'participant_external_unverified')
        ->assertJsonPath('documents.0.request.signature.trusted', false)
        ->assertJsonPath('documents.0.request.signature.is_test', true);

    expect($response->json('documents.0.request.signature.label'))
        ->toStartWith('Assinatura digital de terceiro, cadeia não verificada')
        ->and(json_encode($response->json(), JSON_UNESCAPED_UNICODE))->not->toContain('Assinatura gov.br');

    // A revisão aceita entrou na cadeia: bytes devolvidos, base como prefixo.
    $row->refresh();
    $signed = DocumentVersion::withoutOrganizationScope()->findOrFail($row->signed_document_version_id);
    $bytes = (string) Storage::disk('documents')->get($signed->storage_path);

    expect($signed->kind)->toBe(DocumentVersionKind::SignedIncremental)
        ->and($signed->sha256)->toBe(hash_file('sha256', $returned))
        ->and(str_starts_with($bytes, (string) Storage::disk('documents')->get($base->storage_path)))->toBeTrue()
        ->and($row->signature_kind)->toBe(GovBrSignatureKind::ParticipantExternalUnverified)
        ->and($row->validation_result['prefix_preserved'])->toBeTrue()
        ->and($row->validation_result['new_signature_count'])->toBe(1)
        ->and($row->validation_result['coverage'])->toBe('ENTIRE_FILE')
        ->and($row->validation_result['revocation'])->toBe('not_checked')
        ->and(ExternalSignatureReturn::withoutOrganizationScope()->where('outcome', 'accepted')->count())->toBe(1);

    Bus::assertDispatched(FinalizeEnvelope::class);

    // Um segundo envio do mesmo documento não entra.
    govbrUpload($this, $scenario, 'maria@exemplo.test', $requestUlid, $returned)->assertStatus(409)->assertJsonPath('code', 'already_completed');
});

it('com a raiz fixada por impressão digital e a cadeia conferida, o rótulo é "Assinatura gov.br (avançada)"', function () {
    $scenario = govbrScenario($this->work);
    $maria = govbrCertificate($this->work, 'Maria Alves Souza');
    config()->set('assinavelox.govbr.trust_roots', [$maria['ca']]);
    config()->set('assinavelox.govbr.trust_root_fingerprints', GovBrTrustAnchors::fingerprintsOfFile($maria['ca']));

    $requestUlid = govbrReserve($this, $scenario, 'maria@exemplo.test');
    $returned = govbrSimulate('sign', govbrBaseFile($scenario['base'], $this->work.'/base.pdf'), $this->work.'/devolvido.pdf', $maria);

    govbrUpload($this, $scenario, 'maria@exemplo.test', $requestUlid, $returned)->assertCreated()
        ->assertJsonPath('trust.anchors_configured', true)
        ->assertJsonPath('documents.0.request.signature.kind', 'participant_govbr')
        ->assertJsonPath('documents.0.request.signature.trusted', true);

    $row = ExternalSignatureRequest::withoutOrganizationScope()->where('ulid', $requestUlid)->firstOrFail();

    expect($row->signature_kind)->toBe(GovBrSignatureKind::ParticipantGovBr)
        ->and($row->signature_kind->label())->toBe('Assinatura gov.br (avançada)');
});

it('com âncora configurada, assinatura de outra cadeia é recusada', function () {
    $scenario = govbrScenario($this->work);
    $maria = govbrCertificate($this->work, 'Maria Alves Souza');
    $other = govbrCertificate($this->work, 'Outra Autoridade');
    config()->set('assinavelox.govbr.trust_roots', [$other['ca']]);
    config()->set('assinavelox.govbr.trust_root_fingerprints', GovBrTrustAnchors::fingerprintsOfFile($other['ca']));

    $requestUlid = govbrReserve($this, $scenario, 'maria@exemplo.test');
    $returned = govbrSimulate('sign', govbrBaseFile($scenario['base'], $this->work.'/base.pdf'), $this->work.'/devolvido.pdf', $maria);

    govbrUpload($this, $scenario, 'maria@exemplo.test', $requestUlid, $returned)->assertStatus(422)->assertJsonPath('code', 'chain_not_trusted');

    expect(DocumentVersion::withoutOrganizationScope()->where('kind', DocumentVersionKind::SignedIncremental->value)->count())->toBe(0);
});

it('âncora configurada sem a impressão digital correspondente: devolução recusada com 503, nada aceito', function () {
    $scenario = govbrScenario($this->work);
    $maria = govbrCertificate($this->work, 'Maria Alves Souza');
    config()->set('assinavelox.govbr.trust_roots', [$maria['ca']]);
    config()->set('assinavelox.govbr.trust_root_fingerprints', [str_repeat('ab', 32)]);

    $requestUlid = govbrReserve($this, $scenario, 'maria@exemplo.test');
    $returned = govbrSimulate('sign', govbrBaseFile($scenario['base'], $this->work.'/base.pdf'), $this->work.'/devolvido.pdf', $maria);

    govbrUpload($this, $scenario, 'maria@exemplo.test', $requestUlid, $returned)->assertStatus(503)->assertJsonPath('code', 'trust_anchor_misconfigured');

    expect(ExternalSignatureRequest::withoutOrganizationScope()->where('ulid', $requestUlid)->firstOrFail()->status)->toBe(ExternalSignatureRequestStatus::Pending);
});

it('recusa arquivo que não contém a revisão esperada como prefixo (outro documento e arquivo regravado)', function (string $mode) {
    $scenario = govbrScenario($this->work);
    $maria = govbrCertificate($this->work, 'Maria Alves Souza');
    $requestUlid = govbrReserve($this, $scenario, 'maria@exemplo.test');

    // 'other' assina OUTRO documento; 'rewrite' regrava a base (mesmo conteúdo, outros bytes).
    $input = $mode === 'other'
        ? PdfFixtures::onePagePdf($this->work.'/outro-documento.pdf', 'Outro contrato')
        : govbrBaseFile($scenario['base'], $this->work.'/base.pdf');
    $returned = govbrSimulate($mode === 'other' ? 'sign' : 'rewrite-then-sign', $input, $this->work.'/devolvido.pdf', $maria);

    govbrUpload($this, $scenario, 'maria@exemplo.test', $requestUlid, $returned)->assertStatus(422)
        ->assertJsonPath('code', 'base_not_prefix')
        ->assertJsonStructure(['message', 'code', 'errors' => ['file']]);

    $row = ExternalSignatureRequest::withoutOrganizationScope()->where('ulid', $requestUlid)->firstOrFail();
    $attempt = ExternalSignatureReturn::withoutOrganizationScope()->firstOrFail();

    expect($row->status)->toBe(ExternalSignatureRequestStatus::Pending)
        ->and($row->attempts)->toBe(1)
        ->and($row->failure_code)->toBe('base_not_prefix')
        ->and($attempt->outcome)->toBe('rejected')
        ->and($attempt->received_sha256)->toBe(hash_file('sha256', $returned))
        ->and($attempt->expected_revision_sha256)->toBe($scenario['base']->sha256)
        ->and(DocumentVersion::withoutOrganizationScope()->where('kind', DocumentVersionKind::SignedIncremental->value)->count())->toBe(0);
})->with(['other', 'rewrite']);

it('recusa duas assinaturas novas, assinatura que não cobre o arquivo, conteúdo alterado e certificação que bloqueia', function (string $mode, string $code, ?string $alsoInChecks) {
    $scenario = govbrScenario($this->work);
    $maria = govbrCertificate($this->work, 'Maria Alves Souza');
    $joao = govbrCertificate($this->work, 'Joao Lima');
    $requestUlid = govbrReserve($this, $scenario, 'maria@exemplo.test');

    $returned = govbrSimulate($mode, govbrBaseFile($scenario['base'], $this->work.'/base.pdf'), $this->work.'/devolvido.pdf', $maria, $mode === 'two' ? $joao : null);

    govbrUpload($this, $scenario, 'maria@exemplo.test', $requestUlid, $returned)->assertStatus(422)->assertJsonPath('code', $code);

    $attempt = ExternalSignatureReturn::withoutOrganizationScope()->firstOrFail();

    expect($attempt->rejection_code)->toBe($code)
        ->and(DocumentVersion::withoutOrganizationScope()->where('kind', DocumentVersionKind::SignedIncremental->value)->count())->toBe(0);

    if ($alsoInChecks !== null) {
        expect($attempt->checks['problems'])->toContain($alsoInChecks);
    }
})->with([
    'duas assinaturas novas' => ['two', 'unexpected_revision_count', 'multiple_new_signatures'],
    'assinatura que não cobre o arquivo' => ['append-after', 'unexpected_revision_count', 'signature_not_covering_file'],
    'conteúdo alterado junto com a assinatura' => ['alter', 'unpermitted_changes', null],
    'certificação que proíbe as assinaturas seguintes' => ['certify-p1', 'docmdp_locks_document', null],
]);

it('o carimbo visível (widget da assinatura) é aceito', function () {
    $scenario = govbrScenario($this->work);
    $maria = govbrCertificate($this->work, 'Maria Alves Souza');
    $requestUlid = govbrReserve($this, $scenario, 'maria@exemplo.test');
    $returned = govbrSimulate('visible', govbrBaseFile($scenario['base'], $this->work.'/base.pdf'), $this->work.'/devolvido.pdf', $maria);

    govbrUpload($this, $scenario, 'maria@exemplo.test', $requestUlid, $returned)->assertCreated()->assertJsonPath('stage', 'completed');
});

it('recusa certificado de outra pessoa (CPF divergente) e aceita o do titular sem gravar o CPF completo', function () {
    $scenario = govbrScenario($this->work);
    govbrInformCpf($scenario, 'maria@exemplo.test', '52998224725');
    $impostor = govbrCertificate($this->work, 'Maria Alves Souza', '11144477735');
    $maria = govbrCertificate($this->work, 'Maria Alves Souza', '52998224725');
    $requestUlid = govbrReserve($this, $scenario, 'maria@exemplo.test');
    $base = govbrBaseFile($scenario['base'], $this->work.'/base.pdf');

    $wrong = govbrSimulate('sign', $base, $this->work.'/impostor.pdf', $impostor);
    govbrUpload($this, $scenario, 'maria@exemplo.test', $requestUlid, $wrong)->assertStatus(422)->assertJsonPath('code', 'holder_mismatch');

    $right = govbrSimulate('sign', $base, $this->work.'/titular.pdf', $maria);
    govbrUpload($this, $scenario, 'maria@exemplo.test', $requestUlid, $right)->assertCreated()
        ->assertJsonPath('documents.0.request.signature.holder_cpf_masked', '***.982.247-**');

    $row = ExternalSignatureRequest::withoutOrganizationScope()->where('ulid', $requestUlid)->firstOrFail();
    expect($row->holder_cpf_match)->toBe('match');

    // Nenhum CPF completo (do participante ou dos certificados) nas tabelas desta área.
    foreach (['external_signature_requests', 'external_signature_returns'] as $table) {
        $dump = json_encode(DB::table($table)->get()->all(), JSON_UNESCAPED_UNICODE);
        expect($dump)->not->toContain('52998224725')->and($dump)->not->toContain('11144477735');
    }
});

it('com CPF informado, certificado sem CPF legível é recusado (exigência configurável)', function () {
    $scenario = govbrScenario($this->work);
    govbrInformCpf($scenario, 'maria@exemplo.test', '52998224725');
    $noCpf = govbrCertificate($this->work, 'Maria Alves Souza');
    $requestUlid = govbrReserve($this, $scenario, 'maria@exemplo.test');
    $returned = govbrSimulate('sign', govbrBaseFile($scenario['base'], $this->work.'/base.pdf'), $this->work.'/devolvido.pdf', $noCpf);

    govbrUpload($this, $scenario, 'maria@exemplo.test', $requestUlid, $returned)->assertStatus(422)->assertJsonPath('code', 'holder_cpf_not_found');

    config()->set('assinavelox.govbr.require_holder_cpf', false);
    govbrUpload($this, $scenario, 'maria@exemplo.test', $requestUlid, $returned)->assertCreated();
});

it('pedido expirado é recusado e a revisão deixa de ser entregue', function () {
    $scenario = govbrScenario($this->work);
    $maria = govbrCertificate($this->work, 'Maria Alves Souza');
    $requestUlid = govbrReserve($this, $scenario, 'maria@exemplo.test');
    $returned = govbrSimulate('sign', govbrBaseFile($scenario['base'], $this->work.'/base.pdf'), $this->work.'/devolvido.pdf', $maria);

    $this->travel(121)->minutes();

    govbrUpload($this, $scenario, 'maria@exemplo.test', $requestUlid, $returned)->assertStatus(409)->assertJsonPath('code', 'reservation_expired');

    $row = ExternalSignatureRequest::withoutOrganizationScope()->where('ulid', $requestUlid)->firstOrFail();
    expect($row->status)->toBe(ExternalSignatureRequestStatus::Requested)
        ->and($row->expected_revision_sha256)->toBeNull()
        ->and($row->failure_code)->toBe('reservation_expired');

    govbrCall($this, $scenario, 'maria@exemplo.test', 'download', 'sign.govbr.download', ['pedido' => $requestUlid])->assertStatus(409);
    govbrCall($this, $scenario, 'maria@exemplo.test', 'get', 'sign.govbr.show')->assertOk()->assertJsonPath('stage', 'ready_to_reserve');
});

it('se outra assinatura entrou depois da reserva, a devolução é recusada (nunca revisão irmã)', function () {
    $scenario = govbrScenario($this->work);
    $maria = govbrCertificate($this->work, 'Maria Alves Souza');
    $joao = govbrCertificate($this->work, 'Joao Lima');
    $requestUlid = govbrReserve($this, $scenario, 'maria@exemplo.test');
    $base = govbrBaseFile($scenario['base'], $this->work.'/base.pdf');

    // Outra revisão assinada (p.ex. um A1 de participante) entra sobre a mesma base.
    $other = govbrSimulate('sign', $base, $this->work.'/outra-revisao.pdf', $joao);
    app(FinalizationArtifacts::class)->store($scenario['envelope'], $scenario['document'], DocumentVersionKind::SignedIncremental, $other);

    $returned = govbrSimulate('sign', $base, $this->work.'/devolvido.pdf', $maria);

    govbrUpload($this, $scenario, 'maria@exemplo.test', $requestUlid, $returned)->assertStatus(409)->assertJsonPath('code', 'base_changed');

    expect(DocumentVersion::withoutOrganizationScope()->where('kind', DocumentVersionKind::SignedIncremental->value)->count())->toBe(1)
        ->and(ExternalSignatureRequest::withoutOrganizationScope()->where('ulid', $requestUlid)->firstOrFail()->status)->toBe(ExternalSignatureRequestStatus::Requested);
});

it('um participante por vez: a reserva de outro no mesmo documento espera', function () {
    $scenario = govbrScenario($this->work);
    govbrReserve($this, $scenario, 'maria@exemplo.test');

    govbrCall($this, $scenario, 'joao@exemplo.test', 'post', 'sign.govbr.intent')->assertCreated();
    govbrCall($this, $scenario, 'joao@exemplo.test', 'post', 'sign.govbr.reserve')->assertStatus(409)->assertJsonPath('code', 'reservation_busy');
});

it('arquivo que não é PDF é recusado antes da conferência', function () {
    $scenario = govbrScenario($this->work);
    $requestUlid = govbrReserve($this, $scenario, 'maria@exemplo.test');
    $fake = $this->work.'/nao-e-pdf.pdf';
    file_put_contents($fake, 'isto não é um PDF');

    govbrUpload($this, $scenario, 'maria@exemplo.test', $requestUlid, $fake)->assertStatus(422)->assertJsonPath('code', 'not_pdf');
});

it('sem a sessão deste participante, nada é permitido (403); o pedido de outro participante não é visível (404)', function () {
    $scenario = govbrScenario($this->work);
    $requestUlid = govbrReserve($this, $scenario, 'maria@exemplo.test');

    govbrCall($this, $scenario, 'maria@exemplo.test', 'post', 'sign.govbr.reserve', authenticated: false)->assertStatus(403)->assertJsonPath('code', 'not_authenticated');
    govbrCall($this, $scenario, 'maria@exemplo.test', 'get', 'sign.govbr.show', authenticated: false)->assertOk()
        ->assertJsonPath('authenticated', false)
        ->assertJsonPath('documents.0.request.expected_revision.download_url', null);
    govbrCall($this, $scenario, 'joao@exemplo.test', 'download', 'sign.govbr.download', ['pedido' => $requestUlid])->assertStatus(404);
});

it('desistir libera a reserva e retoma a finalização', function () {
    $scenario = govbrScenario($this->work);
    $requestUlid = govbrReserve($this, $scenario, 'maria@exemplo.test');

    govbrCall($this, $scenario, 'maria@exemplo.test', 'post', 'sign.govbr.withdraw')->assertOk()->assertJsonPath('documents.0.request.status', 'withdrawn');

    expect(ExternalSignatureRequest::withoutOrganizationScope()->where('ulid', $requestUlid)->value('expected_revision_sha256'))->toBeNull();
    Bus::assertDispatched(FinalizeEnvelope::class);
});

it('flag desligada (global, trava de integração ou plano), aprovador ou visualizador: todas as rotas respondem 404', function (array $flags, ?RecipientRole $role) {
    $scenario = govbrScenario($this->work, enable: false);
    govbrEnable($scenario['organization'], ...$flags);

    if ($role !== null) {
        $scenario['recipients']['maria@exemplo.test']->forceFill(['role' => $role])->save();
    }

    $fake = str_repeat('A', 26);

    govbrCall($this, $scenario, 'maria@exemplo.test', 'get', 'sign.govbr.show')->assertNotFound();
    govbrCall($this, $scenario, 'maria@exemplo.test', 'post', 'sign.govbr.intent')->assertNotFound();
    govbrCall($this, $scenario, 'maria@exemplo.test', 'post', 'sign.govbr.withdraw')->assertNotFound();
    govbrCall($this, $scenario, 'maria@exemplo.test', 'post', 'sign.govbr.reserve')->assertNotFound();
    govbrCall($this, $scenario, 'maria@exemplo.test', 'download', 'sign.govbr.download', ['pedido' => $fake])->assertNotFound();
    govbrCall($this, $scenario, 'maria@exemplo.test', 'upload', 'sign.govbr.upload', ['pedido' => $fake], ['file' => govbrFile($scenario['source'])])->assertNotFound();

    expect(ExternalSignatureRequest::withoutOrganizationScope()->count())->toBe(0);
})->with([
    'global desligado' => [[false, true, true], null],
    'trava de integração desligada' => [[true, false, true], null],
    'fora do plano' => [[true, true, false], null],
    'aprovador' => [[true, true, true], RecipientRole::Approver],
    'visualizador' => [[true, true, true], RecipientRole::Viewer],
]);
