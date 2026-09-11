<?php

use App\Models\Organization;
use App\Models\OrganizationBranding;
use App\Models\User;
use App\Services\Branding\BrandingManager;
use App\Services\Branding\BrandingPresenter;
use App\Services\Documents\DocumentStorage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/Support/BrandingHelpers.php';
require_once __DIR__.'/../../Sign/Support/SignerHelpers.php';

/*
|--------------------------------------------------------------------------
| Página pública do signatário: marca só com a flag, logo servido sem identificar ninguém
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    Storage::fake(DocumentStorage::DISK);
    $this->withoutVite();
});

function brandingWithLogo(Organization $organization, ?User $user = null): OrganizationBranding
{
    $manager = app(BrandingManager::class);
    $manager->update($organization, $user, ['display_name' => 'Aurora Imóveis', 'primary_color' => '#0B3D91', 'accent_color' => '#2F80ED']);

    $path = tempnam(sys_get_temp_dir(), 'logo');
    file_put_contents($path, brandingPng());
    $branding = $manager->replaceLogo($organization, $user, $path);
    @unlink($path);

    return $branding;
}

test('props da página pública: marca só com a flag ligada', function () {
    ['organization' => $organization, 'owner' => $owner] = brandingOrganization(enabled: false, actAs: false);
    $branding = brandingWithLogo($organization, $owner);
    $presenter = app(BrandingPresenter::class);

    expect($presenter->forSigner($organization))->toBeNull();

    brandingEnable($organization);

    expect($presenter->forSigner($organization))->toBe([
        'display_name' => 'Aurora Imóveis',
        'logo_url' => route('branding.logo', ['v' => $branding->logo_token]),
        'primary_color' => '#0B3D91',
        'accent_color' => '#2F80ED',
        'on_primary' => '#FFFFFF',
    ]);
});

test('o logo é servido pelo token opaco, com cache longo e sem farejamento de tipo', function () {
    ['organization' => $organization, 'owner' => $owner] = brandingOrganization(actAs: false);
    $branding = brandingWithLogo($organization, $owner);

    $response = $this->get(route('branding.logo', ['v' => $branding->logo_token]))->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('image/png')
        ->and($response->headers->get('Cache-Control'))->toContain('immutable')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->getContent())->toBe(Storage::disk(DocumentStorage::DISK)->get($branding->logo_path));
});

test('token desconhecido, malformado, removido ou flag desligada: o mesmo PNG transparente', function () {
    ['organization' => $organization, 'owner' => $owner] = brandingOrganization(actAs: false);
    $branding = brandingWithLogo($organization, $owner);
    $token = $branding->logo_token;

    $transparent = function (TestResponse $response): void {
        $response->assertOk();
        $size = getimagesizefromstring((string) $response->getContent());

        expect([$size[0], $size[1]])->toBe([1, 1])
            ->and($response->headers->get('Cache-Control'))->toContain('no-store');
    };

    $transparent($this->get(route('branding.logo', ['v' => str_repeat('a', 40)])));
    $transparent($this->get(route('branding.logo', ['v' => '../../etc/passwd'])));
    $transparent($this->get(route('branding.logo')));

    config()->set('assinavelox.features.branding', false);
    $transparent($this->get(route('branding.logo', ['v' => $token])));

    config()->set('assinavelox.features.branding', true);
    app(BrandingManager::class)->removeLogo($organization, $owner);
    $transparent($this->get(route('branding.logo', ['v' => $token])));
});

test('a página pública do signatário recebe a marca em sender.brand (flag ligada)', function () {
    ['organization' => $organization, 'owner' => $owner] = brandingOrganization(actAs: false);
    $branding = brandingWithLogo($organization, $owner);
    $ctx = signerEnvelope(organization: $organization, owner: $owner);

    $sender = $this->get(route('sign.show', ['token' => $ctx['tokens']['maria@exemplo.test']]))
        ->assertOk()
        ->viewData('page')['props']['sender'];

    expect($sender['logo_url'])->toBe(route('branding.logo', ['v' => $branding->logo_token]))
        ->and($sender['brand']['display_name'])->toBe('Aurora Imóveis');

    brandingEnable($organization, false);

    $sender = $this->get(route('sign.show', ['token' => $ctx['tokens']['maria@exemplo.test']]))
        ->viewData('page')['props']['sender'];

    expect($sender['logo_url'])->toBeNull()
        ->and($sender['brand'] ?? null)->toBeNull();
});
