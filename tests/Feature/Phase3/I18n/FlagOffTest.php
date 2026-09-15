<?php

use App\Enums\AuditEventType;
use App\Models\AuditEvent;
use Illuminate\Support\Facades\File;

require_once __DIR__.'/Support/I18nHelpers.php';

/*
|--------------------------------------------------------------------------
| F-I18N — com a flag `multilingual` desligada, nada muda (roadmap T8)
|--------------------------------------------------------------------------
| Mesmo que o participante tenha um idioma gravado (a flag foi desligada depois), a página e
| as mensagens continuam em PT-BR, nenhuma prop nova sai e a troca de idioma responde 404.
*/

beforeEach(function () {
    $this->work = i18nWorkspace();
    $this->withoutVite();
    $this->codes = signerCaptureCodes();
});

afterEach(fn () => File::deleteDirectory($this->work ?? ''));

it('mantém a página pública exatamente como antes', function () {
    ['token' => $token] = i18nSignerScenario('en', flag: false);

    $props = $this->get(route('sign.show', ['token' => $token]))->assertOk()->viewData('page')['props'];

    expect($props)->not->toHaveKey('i18n')
        ->and($props['action'])->toMatchArray(['label' => 'Aceite eletrônico', 'button_label' => 'Assinar documento'])
        ->and($props['privacy'])->not->toHaveKey('reference')
        ->and($props['privacy']['summary'])->toStartWith('Este documento foi enviado por');

    $signing = authenticateSigner($this, $token);

    expect($signing['consent'])->not->toHaveKey('translation')
        ->and($signing)->not->toHaveKey('i18n');
});

it('não traduz avisos nem erros', function () {
    ['token' => $token] = i18nSignerScenario('es', flag: false);

    $this->post(route('sign.otp.send', ['token' => $token]))->assertRedirect();
    $this->post(route('sign.otp.verify', ['token' => $token]), ['code' => '000000'])->assertRedirect();

    $props = $this->get(route('sign.show', ['token' => $token]))->viewData('page')['props'];

    expect(((array) $props['errors'])['code'])->toStartWith('Código inválido ou expirado (');
});

it('não aceita a troca de idioma', function () {
    ['token' => $token] = i18nSignerScenario('en', flag: false);

    $this->post(route('sign.locale.update', ['token' => $token]), ['locale' => 'es'])->assertNotFound();

    expect(AuditEvent::withoutOrganizationScope()->where('event_type', AuditEventType::RecipientDisplayLocaleChanged)->count())->toBe(0);
});
