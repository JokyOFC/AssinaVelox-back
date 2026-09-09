<?php

use App\Enums\AuditEventType;
use App\Enums\EnvelopeStatus;
use App\Enums\PlanConsumptionStatus;
use App\Enums\RecipientStatus;
use App\Enums\SigningOrder;
use App\Models\AuditEvent;
use App\Models\Envelope;
use App\Models\PlanConsumption;
use App\Models\RecipientAccessLink;
use App\Services\Envelopes\Sending\AccessLinks;
use App\Services\Envelopes\Sending\ExpireEnvelopes;
use App\Services\Envelopes\Sending\SendEnvelope;
use Illuminate\Support\Carbon;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/Support/SendingHelpers.php';

beforeEach(function (): void {
    $this->withoutVite();
    $this->provider = fakeEmailProvider();
});

// Helper global do Pest: o arquivo inteiro compartilha o mesmo escopo de funções,
// então uma redeclaração em outro arquivo de teste seria erro fatal.
if (! function_exists('expiredDeadlineEnvelope')) {
    /**
     * Envelope enviado com o prazo já vencido (o relógio é adiantado, não a coluna).
     */
    function expiredDeadlineEnvelope(array $recipients = [['name' => 'Maria Alves', 'email' => 'maria@exemplo.com']], SigningOrder $order = SigningOrder::Sequential): Envelope
    {
        ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

        $envelope = readyEnvelope($organization, $owner, $recipients, $order);

        app(SendEnvelope::class)->handle($envelope);

        $envelope = $envelope->fresh();

        // Prazo padrão = 30 dias; 31 dias depois já venceu.
        Carbon::setTestNow(Carbon::now()->addDays(31));

        return $envelope;
    }
}

afterEach(fn () => Carbon::setTestNow());

test('o comando expira os envelopes vencidos, marca os pendentes e revoga os links', function () {
    $envelope = expiredDeadlineEnvelope();

    $this->artisan('envelopes:expire')
        ->expectsOutputToContain('1 documento(s) marcado(s) como expirado(s).')
        ->assertSuccessful();

    $envelope->refresh();

    expect($envelope->status)->toBe(EnvelopeStatus::Expired)
        ->and($envelope->expired_at)->not->toBeNull();

    expect($envelope->recipients()->firstOrFail()->status)->toBe(RecipientStatus::Expired);

    expect(RecipientAccessLink::withoutOrganizationScope()->whereNull('revoked_at')->count())->toBe(0);

    expect(AuditEvent::withoutOrganizationScope()
        ->where('event_type', AuditEventType::EnvelopeExpired->value)
        ->count())->toBe(1);
});

test('a expiração é idempotente: rodar de novo não grava outro evento', function () {
    $envelope = expiredDeadlineEnvelope();

    $expiration = app(ExpireEnvelopes::class);

    expect($expiration->sweep())->toBe(1)
        ->and($expiration->sweep())->toBe(0)
        ->and($expiration->expire($envelope->fresh()))->toBeFalse();

    expect(AuditEvent::withoutOrganizationScope()
        ->where('event_type', AuditEventType::EnvelopeExpired->value)
        ->count())->toBe(1);
});

test('enforce expira no acesso, sem esperar o agendador, e impede o uso do link', function () {
    $envelope = expiredDeadlineEnvelope();

    $link = RecipientAccessLink::withoutOrganizationScope()->firstOrFail();

    // O link ainda não foi revogado — só venceu junto com o envelope.
    expect($link->revoked_at)->toBeNull();

    // Nenhum comando rodou: quem chama é a página pública, a cada acesso.
    expect(app(ExpireEnvelopes::class)->enforce($envelope))->toBeTrue();

    expect($envelope->fresh()->status)->toBe(EnvelopeStatus::Expired)
        ->and($link->fresh()->revoked_at)->not->toBeNull()
        ->and($link->fresh()->isUsable())->toBeFalse();
});

