<?php

use App\Enums\SignatureStatus;
use App\Models\DocumentVersion;
use App\Models\SignatureAcceptance;
use App\Services\Envelopes\Finalization\EvidenceData;
use App\Services\Signing\ConsentText;
use Illuminate\Support\Facades\File;

require_once __DIR__.'/Support/I18nHelpers.php';

/*
|--------------------------------------------------------------------------
| F-I18N — o aceite guarda o idioma exibido E o texto de referência (multilingue.md §6)
|--------------------------------------------------------------------------
| A página pode ter sido lida em inglês ou espanhol, mas o que o servidor confere, grava em
| `consent_statement` e leva ao PDF de evidências é o texto de REFERÊNCIA em PT-BR. O idioma
| exibido fica em `signature_acceptances.display_locale` e numa linha do PDF (que é PT-BR).
*/

beforeEach(function () {
    $this->work = i18nWorkspace();
    $this->withoutVite();
    $this->codes = signerCaptureCodes();
});

afterEach(fn () => File::deleteDirectory($this->work ?? ''));

/**
 * @return array{acceptance: SignatureAcceptance, scenario: array<string, mixed>, props: array<string, mixed>}
 */
function i18nAccept(object $test, string $locale, bool $flag): array
{
    $scenario = i18nSignerScenario($locale, $flag);
    $props = authenticateSigner($test, $scenario['token']);

    $test->post(route('sign.complete', ['token' => $scenario['token']]), [
        'authorization' => $props['authorization']['token'],
        'consent' => true,
        'signature' => ['method' => 'draw', 'image_base64' => pngDataUri()],
    ])->assertRedirect(route('sign.show', ['token' => $scenario['token']]));

    $acceptance = SignatureAcceptance::withoutOrganizationScope()
        ->where('recipient_id', $scenario['recipient']->id)
        ->sole();

    return ['acceptance' => $acceptance, 'scenario' => $scenario, 'props' => $props];
}

it('grava o idioma exibido e a declaração de referência em PT-BR', function () {
    ['acceptance' => $acceptance, 'scenario' => $scenario, 'props' => $props] = i18nAccept($this, 'en', true);

    $envelope = $scenario['envelope']->fresh();

    expect($acceptance->display_locale)->toBe('en')
        ->and($acceptance->consent_statement)->toBe($props['consent']['statement'])
        ->and($acceptance->consent_statement)->toStartWith('Declaração de aceite eletrônico')
        ->and($acceptance->consent_statement)->not->toContain('I have read')
        ->and($acceptance->terms_version)->toBe(ConsentText::versionFor($envelope, $scenario['recipient']));

    // O comprovante sai no idioma da página; o que foi registrado não muda.
    $receipt = $this->get(route('sign.show', ['token' => $scenario['token']]))->viewData('page')['props'];

    expect($receipt['flash']['success'])->toBe('Acceptance recorded.')
        ->and($receipt['receipt']['action_label'])->toBe('Electronic acceptance')
        ->and($receipt['receipt']['auth_label'])->toBe('Code by email');
});

it('leva o idioma exibido para o PDF de evidências, que continua em PT-BR', function () {
    ['scenario' => $scenario] = i18nAccept($this, 'es', true);

    $envelope = $scenario['envelope']->fresh();
    $data = app(EvidenceData::class)->build($envelope, DocumentVersion::withoutOrganizationScope()->findOrFail($envelope->sent_document_version_id), ['original' => 'a', 'sent' => 'b', 'consolidated' => 'c'], SignatureStatus::None);
    $row = collect($data['participants'])->firstWhere('email', 'maria@exemplo.test');

    expect($row['display_locale_label'])->toContain('Español')
        ->and($row['display_locale_label'])->toContain('tradução de cortesia')
        ->and($row['display_locale_label'])->toContain('referência, em português');

    $html = view('evidence.page', ['evidence' => $data])->render();

    expect($html)->toContain('Idioma da página: Español');
});

it('não grava idioma e não acrescenta linha às evidências com a flag desligada', function () {
    ['acceptance' => $acceptance, 'scenario' => $scenario] = i18nAccept($this, 'en', false);

    $envelope = $scenario['envelope']->fresh();
    $data = app(EvidenceData::class)->build($envelope, DocumentVersion::withoutOrganizationScope()->findOrFail($envelope->sent_document_version_id), ['original' => 'a', 'sent' => 'b', 'consolidated' => 'c'], SignatureStatus::None);
    $row = collect($data['participants'])->firstWhere('email', 'maria@exemplo.test');

    expect($acceptance->display_locale)->toBeNull()
        ->and($row)->not->toHaveKey('display_locale_label');
});
