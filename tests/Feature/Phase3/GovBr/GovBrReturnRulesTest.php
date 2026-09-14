<?php

use App\Enums\EnvelopeStatus;
use App\Integrations\GovBr\GovBrSignatureProvider;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Services\Signing\GovBr\Exceptions\GovBrReturnException;
use App\Services\Signing\GovBr\ExternalSignatureRequestStatus;
use App\Services\Signing\GovBr\GovBrReturnDecision;
use App\Services\Signing\GovBr\GovBrReturnFeature;
use App\Services\Signing\GovBr\GovBrReturnStage;
use App\Services\Signing\GovBr\GovBrSignatureKind;
use App\Services\Signing\GovBr\GovBrTrustAnchors;
use App\Services\Signing\GovBr\Models\ExternalSignatureRequest;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/../../Support/OrganizationHelpers.php';
require_once __DIR__.'/Support/GovBrHelpers.php';

/*
|--------------------------------------------------------------------------
| Regras da devolução gov.br sem PDF: decisão, rótulos, âncoras, prazos e a trava (P3-GOV)
|--------------------------------------------------------------------------
*/

/**
 * @param  array<string, mixed>  $signature
 * @return array<string, mixed>
 */
function govbrToolResult(array $signature = [], array $problems = []): array
{
    return [
        'ok' => true,
        'accepted' => $problems === [],
        'problems' => $problems,
        'prefix_preserved' => true,
        'new_revisions' => 1,
        'new_signature_count' => 1,
        'trust_roots_configured' => 0,
        'revocation' => 'not_checked',
        'signature' => $signature + [
            'intact' => true,
            'valid' => true,
            'coverage' => 'ENTIRE_FILE',
            'trusted' => false,
            'test_certificate' => false,
            'holder' => ['cpf_match' => 'unknown', 'cpf_masked' => null],
        ],
    ];
}

it('sem âncora configurada o rótulo nunca é gov.br, mesmo que o pdftool diga "trusted"', function () {
    $decision = GovBrReturnDecision::decide(govbrToolResult(['trusted' => true]), false, false, true, true);

    expect($decision->accepted)->toBeTrue()
        ->and($decision->kind)->toBe(GovBrSignatureKind::ParticipantExternalUnverified)
        ->and($decision->kind->label())->not->toContain('gov.br')
        ->and(GovBrSignatureKind::for(false, true))->toBe(GovBrSignatureKind::ParticipantExternalUnverified)
        ->and(GovBrSignatureKind::for(true, false))->toBe(GovBrSignatureKind::ParticipantExternalUnverified)
        ->and(GovBrSignatureKind::for(true, true))->toBe(GovBrSignatureKind::ParticipantGovBr);
});

it('os rótulos dizem o que é e o que não é', function () {
    expect(GovBrSignatureKind::ParticipantGovBr->label())->toBe('Assinatura gov.br (avançada)')
        ->and(GovBrSignatureKind::ParticipantGovBr->description())->toContain('Não é assinatura com certificado ICP-Brasil')
        ->and(GovBrSignatureKind::ParticipantExternalUnverified->label())->toBe('Assinatura digital de terceiro, cadeia não verificada')
        ->and(GovBrSignatureKind::ParticipantExternalUnverified->description())->toContain('Não se afirma que seja uma assinatura gov.br');

    foreach (GovBrSignatureKind::cases() as $kind) {
        expect(strtolower($kind->label()))->not->toContain('qualificada')->and($kind->label())->not->toContain('ICP');
    }
});

it('a decisão recusa o que o pdftool recusou e, por defesa em profundidade, o que os fatos não sustentam', function () {
    expect(GovBrReturnDecision::decide(govbrToolResult([], ['base_not_prefix', 'no_new_signature']), false, false, true, true)->rejectionCode)->toBe('base_not_prefix')
        ->and(GovBrReturnDecision::decide(['accepted' => true, 'problems' => []] + govbrToolResult(['coverage' => 'ENTIRE_REVISION']), false, false, true, true)->rejectionCode)->toBe('signature_invalid')
        ->and(GovBrReturnDecision::decide(['new_signature_count' => 2] + govbrToolResult(), false, false, true, true)->rejectionCode)->toBe('signature_invalid')
        ->and(GovBrReturnDecision::decide(govbrToolResult(['trusted' => false]), true, false, true, true)->rejectionCode)->toBe('chain_not_trusted')
        ->and(GovBrReturnDecision::decide(govbrToolResult(['test_certificate' => true]), false, false, true, false)->rejectionCode)->toBe('test_certificate_not_accepted')
        ->and(GovBrReturnDecision::decide(govbrToolResult(['holder' => ['cpf_match' => 'mismatch']]), false, true, true, true)->rejectionCode)->toBe('holder_mismatch')
        ->and(GovBrReturnDecision::decide(govbrToolResult(['holder' => ['cpf_match' => 'unknown']]), false, true, true, true)->rejectionCode)->toBe('holder_cpf_not_found')
        ->and(GovBrReturnDecision::decide(govbrToolResult(['holder' => ['cpf_match' => 'unknown']]), false, true, false, true)->accepted)->toBeTrue()
        ->and(GovBrReturnDecision::decide(govbrToolResult(['holder' => ['cpf_match' => 'match']]), false, true, true, true)->accepted)->toBeTrue();
});

