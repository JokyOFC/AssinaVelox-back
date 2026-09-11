<?php

use App\Integrations\Dto\OutboundEmail;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\Signing\SignerOtpNotification;
use App\Services\Branding\BrandingManager;
use App\Services\Branding\Contracts\VerifiedSenderDomains;
use App\Services\Documents\DocumentStorage;
use App\Services\Envelopes\Sending\SendEnvelope;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/Support/BrandingHelpers.php';
require_once __DIR__.'/../../Sending/Support/SendingHelpers.php';

/*
|--------------------------------------------------------------------------
| E-mails ao participante: marca e Reply-To só com a flag ligada
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    Storage::fake(DocumentStorage::DISK);
    $this->withoutVite();
});

/** Avisos do convite que precisam continuar no e-mail, com ou sem marca. */
const BRANDING_INVITATION_NOTICES = [
    'enviou o documento',
    'para você assinar eletronicamente',
    'Revisar e assinar',
    'Para confirmar que é você, vamos enviar um código de 6 dígitos para este mesmo e-mail.',
    'O prazo para assinar termina em',
    'Este link é pessoal e foi criado só para você — não encaminhe este e-mail.',
    'Atenciosamente, AssinaVelox',
];

function brandingSaveFull(Organization $organization, ?User $user = null): void
{
    $manager = app(BrandingManager::class);
    $manager->update($organization, $user, [
        'display_name' => 'Aurora Imóveis',
        'primary_color' => '#0B3D91',
        'accent_color' => '#2F80ED',
        'reply_to_email' => 'contato@aurora.com.br',
        'sender_email' => 'documentos@aurora.com.br',
    ]);

    $path = tempnam(sys_get_temp_dir(), 'logo');
    file_put_contents($path, brandingPng());
    $manager->replaceLogo($organization, $user, $path);
    @unlink($path);
}

function brandingSendInvitation(Organization $organization, User $owner): OutboundEmail
{
    $provider = fakeEmailProvider();
    $envelope = readyEnvelope($organization, $owner, [['name' => 'Maria Alves', 'email' => 'maria@exemplo.com']], envelopeAttributes: ['title' => 'Contrato de locação']);

    app(SendEnvelope::class)->handle($envelope);

    expect($provider->sent)->toHaveCount(1);

    return $provider->sent[0];
}

test('flag desligada: mesmo com marca salva, o convite é o da Fase 1', function () {
    ['organization' => $organization, 'owner' => $owner] = brandingOrganization(enabled: false, actAs: false);
    brandingSaveFull($organization, $owner);

    $email = brandingSendInvitation($organization, $owner);

    expect($email->replyToAddress)->toBeNull()
        ->and($email->fromAddress)->toBeNull()
        ->and($email->htmlBody)->not->toContain('via AssinaVelox')
        ->and($email->htmlBody)->not->toContain('Aurora Imóveis')
        ->and($email->htmlBody)->not->toContain('<img')
        ->and($email->htmlBody)->not->toContain('#0B3D91');

    foreach (BRANDING_INVITATION_NOTICES as $notice) {
        expect($email->htmlBody)->toContain($notice);
    }
});

test('flag ligada sem marca salva: nada muda', function () {
    ['organization' => $organization, 'owner' => $owner] = brandingOrganization(actAs: false);

    $email = brandingSendInvitation($organization, $owner);

    expect($email->replyToAddress)->toBeNull()
        ->and($email->htmlBody)->not->toContain('via AssinaVelox')
        ->and($email->htmlBody)->not->toContain('<img');
});