test('enforce não toca em envelope dentro do prazo nem em envelope terminal', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = readyEnvelope($organization, $owner);
    app(SendEnvelope::class)->handle($envelope);
    $envelope = $envelope->fresh();

    $expiration = app(ExpireEnvelopes::class);

    expect($expiration->enforce($envelope))->toBeFalse()
        ->and($envelope->fresh()->status)->toBe(EnvelopeStatus::InProgress);

    $envelope->forceFill(['status' => EnvelopeStatus::Completed])->save();

    expect($expiration->enforce($envelope->fresh()))->toBeFalse();
});

test('revalidate devolve o envelope com o status correto para o momento', function () {
    $envelope = expiredDeadlineEnvelope();

    $result = app(ExpireEnvelopes::class)->revalidate($envelope);

    expect($result->status)->toBe(EnvelopeStatus::Expired);
});

test('a expiração não libera o consumo do plano: o envio já produziu efeito', function () {
    $envelope = expiredDeadlineEnvelope();

    app(ExpireEnvelopes::class)->sweep();

    $consumption = PlanConsumption::withoutOrganizationScope()->firstOrFail();

    expect($consumption->status)->toBe(PlanConsumptionStatus::Committed)
        ->and(subscriptionFor($envelope->organization)->envelopes_used)->toBe(1);
});

test('o aviso de prazo sai uma única vez e só para quem tem convite ativo', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    $envelope = readyEnvelope($organization, $owner, [
        ['name' => 'Maria Alves', 'email' => 'maria@exemplo.com'],
        ['name' => 'Carlos Mendes', 'email' => 'carlos@exemplo.com'],
    ], SigningOrder::Sequential);

    app(SendEnvelope::class)->handle($envelope);
    $envelope = $envelope->fresh();

    // Dentro da janela de 48 h antes do prazo.
    Carbon::setTestNow($envelope->expires_at->copy()->subHours(20));

    $this->artisan('envelopes:notify-expiring')
        ->expectsOutputToContain('1 documento(s) com aviso de prazo enviado.')
        ->assertSuccessful();

    // Maria (convidada) recebe; Carlos (aguardando a vez, sem link) não.
    expect($this->provider->to('maria@exemplo.com'))->toHaveCount(2) // convite + aviso
        ->and($this->provider->to('carlos@exemplo.com'))->toHaveCount(0);

    // O remetente também é avisado (preferência padrão inclui e-mail).
    expect($this->provider->to($owner->email))->toHaveCount(1);

    $before = $this->provider->count();

    $this->artisan('envelopes:notify-expiring')->assertSuccessful();

    expect($this->provider->count())->toBe($before);
});

test('o aviso de prazo emite um link novo e revoga o anterior', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = readyEnvelope($organization, $owner);
    app(SendEnvelope::class)->handle($envelope);
    $envelope = $envelope->fresh();

    $first = RecipientAccessLink::withoutOrganizationScope()->firstOrFail();

    Carbon::setTestNow($envelope->expires_at->copy()->subHours(10));

    app(ExpireEnvelopes::class)->warnExpiring();

    expect($first->fresh()->revoked_at)->not->toBeNull()
        ->and(RecipientAccessLink::withoutOrganizationScope()->whereNull('revoked_at')->count())->toBe(1);

    // O e-mail do aviso carrega um link que resolve.
    $bodies = $this->provider->bodies();
    preg_match_all('#/assinar/([A-Za-z0-9_-]{43})#', $bodies, $matches);

    $latest = end($matches[1]);

    expect(app(AccessLinks::class)->resolve($latest)?->isUsable())->toBeTrue();
});

test('envelope sem pendentes não recebe aviso de prazo', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = readyEnvelope($organization, $owner);
    app(SendEnvelope::class)->handle($envelope);
    $envelope = $envelope->fresh();

    $envelope->recipients()->firstOrFail()->forceFill([
        'status' => RecipientStatus::Signed,
        'signed_at' => now(),
    ])->save();

    Carbon::setTestNow($envelope->expires_at->copy()->subHours(5));

    expect(app(ExpireEnvelopes::class)->warnExpiring())->toBe(0);
});
