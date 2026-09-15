<?php

use App\Enums\AuthMethod;
use App\Enums\RecipientRole;
use App\Models\Envelope;
use App\Models\Organization;
use App\Models\Recipient;
use App\Services\Signing\ConsentText;
use App\Support\Locale\CourtesyLegalText;
use App\Support\Locale\SignerLocale;
use Illuminate\Support\Facades\File;

require_once __DIR__.'/Support/I18nHelpers.php';

/*
|--------------------------------------------------------------------------
| F-I18N — textos jurídicos: mesma estrutura em todos os idiomas (multilingue.md §6)
|--------------------------------------------------------------------------
| Os modelos de `lang/pt_BR/signer_legal.php` reproduzem, palavra por palavra, o que o
| ConsentText gera — é assim que se garante que en/es seguem a mesma estrutura (papel,
| quantidade de documentos, certificado, canal do código, PIN). O texto gravado continua
| sendo o do ConsentText; a tradução é de cortesia.
*/

beforeEach(function () {
    $this->work = i18nWorkspace();
    $this->withoutVite();
});

afterEach(fn () => File::deleteDirectory($this->work ?? ''));

/**
 * @return array{envelope: Envelope, recipient: Recipient, organization: Organization, sha: string}
 */
function legalScenario(RecipientRole $role = RecipientRole::Signer, AuthMethod $method = AuthMethod::EmailOtp): array
{
    $ctx = signerEnvelope([[
        'name' => 'Maria Alves Souza',
        'email' => 'maria@exemplo.test',
    ]]);

    $recipient = $ctx['recipients']['maria@exemplo.test'];
    $recipient->forceFill(['role' => $role, 'auth_method' => $method, 'phone' => '+5511912345678'])->save();

    return [
        'envelope' => $ctx['envelope'],
        'recipient' => $recipient->fresh(),
        'organization' => $ctx['organization'],
        'sha' => (string) $ctx['version']->sha256,
    ];
}

it('reproduz em PT-BR a declaração do ConsentText (signatário, com e sem certificado)', function (bool $certificate) {
    ['envelope' => $envelope, 'recipient' => $recipient, 'organization' => $organization, 'sha' => $sha] = legalScenario();

    expect(CourtesyLegalText::statement(SignerLocale::PtBr, $envelope, $recipient, $organization, $sha, $certificate))
        ->toBe(ConsentText::statement($envelope, $recipient, $organization, $sha, $certificate));
})->with([true, false]);

it('reproduz em PT-BR as variantes de testemunha e aprovador', function (RecipientRole $role) {
    ['envelope' => $envelope, 'recipient' => $recipient, 'organization' => $organization, 'sha' => $sha] = legalScenario($role);

    expect(CourtesyLegalText::statement(SignerLocale::PtBr, $envelope, $recipient, $organization, $sha, false))
        ->toBe(ConsentText::statement($envelope, $recipient, $organization, $sha, false))
        ->and(CourtesyLegalText::checkboxLabel(SignerLocale::PtBr, $envelope, $recipient))
        ->toBe(ConsentText::checkboxLabel($envelope, $recipient))
        ->and(CourtesyLegalText::privacySummary(SignerLocale::PtBr, $organization, $role, $recipient))
        ->toBe(ConsentText::privacySummary($organization, $role, $recipient))
        ->and(CourtesyLegalText::privacyNotice(SignerLocale::PtBr, $organization, $role, $recipient))
        ->toBe(ConsentText::privacyNotice($organization, $role, $recipient));
})->with([RecipientRole::Witness, RecipientRole::Approver]);

it('reproduz em PT-BR o aviso de privacidade de quem só acompanha', function () {
    ['recipient' => $recipient, 'organization' => $organization] = legalScenario(RecipientRole::Viewer);

    expect(CourtesyLegalText::privacyNotice(SignerLocale::PtBr, $organization, RecipientRole::Viewer, $recipient))
        ->toBe(ConsentText::privacyNotice($organization, RecipientRole::Viewer, $recipient));
});

it('reproduz em PT-BR os complementos de quem confirma o código por SMS', function () {
    config()->set('assinavelox.features.sms_whatsapp', true);
    ['envelope' => $envelope, 'recipient' => $recipient, 'organization' => $organization, 'sha' => $sha] = legalScenario(RecipientRole::Signer, AuthMethod::SmsOtp);

    expect(CourtesyLegalText::statement(SignerLocale::PtBr, $envelope, $recipient, $organization, $sha, false))
        ->toBe(ConsentText::statement($envelope, $recipient, $organization, $sha, false))
        ->and(CourtesyLegalText::checkboxLabel(SignerLocale::PtBr, $envelope, $recipient))
        ->toBe(ConsentText::checkboxLabel($envelope, $recipient))
        ->and(CourtesyLegalText::privacyNotice(SignerLocale::PtBr, $organization, RecipientRole::Signer, $recipient))
        ->toBe(ConsentText::privacyNotice($organization, RecipientRole::Signer, $recipient));
});

it('reproduz em PT-BR os avisos de conclusão', function (bool $certificate, bool $participant) {
    expect(CourtesyLegalText::completionNotice(SignerLocale::PtBr, $certificate, $participant))
        ->toBe(ConsentText::completionNotice($certificate, $participant));
})->with([
    [true, false], [false, false], [true, true], [false, true],
]);

it('traduz a declaração sem deixar marcador por preencher nem texto em português', function (SignerLocale $locale) {
    ['envelope' => $envelope, 'recipient' => $recipient, 'organization' => $organization, 'sha' => $sha] = legalScenario();

    $statement = CourtesyLegalText::statement($locale, $envelope, $recipient, $organization, $sha, false);
    $notice = CourtesyLegalText::privacyNotice($locale, $organization, RecipientRole::Signer, $recipient);

    // O que é dado aparece como veio: título, nome, resumo do documento.
    expect($statement)->toContain('Contrato de locação')
        ->and($statement)->toContain('Maria Alves Souza')
        ->and($statement)->toContain($sha)
        ->and($statement)->toContain(ConsentText::versionFor($envelope, $recipient))
        ->and($statement.$notice)->not->toMatch('/:[a-z_]+\b/')
        ->and($statement.$notice)->not->toContain('Declaração')
        ->and($statement.$notice)->not->toContain('aceite eletrônico')
        ->and($notice)->not->toContain('Como seus dados');
})->with([SignerLocale::En, SignerLocale::Es]);
