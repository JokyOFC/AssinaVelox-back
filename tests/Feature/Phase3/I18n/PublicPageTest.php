<?php

use App\Enums\AuditEventType;
use App\Models\AuditEvent;
use App\Services\Signing\ConsentText;
use Illuminate\Support\Facades\File;

require_once __DIR__.'/Support/I18nHelpers.php';

/*
|--------------------------------------------------------------------------
| F-I18N — página pública no idioma do participante (docs/fase-3/multilingue.md §4 e §5)
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->work = i18nWorkspace();
    $this->withoutVite();
    $this->codes = signerCaptureCodes();
});

afterEach(fn () => File::deleteDirectory($this->work ?? ''));

/**
 * @return array<string, mixed>
 */
function i18nProps(object $test, string $token): array
{
    return $test->get(route('sign.show', ['token' => $token]))->assertOk()->viewData('page')['props'];
}

it('mostra a tela de identificação em inglês, com o original do aviso a um clique', function () {
    ['token' => $token] = i18nSignerScenario('en');

    $props = i18nProps($this, $token);

    expect($props['i18n'])->toMatchArray([
        'locale' => 'en',
        'reference_locale' => 'pt_BR',
        'switch_url' => route('sign.locale.update', ['token' => $token]),
        'legal_reviewed' => false,
    ])
        ->and($props['action'])->toMatchArray(['label' => 'Electronic acceptance', 'button_label' => 'Sign document'])
        ->and($props['recipient']['participant_role_label'])->toBe('Signer')
        ->and($props['privacy']['summary'])->toStartWith('This document was sent by')
        ->and($props['privacy']['notice'])->toStartWith('How your data is used on this page')
        ->and($props['privacy']['reference']['summary'])->toStartWith('Este documento foi enviado por')
        // A versão do aviso continua a do texto de referência.
        ->and($props['privacy']['version'])->toBe(ConsentText::PRIVACY_NOTICE_VERSION);
});

it('mantém a declaração de referência e oferece a tradução de cortesia ao lado', function () {
    ['token' => $token] = i18nSignerScenario('es');

    $props = authenticateSigner($this, $token);

    expect($props['consent']['statement'])->toStartWith('Declaração de aceite eletrônico')
        ->and($props['consent_text'])->toBe($props['consent']['statement'])
        ->and($props['consent']['checkbox_label'])->toStartWith('Li o documento')
        ->and($props['consent']['translation'])->toMatchArray(['locale' => 'es', 'reviewed' => false])
        ->and($props['consent']['translation']['statement'])->toStartWith('Declaración de aceptación electrónica')
        ->and($props['consent']['translation']['checkbox_label'])->toStartWith('He leído el documento')
        ->and($props['consent']['completion_notice'])->toStartWith('Al final,');
});

it('traduz os avisos da sessão e as mensagens de erro do serviço', function () {
    ['token' => $token] = i18nSignerScenario('en');

    $this->post(route('sign.otp.send', ['token' => $token]))->assertRedirect();

    expect(i18nProps($this, $token)['flash']['info'])->toStartWith('We sent a code to ');

    $this->post(route('sign.otp.verify', ['token' => $token]), ['code' => '000000'])->assertRedirect();

    expect(((array) i18nProps($this, $token)['errors'])['code'])->toStartWith('Invalid or expired code (');
});

it('troca o idioma de exibição só nesta sessão e registra na trilha', function () {
    ['token' => $token, 'recipient' => $recipient] = i18nSignerScenario('en');

    $this->post(route('sign.locale.update', ['token' => $token]), ['locale' => 'es'])
        ->assertRedirect(route('sign.show', ['token' => $token]));

    $props = i18nProps($this, $token);

    expect($props['i18n']['locale'])->toBe('es')
        ->and($props['action']['button_label'])->toBe('Firmar documento')
        // O que o remetente registrou (idioma dos e-mails) não muda.
        ->and($recipient->fresh()->locale)->toBe('en');

    $event = AuditEvent::withoutOrganizationScope()->where('event_type', AuditEventType::RecipientDisplayLocaleChanged)->sole();

    expect($event->payload)->toBe(['from' => 'en', 'to' => 'es', 'stored' => 'en'])
        ->and($event->recipient_id)->toBe($recipient->id);
});

it('recusa idioma fora da lista fechada sem gravar nada', function () {
    ['token' => $token] = i18nSignerScenario('en');

    $this->post(route('sign.locale.update', ['token' => $token]), ['locale' => '../../lang/fr'])
        ->assertRedirect()
        ->assertSessionHasErrors('locale');

    expect(i18nProps($this, $token)['i18n']['locale'])->toBe('en')
        ->and(AuditEvent::withoutOrganizationScope()->where('event_type', AuditEventType::RecipientDisplayLocaleChanged)->count())->toBe(0);
});

it('com a flag ligada e o participante em PT-BR, só acrescenta o seletor', function () {
    ['token' => $token] = i18nSignerScenario('pt_BR');

    $props = i18nProps($this, $token);

    expect($props['i18n']['locale'])->toBe('pt_BR')
        ->and($props['action']['button_label'])->toBe('Assinar documento')
        ->and($props['privacy'])->not->toHaveKey('reference')
        ->and($props['privacy']['summary'])->toStartWith('Este documento foi enviado por');
});
