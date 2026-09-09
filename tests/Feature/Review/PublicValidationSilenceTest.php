<?php

use App\Enums\EnvelopeStatus;
use App\Models\CertificateReference;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/../Verification/Support/VerificationHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão de design — a página pública afirma "validada" sem validação
|--------------------------------------------------------------------------
| `SignatureNarrative` documenta a regra da casa: "Um resultado ausente,
| incompleto ou inconclusivo NUNCA vira sucesso", e produz o fato negativo
| explícito em `validation.integrity_label` ("A assinatura foi aplicada, mas
| nenhum resultado de validação foi registrado na conclusão. A integridade não
| pode ser afirmada a partir desta página.").
|
| A página pública desfaz as duas metades da regra:
|
|  1. `PublicVerification::milestones()` cria o marco "Assinatura criptográfica
|     da operadora aplicada E VALIDADA" só porque `validated_at` não é nulo, sem
|     olhar uma única vez para o resultado; e
|  2. só `validation.summary` chega ao componente que escreve o resultado
|     técnico (`verify/show.tsx` → `SignatureStatement validationSummary`), e
|     `summary` é `null` exatamente no caso inconclusivo. O fato negativo, que a
|     prop `validation` carrega, não é renderizado por ninguém.
|
| Observado no navegador em /verificar/EBPT-XD2P-XWME (envelope do seeder, com
| `validation_result.result = null` e `reason = demo_seed_not_validated`): a
| linha do tempo diz "aplicada e validada" e a página não traz uma palavra sobre
| a validação. A página interna de evidências, com os mesmos dados, diz a verdade
| — só o terceiro que consulta pelo código não a vê.
|
| O formato aqui é o de `OperatorSignature::validationPayload()` (envelope com
| `result` dentro), que é o que a finalização e o seeder gravam.
*/

beforeEach(fn () => $this->withoutVite());

/**
 * @return array<string, mixed>
 */
function inconclusiveValidationPayload(): array
{
    return [
        'signed' => true,
        'profile' => 'PAdES-B-B',
        'environment' => 'test',
        'validated_at' => now()->toIso8601String(),
        'timestamp' => null,
        'long_term_validation' => false,
        'revocation' => 'not_checked',
        'reason' => 'demo_seed_not_validated',
        'result' => null,
    ];
}

it('não anuncia "validada" quando nenhum resultado de validação foi registrado', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $certificate = CertificateReference::factory()->active()->create();

    $envelope = verifiableEnvelope($organization, $owner, EnvelopeStatus::Completed);
    finalizeEnvelope($envelope, 'company_a1', $certificate, inconclusiveValidationPayload());

    $result = verifyProps($this->get(route('verify.show', ['code' => $envelope->verification_code])))['result'];

    // Controle: a narrativa sabe que não há o que afirmar.
    expect($result['validation']['integrity'])->toBe('unknown')
        ->and($result['validation']['summary'])->toBeNull();

    $labels = implode(' | ', array_column($result['events_summary'], 'label'));

    expect($labels)->not->toContain('validada');
});

it('continua anunciando "validada" quando a validação de fato confirmou', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $certificate = CertificateReference::factory()->active()->create();

    $envelope = verifiableEnvelope($organization, $owner, EnvelopeStatus::Completed);
    finalizeEnvelope($envelope, 'company_a1', $certificate, [
        ...inconclusiveValidationPayload(),
        'reason' => null,
        'result' => validationSummary(intact: true),
    ]);

    $result = verifyProps($this->get(route('verify.show', ['code' => $envelope->verification_code])))['result'];
    $labels = implode(' | ', array_column($result['events_summary'], 'label'));

    expect($result['validation']['integrity'])->toBe('intact')
        ->and($labels)->toContain('validada');
});

it('a página pública renderiza o fato negativo da validação, não só o resumo', function () {
    $publicPage = (string) file_get_contents(resource_path('js/pages/verify/show.tsx'));
    $evidencePage = (string) file_get_contents(resource_path('js/pages/envelopes/evidence.tsx'));

    // Controle: a página interna monta `<ValidationDetails validation={validation} />`,
    // que é quem escreve integrity_label / chain_trust_label / revocation_label.
    expect($evidencePage)->toContain('<ValidationDetails');

    // A pública declara a prop `validation` e importa só o TIPO
    // `VerificationValidation`; o componente nunca é montado, então o fato negativo
    // trafega até o navegador e não é exibido a ninguém.
    expect($publicPage)->toContain('<ValidationDetails');
});
