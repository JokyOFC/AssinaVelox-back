<?php

use App\Enums\AuditEventType;
use App\Integrations\Email\FakeSenderDomainVerifier;
use App\Integrations\Email\SenderDomainRegistry;
use App\Models\AuditEvent;
use App\Models\SenderDomain;
use App\Services\Branding\Contracts\VerifiedSenderDomains;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/Support/ChannelHelpers.php';

/*
|--------------------------------------------------------------------------
| Domínios de envio (classe B): simulador, produção desabilitada, isolamento
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    ['organization' => $this->organization, 'owner' => $this->owner] = createOrganizationWithOwner();
    $this->registry = app(SenderDomainRegistry::class);
});

it('com a flag desligada, não cadastra e nenhum domínio conta como verificado', function () {
    expect(fn () => $this->registry->create($this->organization, 'empresa.com.br', $this->owner))
        ->toThrow(ValidationException::class, 'Domínios de envio não estão habilitados para esta organização.');

    expect(SenderDomain::query()->count())->toBe(0)
        ->and(app(VerifiedSenderDomains::class)->isVerified($this->organization, 'empresa.com.br'))->toBeFalse();
});

it('cadastra com o simulador: normaliza o domínio, grava registros simulados e a trilha', function () {
    channelsEnable($this->organization, smsWhatsapp: false, senderDomains: true);

    $domain = $this->registry->create($this->organization, ' Empresa.COM.br. ', $this->owner);

    expect($domain->domain)->toBe('empresa.com.br')
        ->and($domain->status)->toBe(SenderDomain::STATUS_PENDING)
        ->and($domain->is_simulated)->toBeTrue()
        ->and($domain->provider)->toBe(FakeSenderDomainVerifier::NAME)
        ->and($domain->toArray())->not->toHaveKey('verification_token')
        ->and(collect($domain->expected_records)->every(fn (array $record): bool => str_contains($record['host'].$record['value'], 'simulado')))->toBeTrue();

    $event = AuditEvent::query()->where('event_type', AuditEventType::SenderDomainCreated->value)->sole();

    expect($event->envelope_id)->toBeNull()
        ->and($event->organization_id)->toBe($this->organization->id)
        ->and($event->payload['domain'])->toBe('empresa.com.br')
        ->and($event->payload['simulated'])->toBeTrue();
});

it('verificação pelo simulador nunca libera o remetente próprio', function () {
    channelsEnable($this->organization, smsWhatsapp: false, senderDomains: true);
    $domain = $this->registry->create($this->organization, 'empresa.com.br', $this->owner);

    app(FakeSenderDomainVerifier::class)->simulate('empresa.com.br', 'verified');
    $this->registry->check($domain, $this->owner);

    $domain->refresh();
    $props = $this->registry->props($this->organization);

    expect($domain->status)->toBe(SenderDomain::STATUS_VERIFIED)
        ->and($domain->is_simulated)->toBeTrue()
        ->and(AuditEvent::query()->where('event_type', AuditEventType::SenderDomainVerified->value)->exists())->toBeTrue()
        ->and(app(VerifiedSenderDomains::class)->isVerified($this->organization, 'empresa.com.br'))->toBeFalse()
        ->and($props['domains'][0]['usable_as_sender'])->toBeFalse()
        ->and($props['domains'][0]['simulated'])->toBeTrue();
});

it('só um domínio verificado de verdade, da própria organização e com a flag, libera o remetente', function () {
    channelsEnable($this->organization, smsWhatsapp: false, senderDomains: true);
    ['organization' => $other] = createOrganizationWithOwner();

    SenderDomain::query()->create([
        'organization_id' => $this->organization->id,
        'domain' => 'empresa.com.br',
        'status' => SenderDomain::STATUS_VERIFIED,
        'verification_token' => 'x',
        'provider' => 'email_api_servico_proprio',
        'is_simulated' => false,
        'verified_at' => now(),
    ]);

    $verified = app(VerifiedSenderDomains::class);

    expect($verified->isVerified($this->organization, 'EMPRESA.com.br'))->toBeTrue()
        ->and($verified->isVerified($this->organization, 'outra.com.br'))->toBeFalse()
        ->and($verified->isVerified($other, 'empresa.com.br'))->toBeFalse();

    channelsEnable($this->organization, smsWhatsapp: false, senderDomains: false);

    expect($verified->isVerified($this->organization, 'empresa.com.br'))->toBeFalse();
});

it('falha na verificação fica registrada com o motivo', function () {
    channelsEnable($this->organization, smsWhatsapp: false, senderDomains: true);
    $domain = $this->registry->create($this->organization, 'empresa.com.br', $this->owner);

    app(FakeSenderDomainVerifier::class)->simulate('empresa.com.br', 'failed', 'Registro DKIM ausente.');
    $this->registry->check($domain, $this->owner);

    expect($domain->fresh()->status)->toBe(SenderDomain::STATUS_FAILED)
        ->and($domain->fresh()->failure_reason)->toBe('Registro DKIM ausente.')
        ->and(AuditEvent::query()->where('event_type', AuditEventType::SenderDomainFailed->value)->exists())->toBeTrue();
});

it('em produção o verificador do serviço próprio está desabilitado e o cadastro é recusado com o motivo', function () {
    channelsEnable($this->organization, smsWhatsapp: false, senderDomains: true);
    config()->set('assinavelox.sender_domains.verifier', 'http');

    $availability = $this->registry->availability($this->organization);

    expect($availability['available'])->toBeFalse()
        ->and($availability['reason_code'])->toBe('provider_disabled')
        ->and($availability['reason'])->toContain('Reply-To');

    expect(fn () => $this->registry->create($this->organization, 'empresa.com.br', $this->owner))
        ->toThrow(ValidationException::class, 'A verificação de domínio está desativada nesta instalação');
});

it('recusa domínio inválido, da plataforma, repetido ou além do limite; remover grava a trilha', function () {
    channelsEnable($this->organization, smsWhatsapp: false, senderDomains: true);
    config()->set('mail.from.address', 'no-reply@assinavelox.test');
    config()->set('assinavelox.sender_domains.max_per_organization', 1);

    foreach (['não é domínio', 'maria@empresa.com.br', 'localhost', 'https://empresa.com.br/x'] as $invalid) {
        expect(fn () => $this->registry->create($this->organization, $invalid, $this->owner))
            ->toThrow(ValidationException::class, 'Informe um domínio válido, como empresa.com.br.');
    }

    expect(fn () => $this->registry->create($this->organization, 'assinavelox.test', $this->owner))
        ->toThrow(ValidationException::class, 'Este domínio é da plataforma');

    $domain = $this->registry->create($this->organization, 'empresa.com.br', $this->owner);

    expect(fn () => $this->registry->create($this->organization, 'empresa.com.br', $this->owner))
        ->toThrow(ValidationException::class, 'Este domínio já está cadastrado.');
    expect(fn () => $this->registry->create($this->organization, 'outra.com.br', $this->owner))
        ->toThrow(ValidationException::class, 'máximo de 1 domínios');

    $this->registry->delete($domain, $this->owner);

    expect(SenderDomain::query()->count())->toBe(0)
        ->and(AuditEvent::query()->where('event_type', AuditEventType::SenderDomainDeleted->value)->exists())->toBeTrue();
});
