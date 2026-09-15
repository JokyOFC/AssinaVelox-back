<?php

use App\Enums\MembershipRole;
use App\Models\AuditEvent;
use App\Services\Embed\AllowedOrigins;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/Support/EmbedHelpers.php';

/*
|--------------------------------------------------------------------------
| Origens permitidas do widget (G-EMBED, docs/fase-3/widget-embutido.md §4)
|--------------------------------------------------------------------------
*/

beforeEach(fn () => $this->withoutVite());

test('normaliza para a origem exata que o navegador serializa', function (string $raw, string $expected) {
    expect(AllowedOrigins::normalize($raw))->toBe($expected);
})->with([
    'minúsculas' => ['https://App.Cliente.com.BR', 'https://app.cliente.com.br'],
    'porta padrão' => ['https://cliente.com.br:443', 'https://cliente.com.br'],
    'barra final' => ['https://cliente.com.br/', 'https://cliente.com.br'],
    'porta própria' => ['https://cliente.com.br:8443', 'https://cliente.com.br:8443'],
    'espaços' => ['  https://cliente.com.br  ', 'https://cliente.com.br'],
    'localhost fora de produção' => ['http://localhost:3000', 'http://localhost:3000'],
    'loopback IPv4 fora de produção' => ['http://127.0.0.1:8000', 'http://127.0.0.1:8000'],
]);

test('recusa o que não é origem exata', function (string $raw) {
    expect(AllowedOrigins::normalize($raw))->toBeNull();
})->with([
    'vazio' => [''],
    'http público' => ['http://cliente.com.br'],
    'curinga' => ['https://*.cliente.com.br'],
    'caminho' => ['https://cliente.com.br/app'],
    'query' => ['https://cliente.com.br?x=1'],
    'fragmento' => ['https://cliente.com.br#x'],
    'usuário e senha' => ['https://u:p@cliente.com.br'],
    'IP literal' => ['https://203.0.113.10'],
    'IP em hexadecimal' => ['https://0x7f.0.0.1'],
    'host sem ponto' => ['https://intranet'],
    'outro esquema' => ['ftp://cliente.com.br'],
    'javascript' => ['javascript:alert(1)'],
    'porta inválida' => ['https://cliente.com.br:70000'],
    'rótulo inválido' => ['https://-cliente.com.br'],
]);

test('em produção, localhost e 127.0.0.1 não valem (nem os já gravados)', function () {
    ['organization' => $organization] = createOrganizationWithOwner();
    AllowedOrigins::replace($organization, ['http://localhost:3000', 'https://cliente.com.br']);

    app()->detectEnvironment(fn () => 'production');

    try {
        expect(AllowedOrigins::normalize('http://localhost:3000'))->toBeNull()
            ->and(AllowedOrigins::normalize('https://localhost'))->toBeNull()
            ->and(AllowedOrigins::forOrganization($organization))->toBe(['https://cliente.com.br']);
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }
});

test('tela de origens: flag desligada 404; operador sem manage_integrations 403', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $member = attachMember($organization, MembershipRole::Member);

    actingAsMember($owner, $organization);
    $this->get(route('integrations.embed.edit'))->assertNotFound();
    $this->put(route('integrations.embed.update'), ['origins' => ['https://cliente.com.br']])->assertNotFound();

    embedEnable($organization);

    actingAsMember($member, $organization);
    $this->get(route('integrations.embed.edit'))->assertForbidden();
    $this->put(route('integrations.embed.update'), ['origins' => ['https://cliente.com.br']])->assertForbidden();
});

test('owner cadastra, a lista é normalizada e sem repetição, e a trilha registra a mudança', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    embedEnable($organization);
    actingAsMember($owner, $organization);

    $this->get(route('integrations.embed.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('embed/origins')
            ->where('origins', [])
            ->where('allows_loopback', true)
            ->where('script_url', route('embed.script')));

    $this->put(route('integrations.embed.update'), [
        'origins' => ['https://Cliente.com.br/', 'https://cliente.com.br:443', '', 'https://app.cliente.com.br:8443'],
    ])->assertRedirect();

    expect(AllowedOrigins::forOrganization($organization))->toBe(['https://cliente.com.br', 'https://app.cliente.com.br:8443']);

    $event = AuditEvent::withoutGlobalScopes()->where('event_type', 'embed_origins.updated')->firstOrFail();
    expect($event->envelope_id)->toBeNull()
        ->and($event->payload['added'])->toBe(['https://cliente.com.br', 'https://app.cliente.com.br:8443'])
        ->and($event->payload['count'])->toBe(2);

    $this->put(route('integrations.embed.update'), [
        'origins' => ['https://cliente.com.br', 'https://*.cliente.com.br'],
    ])->assertSessionHasErrors(['origins.1']);

    // Um item inválido recusa a lista inteira: nada mudou.
    expect(AllowedOrigins::forOrganization($organization))->toBe(['https://cliente.com.br', 'https://app.cliente.com.br:8443']);
});