test('flag ligada: logo, nome, cor do botão e Reply-To; texto e avisos intactos', function () {
    ['organization' => $organization, 'owner' => $owner] = brandingOrganization(actAs: false);
    brandingSaveFull($organization, $owner);
    $branding = app(BrandingManager::class)->find($organization);

    $email = brandingSendInvitation($organization, $owner);
    $logoUrl = route('branding.logo', ['v' => $branding->logo_token]);

    expect($email->htmlBody)->toContain('Aurora Imóveis')
        ->and($email->htmlBody)->toContain('via AssinaVelox')
        ->and($email->htmlBody)->toContain('src="'.$logoUrl.'"')
        ->and($email->htmlBody)->toMatch('/background-color:\s*#0B3D91/i')
        ->and($email->htmlBody)->toMatch('#href="[^"]*/assinar/[A-Za-z0-9_-]{43}"#')
        ->and($email->replyToAddress)->toBe('contato@aurora.com.br')
        // Sem domínio verificado o remetente continua o da plataforma.
        ->and($email->fromAddress)->toBeNull()
        ->and($email->textBody)->toContain('Aurora Imóveis — via AssinaVelox')
        ->and($email->textBody)->toMatch('#Revisar e assinar: \S+/assinar/[A-Za-z0-9_-]{43}#');

    foreach (BRANDING_INVITATION_NOTICES as $notice) {
        expect($email->htmlBody)->toContain($notice);
    }

    // A única imagem é o logo, e a URL dele não carrega nada do destinatário nem da mensagem.
    preg_match_all('/<img[^>]+src="([^"]+)"/', $email->htmlBody, $images);

    expect($images[1])->toBe([$logoUrl])
        ->and($logoUrl)->not->toContain('maria')
        ->and($logoUrl)->not->toContain($email->correlationId)
        ->and($logoUrl)->not->toContain('assinar');
});

test('o código por e-mail com marca continua trazendo o código e os avisos', function () {
    ['organization' => $organization, 'owner' => $owner] = brandingOrganization(actAs: false);
    brandingSaveFull($organization, $owner);
    $provider = fakeEmailProvider();

    $envelope = readyEnvelope($organization, $owner);
    $recipient = $envelope->recipients()->firstOrFail();

    Notification::route('mail', $recipient->email)
        ->notify(new SignerOtpNotification($recipient, $envelope, '482913', 10, (string) Str::ulid()));

    $email = $provider->sent[0];

    expect($email->htmlBody)->toContain('482 913')
        ->and($email->htmlBody)->toContain('O código vale por 10 minutos e só pode ser usado uma vez.')
        ->and($email->htmlBody)->toContain('Se você não pediu este código, ignore esta mensagem: sem ele, nada é assinado.')
        ->and($email->htmlBody)->toContain('via AssinaVelox')
        ->and($email->subject)->not->toContain('482913')
        ->and($email->replyToAddress)->toBe('contato@aurora.com.br');
});

test('remetente próprio só com domínio verificado pelo contrato', function () {
    ['organization' => $organization, 'owner' => $owner] = brandingOrganization(actAs: false);
    brandingSaveFull($organization, $owner);

    app()->instance(VerifiedSenderDomains::class, new class implements VerifiedSenderDomains
    {
        public function isVerified(Organization $organization, string $domain): bool
        {
            return $domain === 'aurora.com.br';
        }
    });

    $email = brandingSendInvitation($organization, $owner);

    expect($email->fromAddress)->toBe('documentos@aurora.com.br')
        ->and($email->fromName)->toBe('Aurora Imóveis')
        ->and($email->replyToAddress)->toBe('contato@aurora.com.br')
        ->and($email->htmlBody)->toContain('via AssinaVelox');
});

test('domínio de outro remetente não verificado mantém o remetente da plataforma', function () {
    ['organization' => $organization, 'owner' => $owner] = brandingOrganization(actAs: false);
    brandingSaveFull($organization, $owner);

    app()->instance(VerifiedSenderDomains::class, new class implements VerifiedSenderDomains
    {
        public function isVerified(Organization $organization, string $domain): bool
        {
            return $domain === 'outro-dominio.com.br';
        }
    });

    $email = brandingSendInvitation($organization, $owner);

    expect($email->fromAddress)->toBeNull()
        ->and($email->replyToAddress)->toBe('contato@aurora.com.br');
});