it('o resumo gravável não carrega nome, CPF nem assunto do certificado', function () {
    $decision = GovBrReturnDecision::decide(govbrToolResult([
        'signer_subject' => 'CN=Maria:52998224725',
        'holder' => ['cpf_match' => 'match', 'cpf_masked' => '***.982.247-**', 'name' => 'Maria'],
    ]), false, true, true, true);

    $checks = json_encode($decision->checks(), JSON_UNESCAPED_UNICODE);

    expect($checks)->not->toContain('Maria')->and($checks)->not->toContain('52998224725')->and($checks)->toContain('"cpf_match":"match"');
});

it('as âncoras só valem fixadas por impressão digital', function () {
    // A AC de TESTE vem do pdftool (o OpenSSL do PHP no Windows não gera chaves sem openssl.cnf).
    govbrBoot($this);

    try {
        $root = govbrCertificate($this->work, 'Raiz de Teste')['ca'];
        $pem = (string) file_get_contents($root);
        $fingerprint = strtolower((string) openssl_x509_fingerprint((string) $pem, 'sha256'));
        $der = $this->work.DIRECTORY_SEPARATOR.'raiz.der';
        preg_match('/-----BEGIN CERTIFICATE-----(.+?)-----END CERTIFICATE-----/s', $pem, $match);
        file_put_contents($der, base64_decode((string) preg_replace('/\s+/', '', $match[1])));

        expect(GovBrTrustAnchors::fingerprintsOfFile($root))->toBe([$fingerprint])
            ->and(GovBrTrustAnchors::fingerprintsOfFile($der))->toBe([$fingerprint])
            ->and(GovBrTrustAnchors::fingerprintsOfFile($this->work.DIRECTORY_SEPARATOR.'nao-existe.pem'))->toBe([]);

        config()->set('assinavelox.govbr.trust_roots', []);
        expect(app(GovBrTrustAnchors::class)->configured())->toBeFalse()
            ->and(app(GovBrTrustAnchors::class)->verifiedPaths())->toBe([]);

        // Impressão digital com ":" e maiúsculas (como os visualizadores de certificado mostram).
        config()->set('assinavelox.govbr.trust_roots', [$root]);
        config()->set('assinavelox.govbr.trust_root_fingerprints', [implode(':', str_split(strtoupper($fingerprint), 2))]);
        expect(app(GovBrTrustAnchors::class)->verifiedPaths())->toBe([$root]);

        config()->set('assinavelox.govbr.trust_root_fingerprints', []);
        expect(fn () => app(GovBrTrustAnchors::class)->verifiedPaths())->toThrow(GovBrReturnException::class);

        config()->set('assinavelox.govbr.trust_root_fingerprints', [str_repeat('0', 64)]);
        expect(fn () => app(GovBrTrustAnchors::class)->verifiedPaths())->toThrow(GovBrReturnException::class);
    } finally {
        govbrTeardown($this);
    }
});

it('a flag nasce desligada e exige interruptor global, trava de integração e plano', function () {
    ['organization' => $organization] = createOrganizationWithOwner(['name' => 'Horizonte']);

    expect(config('assinavelox.govbr.return_enabled'))->toBeFalse()
        ->and(config('assinavelox.govbr.finalizer_integration'))->toBeFalse()
        ->and(config('assinavelox.govbr.api.provider'))->toBe('none')
        ->and(GovBrReturnFeature::enabledFor($organization))->toBeFalse();

    config()->set('assinavelox.govbr.return_enabled', true);
    expect(GovBrReturnFeature::enabledFor($organization))->toBeFalse();

    config()->set('assinavelox.govbr.finalizer_integration', true);
    expect(GovBrReturnFeature::enabledFor($organization))->toBeFalse();
});

