<?php

use App\Models\Organization;
use App\Models\User;
use App\Services\Branding\BrandingManager;
use App\Services\Branding\BrandingPresenter;
use App\Services\Documents\DocumentStorage;
use App\Services\Envelopes\Sending\SendEnvelope;
use App\Services\Signing\SignerPageProps;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/Support/BrandingHelpers.php';
require_once __DIR__.'/../../Sending/Support/SendingHelpers.php';

/*
|--------------------------------------------------------------------------
| Isolamento: a marca de uma organização nunca aparece para outra
|--------------------------------------------------------------------------
| As duas organizações dividem o plano Grátis dos testes, então a flag está ligada para
| AMBAS: o isolamento vem da consulta por organização, não da flag.
*/

beforeEach(function () {
    Storage::fake(DocumentStorage::DISK);
    $this->withoutVite();
});

/**
 * @return array{0: Organization, 1: User, 2: Organization, 3: User}
 */
function brandingTwoOrganizations(): array
{
    ['organization' => $aurora, 'owner' => $auroraOwner] = brandingOrganization('Imobiliária Aurora', actAs: false);
    ['organization' => $vega, 'owner' => $vegaOwner] = brandingOrganization('Construtora Vega', actAs: false);

    $manager = app(BrandingManager::class);
    $manager->update($aurora, $auroraOwner, [
        'display_name' => 'Aurora Imóveis',
        'primary_color' => '#0B3D91',
        'reply_to_email' => 'contato@aurora.com.br',
    ]);

    $path = tempnam(sys_get_temp_dir(), 'logo');
    file_put_contents($path, brandingPng());
    $manager->replaceLogo($aurora, $auroraOwner, $path);
    @unlink($path);

    return [$aurora, $auroraOwner, $vega, $vegaOwner];
}

test('as superfícies da outra organização não recebem a marca', function () {
    [$aurora, , $vega] = brandingTwoOrganizations();
    $presenter = app(BrandingPresenter::class);

    expect($presenter->forSigner($aurora)['display_name'])->toBe('Aurora Imóveis')
        ->and($presenter->forSigner($vega))->toBeNull()
        ->and($presenter->forEmail($vega))->toBeNull()
        ->and($presenter->forEvidence($vega))->toBeNull()
        ->and($presenter->logoUrl($vega))->toBeNull();
});

test('a tela de marca mostra só a marca da organização corrente', function () {
    [$aurora, , $vega, $vegaOwner] = brandingTwoOrganizations();

    actingAsMember($vegaOwner, $vega);

    $props = $this->get(route('settings.branding'))->assertOk()->viewData('page')['props'];

    expect($props['organization_name'])->toBe('Construtora Vega')
        ->and($props['branding']['display_name'])->toBeNull()
        ->and($props['branding']['logo'])->toBeNull()
        ->and($props['branding']['reply_to_email'])->toBeNull()
        ->and(json_encode($props))->not->toContain('Aurora');

    // Escrever na tela da Vega nunca toca a linha da Aurora (a rota não tem id: é sempre a
    // organização corrente).
    $this->patch(route('settings.branding.update'), ['display_name' => 'Vega Obras'])->assertSessionHasNoErrors();

    $manager = app(BrandingManager::class);

    expect($manager->find($vega)->display_name)->toBe('Vega Obras')
        ->and($manager->find($aurora)->display_name)->toBe('Aurora Imóveis');

    $this->delete(route('settings.branding.logo.destroy'))->assertSessionHas('success');

    expect($manager->find($aurora)->hasLogo())->toBeTrue();
});

test('o convite da outra organização sai sem a marca, sem Reply-To e sem o logo', function () {
    [$aurora, , $vega, $vegaOwner] = brandingTwoOrganizations();
    $provider = fakeEmailProvider();

    $envelope = readyEnvelope($vega, $vegaOwner, [['name' => 'Rita Moura', 'email' => 'rita@exemplo.com']]);
    app(SendEnvelope::class)->handle($envelope);

    $email = $provider->sent[0];
    $token = app(BrandingManager::class)->find($aurora)->logo_token;

    expect($email->replyToAddress)->toBeNull()
        ->and($email->htmlBody)->not->toContain('Aurora')
        ->and($email->htmlBody)->not->toContain($token)
        ->and($email->htmlBody)->not->toContain('via AssinaVelox');
});

test('a página pública inválida (sem organização) nunca carrega marca', function () {
    brandingTwoOrganizations();

    $sender = SignerPageProps::invalid()['sender'];

    expect($sender['logo_url'])->toBeNull()
        ->and($sender)->not->toHaveKey('brand');
});
