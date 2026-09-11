<?php

use App\Enums\MembershipRole;
use App\Services\Branding\BrandingManager;
use App\Services\Documents\DocumentStorage;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/Support/BrandingHelpers.php';

/*
|--------------------------------------------------------------------------
| Logo da organização: formatos, limites, metadados, troca e remoção (C-BRAND)
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    Storage::fake(DocumentStorage::DISK);
    $this->withoutVite();
});

test('recusa SVG, com a extensão certa ou disfarçado de PNG', function () {
    ['organization' => $organization] = brandingOrganization();

    foreach ([['logo.svg', 'image/svg+xml'], ['logo.png', 'image/png']] as [$name, $mime]) {
        $this->from(route('settings.branding'))
            ->post(route('settings.branding.logo.store'), ['logo' => brandingUpload(brandingSvg(), $name, $mime)])
            ->assertRedirect(route('settings.branding'))
            ->assertSessionHasErrors(['logo' => 'SVG não é aceito. Envie o logo em PNG ou JPEG.']);
    }

    expect(Storage::disk(DocumentStorage::DISK)->allFiles())->toBe([])
        ->and(app(BrandingManager::class)->find($organization))->toBeNull();
});

test('recusa arquivo que não é imagem e imagem em formato não aceito', function () {
    brandingOrganization();

    $this->from(route('settings.branding'))
        ->post(route('settings.branding.logo.store'), ['logo' => brandingUpload("%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer<<>>\n%%EOF", 'logo.png')])
        ->assertSessionHasErrors(['logo' => 'O arquivo enviado não é uma imagem. Envie o logo em PNG ou JPEG.']);

    $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==');

    $this->from(route('settings.branding'))
        ->post(route('settings.branding.logo.store'), ['logo' => brandingUpload($gif, 'logo.gif', 'image/gif')])
        ->assertSessionHasErrors(['logo' => 'Formato não aceito. Envie o logo em PNG ou JPEG.']);

    expect(Storage::disk(DocumentStorage::DISK)->allFiles())->toBe([]);
});

test('recusa imagem gigante (dimensões) e arquivo acima do tamanho máximo', function () {
    brandingOrganization();

    // Pouco peso em bytes, dimensões enormes: barrado pelo cabeçalho, antes de decodificar.
    $this->from(route('settings.branding'))
        ->post(route('settings.branding.logo.store'), ['logo' => brandingUpload(brandingPng(5000, 1200))])
        ->assertSessionHasErrors(['logo' => 'A imagem tem 5000×1200 px; o limite é 4000 px no maior lado.']);

    // Pequena demais para ser um logo legível.
    $this->from(route('settings.branding'))
        ->post(route('settings.branding.logo.store'), ['logo' => brandingUpload(brandingPng(20, 20))])
        ->assertSessionHasErrors(['logo' => 'A imagem precisa ter pelo menos 32 px de cada lado.']);

    config()->set('assinavelox.branding.logo_max_kb', 16);

    $this->from(route('settings.branding'))
        ->post(route('settings.branding.logo.store'), ['logo' => brandingUpload(brandingPngWithText('Comment', str_repeat('x', 20_000)))])
        ->assertSessionHasErrors(['logo' => 'O logo pode ter no máximo 16 KB.']);

    expect(Storage::disk(DocumentStorage::DISK)->allFiles())->toBe([]);
});

test('PNG é reencodado sem metadados e reduzido à caixa de saída', function () {
    ['organization' => $organization] = brandingOrganization();

    $this->from(route('settings.branding'))
        ->post(route('settings.branding.logo.store'), ['logo' => brandingUpload(brandingPngWithText('Author', 'SEGREDO-PNG-4821', 2000, 1000))])
        ->assertRedirect(route('settings.branding'))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Logo atualizado.');

    $branding = app(BrandingManager::class)->find($organization);
    $stored = Storage::disk(DocumentStorage::DISK)->get($branding->logo_path);

    expect($stored)->toStartWith("\x89PNG")
        ->and($stored)->not->toContain('SEGREDO-PNG-4821')
        ->and($stored)->not->toContain('tEXt')
        ->and($branding->logo_width)->toBe(800)
        ->and($branding->logo_height)->toBe(400)
        ->and($branding->logo_sha256)->toBe(hash('sha256', $stored))
        ->and($branding->logo_token)->toMatch('/^[a-f0-9]{40}$/')
        ->and($branding->logo_path)->toBe('orgs/'.$organization->ulid.'/branding/logo-'.$branding->logo_token.'.png');

    // Nome original do arquivo nunca entra no caminho.
    expect($branding->logo_path)->not->toContain('logo.png');
});

test('JPEG com EXIF e comentário vira PNG sem nenhum dos dois', function () {
    ['organization' => $organization] = brandingOrganization();

    $jpeg = brandingJpegWithMetadata('SEGREDO-JPEG-7310');
    expect($jpeg)->toContain('Exif')->and($jpeg)->toContain('SEGREDO-JPEG-7310');

    $this->from(route('settings.branding'))
        ->post(route('settings.branding.logo.store'), ['logo' => brandingUpload($jpeg, 'foto.jpg', 'image/jpeg')])
        ->assertSessionHasNoErrors();

    $branding = app(BrandingManager::class)->find($organization);
    $stored = Storage::disk(DocumentStorage::DISK)->get($branding->logo_path);

    expect($stored)->toStartWith("\x89PNG")
        ->and($stored)->not->toContain('Exif')
        ->and($stored)->not->toContain('SEGREDO-JPEG-7310')
        ->and([$branding->logo_width, $branding->logo_height])->toBe([600, 300]);
});

test('trocar o logo muda o token e apaga o arquivo anterior; remover apaga o arquivo', function () {
    ['organization' => $organization] = brandingOrganization();
    $manager = app(BrandingManager::class);

    $this->post(route('settings.branding.logo.store'), ['logo' => brandingUpload(brandingPng())])->assertSessionHasNoErrors();
    $first = $manager->find($organization);

    $this->post(route('settings.branding.logo.store'), ['logo' => brandingUpload(brandingPng(500, 250))])->assertSessionHasNoErrors();
    $second = $manager->find($organization);

    $disk = Storage::disk(DocumentStorage::DISK);

    expect($second->logo_token)->not->toBe($first->logo_token)
        ->and($disk->exists($first->logo_path))->toBeFalse()
        ->and($disk->exists($second->logo_path))->toBeTrue()
        ->and($disk->allFiles())->toHaveCount(1);

    $this->delete(route('settings.branding.logo.destroy'))->assertSessionHas('success', 'Logo removido.');

    expect($manager->find($organization)->logo_path)->toBeNull()
        ->and($manager->find($organization)->logo_token)->toBeNull()
        ->and($disk->allFiles())->toBe([]);
});

test('com a flag desligada a tela mostra o estado Fase 2 e as escritas respondem 403', function () {
    ['organization' => $organization] = brandingOrganization(enabled: false);

    $page = $this->get(route('settings.branding'))->assertOk()->viewData('page');

    expect($page['component'])->toBe('settings/branding')
        ->and($page['props']['enabled'])->toBeFalse()
        ->and($page['props']['features']['branding'] ?? false)->toBeFalse();

    $this->post(route('settings.branding.logo.store'), ['logo' => brandingUpload(brandingPng())])->assertForbidden();
    $this->patch(route('settings.branding.update'), ['primary_color' => '#0B3D91'])->assertForbidden();
    $this->delete(route('settings.branding.logo.destroy'))->assertForbidden();

    expect(app(BrandingManager::class)->find($organization))->toBeNull()
        ->and(Storage::disk(DocumentStorage::DISK)->allFiles())->toBe([]);
});

test('só owner e admin configuram a marca', function () {
    ['organization' => $organization] = brandingOrganization(actAs: false);

    $member = attachMember($organization, MembershipRole::Member);
    actingAsMember($member, $organization);

    $this->get(route('settings.branding'))->assertForbidden();
    $this->post(route('settings.branding.logo.store'), ['logo' => brandingUpload(brandingPng())])->assertForbidden();
    $this->patch(route('settings.branding.update'), ['display_name' => 'Outro nome'])->assertForbidden();

    $admin = attachMember($organization, MembershipRole::Admin);
    actingAsMember($admin, $organization);

    $this->patch(route('settings.branding.update'), ['display_name' => 'Aurora Imóveis'])->assertSessionHasNoErrors();

    expect(app(BrandingManager::class)->find($organization)->display_name)->toBe('Aurora Imóveis');
});