it('a API direta é só contrato (classe C): nenhuma implementação existe nem está registrada', function () {
    $implementations = array_filter(get_declared_classes(), fn (string $class): bool => in_array(GovBrSignatureProvider::class, class_implements($class) ?: [], true));

    expect(GovBrSignatureProvider::AVAILABILITY)->toBe('blocked_class_c')
        ->and(app()->bound(GovBrSignatureProvider::class))->toBeFalse()
        ->and($implementations)->toBe([])
        ->and(glob(app_path('Integrations/GovBr/*.php')))->toBe([app_path('Integrations/GovBr/GovBrSignatureProvider.php')]);
});

it('as tabelas novas existem (migrations aditivas)', function () {
    expect(Schema::hasTable('external_signature_requests'))->toBeTrue()
        ->and(Schema::hasColumns('external_signature_requests', ['recipient_id', 'provider', 'expected_revision_sha256', 'status', 'expires_at']))->toBeTrue()
        ->and(Schema::hasTable('external_signature_returns'))->toBeTrue();
});

describe('pontos de encaixe na finalização (GovBrReturnStage)', function () {
    beforeEach(function () {
        ['organization' => $this->organization, 'owner' => $owner] = createOrganizationWithOwner(['name' => 'Horizonte']);
        $this->envelope = Envelope::factory()->forOrganization($this->organization, $owner)->create(['status' => EnvelopeStatus::Finalizing]);
        $this->document = Document::factory()->forEnvelope($this->envelope)->create();
        $this->recipient = Recipient::factory()->forEnvelope($this->envelope, 1)->create();
        $this->row = fn (array $attributes = []): ExternalSignatureRequest => tap(new ExternalSignatureRequest, fn (ExternalSignatureRequest $row) => $row->forceFill($attributes + [
            'organization_id' => $this->organization->getKey(),
            'envelope_id' => $this->envelope->getKey(),
            'recipient_id' => $this->recipient->getKey(),
            'document_id' => $this->document->getKey(),
        ])->save());
    });

    it('sem pedidos, nada muda: não está ativo e não espera', function () {
        $stage = app(GovBrReturnStage::class);

        expect($stage->activeFor($this->envelope))->toBeFalse()
            ->and($stage->awaiting($this->envelope))->toBeFalse()
            ->and($stage->signatureCount($this->document))->toBe(0);
    });

    it('espera pelos pedidos no prazo, devolve a reserva vencida e vence o prazo total', function () {
        $stage = app(GovBrReturnStage::class);
        $row = ($this->row)([
            'status' => ExternalSignatureRequestStatus::Pending,
            'expected_revision_sha256' => str_repeat('a', 64),
            'reserved_at' => now()->subHours(3),
            'expires_at' => now()->subHour(),
        ]);

        expect($stage->activeFor($this->envelope))->toBeTrue()
            ->and($stage->awaiting($this->envelope))->toBeTrue();

        $row->refresh();
        expect($row->status)->toBe(ExternalSignatureRequestStatus::Requested)
            ->and($row->failure_code)->toBe('reservation_expired')
            ->and($row->expected_revision_sha256)->toBeNull()
            ->and($row->window_expires_at)->not->toBeNull();

        $this->travel($stage->windowMinutes() + 1)->minutes();

        expect($stage->awaiting($this->envelope))->toBeFalse()
            ->and($row->refresh()->status)->toBe(ExternalSignatureRequestStatus::Expired);
    });

    it('conta as devoluções aceitas, reabre quando a base é refeita e encerra com o envelope', function () {
        $stage = app(GovBrReturnStage::class);
        $version = DocumentVersion::factory()->forDocument($this->document)->create(['version_number' => 2]);
        $done = ($this->row)([
            'status' => ExternalSignatureRequestStatus::Completed,
            'signed_document_version_id' => $version->getKey(),
            'signature_kind' => GovBrSignatureKind::ParticipantExternalUnverified,
            'completed_at' => now(),
        ]);

        expect($stage->signatureCount($this->document))->toBe(1)
            ->and($stage->kindsFor($this->document))->toBe([GovBrSignatureKind::ParticipantExternalUnverified]);

        expect($stage->resetDocument($this->document))->toBe(1)
            ->and($done->refresh()->status)->toBe(ExternalSignatureRequestStatus::Requested)
            ->and($done->signed_document_version_id)->toBeNull()
            ->and($stage->closeFor($this->envelope))->toBe(1)
            ->and($done->refresh()->status)->toBe(ExternalSignatureRequestStatus::Closed);
    });
});
