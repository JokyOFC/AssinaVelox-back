<?php

use App\Services\Branding\BrandingManager;
use App\Services\Branding\ColorContrast;
use App\Services\Documents\DocumentStorage;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/Support/BrandingHelpers.php';

/*
|--------------------------------------------------------------------------
| Cores da marca: formato e contraste mínimo (WCAG) — recusa no servidor
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    Storage::fake(DocumentStorage::DISK);
    $this->withoutVite();
});

test('a razão de contraste segue a fórmula da WCAG', function () {
    expect(ColorContrast::ratio('#FFFFFF', '#000000'))->toEqualWithDelta(21.0, 0.001)
        ->and(ColorContrast::ratio('#777777', '#FFFFFF'))->toEqualWithDelta(4.48, 0.01)
        ->and(ColorContrast::ratio('#1257C9', '#FFFFFF'))->toBeGreaterThan(4.5)
        ->and(ColorContrast::ratio('#FFD700', '#FFFFFF'))->toBeLessThan(2.0)
        // Arredonda para baixo: 4,48 nunca aparece como "4,5:1".
        ->and(ColorContrast::format(ColorContrast::ratio('#777777', '#FFFFFF')))->toBe('4,4:1')
        ->and(ColorContrast::primaryProblem('#777777'))->not->toBeNull()
        ->and(ColorContrast::primaryProblem('#0B3D91'))->toBeNull()
        ->and(ColorContrast::accentProblem('#E5E7EB'))->not->toBeNull()
        ->and(ColorContrast::accentProblem('#2F80ED'))->toBeNull();
});

test('só aceita #RRGGBB (ou #RGB) e normaliza em maiúsculas', function () {
    expect(ColorContrast::normalize('#abc'))->toBe('#AABBCC')
        ->and(ColorContrast::normalize('0b3d91'))->toBe('#0B3D91')
        ->and(ColorContrast::normalize('red'))->toBeNull()
        ->and(ColorContrast::normalize('rgb(0,0,0)'))->toBeNull()
        ->and(ColorContrast::normalize('#0B3D91AA'))->toBeNull()
        ->and(ColorContrast::normalize(' #12 '))->toBeNull();
});

test('cor primária ilegível com texto branco é recusada com a razão calculada', function () {
    ['organization' => $organization] = brandingOrganization();

    $this->from(route('settings.branding'))
        ->patch(route('settings.branding.update'), ['primary_color' => '#FFD700', 'accent_color' => '#1257C9'])
        ->assertRedirect(route('settings.branding'))
        ->assertSessionHasErrors(['primary_color' => 'Contraste insuficiente: o texto branco sobre #FFD700 fica com 1,4:1; o mínimo é 4,5:1. Escolha um tom mais escuro.']);

    expect(app(BrandingManager::class)->find($organization))->toBeNull();
});

test('cor de destaque apagada sobre o branco é recusada', function () {
    brandingOrganization();

    $this->from(route('settings.branding'))
        ->patch(route('settings.branding.update'), ['primary_color' => '#0B3D91', 'accent_color' => '#E5E7EB'])
        ->assertSessionHasErrors('accent_color');

    $errors = session('errors')->getBag('default');

    expect($errors->first('accent_color'))->toContain('Contraste insuficiente')
        ->and($errors->first('accent_color'))->toContain('o mínimo é 3,0:1')
        ->and($errors->has('primary_color'))->toBeFalse();
});

test('formato inválido de cor é recusado antes da conta de contraste', function () {
    brandingOrganization();

    $this->from(route('settings.branding'))
        ->patch(route('settings.branding.update'), ['primary_color' => 'azul'])
        ->assertSessionHasErrors(['primary_color' => 'Use uma cor no formato #RRGGBB (ex.: #1257C9).']);
});

test('combinação legível é salva normalizada, junto com nome e Reply-To', function () {
    ['organization' => $organization] = brandingOrganization();

    $this->from(route('settings.branding'))
        ->patch(route('settings.branding.update'), [
            'display_name' => "  Aurora \u{200B}Imóveis  ",
            'primary_color' => '#0b3d91',
            'accent_color' => '2f80ed',
            'reply_to_email' => 'Contato@Aurora.com.br',
        ])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Marca atualizada.');

    $branding = app(BrandingManager::class)->find($organization);

    expect($branding->display_name)->toBe('Aurora Imóveis')
        ->and($branding->primary_color)->toBe('#0B3D91')
        ->and($branding->accent_color)->toBe('#2F80ED')
        ->and($branding->reply_to_email)->toBe('contato@aurora.com.br');

    // String vazia volta ao padrão.
    $this->patch(route('settings.branding.update'), ['primary_color' => '', 'reply_to_email' => ''])->assertSessionHasNoErrors();

    expect($branding->fresh()->primary_color)->toBeNull()
        ->and($branding->fresh()->reply_to_email)->toBeNull()
        ->and($branding->fresh()->accent_color)->toBe('#2F80ED');
});

test('Reply-To inválido ou com quebra de linha (injeção de cabeçalho) é recusado', function () {
    ['organization' => $organization] = brandingOrganization();

    foreach (["contato@aurora.com.br\r\nBcc: alvo@exemplo.com", 'nao-e-email', 'a@b'] as $value) {
        $this->from(route('settings.branding'))
            ->patch(route('settings.branding.update'), ['reply_to_email' => $value])
            ->assertSessionHasErrors('reply_to_email');
    }

    expect(app(BrandingManager::class)->find($organization))->toBeNull();
});
