<?php

use App\Enums\AuditEventType;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Support\Http\BlockedOutboundUrl;
use App\Support\Http\IpClassifier;
use App\Support\Http\OutboundUrlGuard;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/Support/WebhookHelpers.php';

/*
|--------------------------------------------------------------------------
| Proteção contra SSRF — docs/fase-2/webhooks.md §6
|--------------------------------------------------------------------------
| DNS sempre falso (FakeDnsResolver) e nenhuma chamada HTTP sai (Http::fake /
| preventStrayRequests). O pino de IP com conexão real está em PinnedConnectionTest.
*/

function ssrfGuard(array $records = []): OutboundUrlGuard
{
    fakeDns($records);

    return app(OutboundUrlGuard::class);
}

function blockedReasonOf(callable $callback): ?string
{
    try {
        $callback();
    } catch (BlockedOutboundUrl $blocked) {
        return $blocked->reason;
    }

    return null;
}

dataset('blocked_addresses', [
    'loopback 127.0.0.1' => ['127.0.0.1', 'loopback'],
    'loopback 127.53.0.9' => ['127.53.0.9', 'loopback'],
    'privada 10/8' => ['10.0.0.5', 'private'],
    'privada 172.16/12 (início)' => ['172.16.4.4', 'private'],
    'privada 172.16/12 (fim)' => ['172.31.255.254', 'private'],
    'privada 192.168/16' => ['192.168.1.10', 'private'],
    'link-local 169.254/16' => ['169.254.10.10', 'link_local'],
    'metadados de nuvem 169.254.169.254' => ['169.254.169.254', 'link_local'],
    'CGNAT 100.64/10 (início)' => ['100.64.0.1', 'cgnat'],
    'CGNAT 100.64/10 (fim)' => ['100.127.255.254', 'cgnat'],
    '0.0.0.0' => ['0.0.0.0', 'this_network'],
    '0.0.0.0/8' => ['0.1.2.3', 'this_network'],
    'multicast 224/4' => ['224.0.0.251', 'multicast'],
    'reservada 240/4' => ['240.0.0.1', 'reserved'],
    'broadcast' => ['255.255.255.255', 'reserved'],
    'documentação 192.0.2/24' => ['192.0.2.1', 'documentation'],
    'benchmark 198.18/15' => ['198.19.0.1', 'benchmarking'],
    'IPv6 loopback ::1' => ['::1', 'loopback'],
    'IPv6 não especificado ::' => ['::', 'unspecified'],
    'IPv6 ULA fc00::/7 (fd)' => ['fd12:3456::1', 'unique_local'],
    'IPv6 ULA fc00::/7 (fc)' => ['fc00::1', 'unique_local'],
    'IPv6 metadados AWS fd00:ec2::254' => ['fd00:ec2::254', 'unique_local'],
    'IPv6 link-local fe80::/10' => ['fe80::1', 'link_local'],
    'IPv6 multicast ff00::/8' => ['ff02::1', 'multicast'],
    'IPv6 documentação' => ['2001:db8::1', 'documentation'],
    'IPv4 mapeado → loopback' => ['::ffff:127.0.0.1', 'ipv4_embedded:loopback'],
    'IPv4 mapeado → metadados' => ['::ffff:169.254.169.254', 'ipv4_embedded:link_local'],
    'IPv4 mapeado → privada' => ['::ffff:10.1.2.3', 'ipv4_embedded:private'],
    'IPv4 mapeado em hexadecimal' => ['::ffff:7f00:1', 'ipv4_embedded:loopback'],
    'IPv4 mapeado → pública (também recusado)' => ['::ffff:8.8.8.8', 'ipv4_embedded:public'],
    'NAT64 64:ff9b:: → metadados' => ['64:ff9b::a9fe:a9fe', 'ipv4_embedded:link_local'],
    '6to4 2002:: → privada' => ['2002:0a00:0001::1', 'ipv4_embedded:private'],
    'IPv4 compatível ::a.b.c.d' => ['::127.0.0.1', 'ipv4_compatible'],
]);

it('classifica cada faixa bloqueada (IPv4, IPv6 e IPv4 embutido)', function (string $ip, string $label): void {
    expect(IpClassifier::blockedReason($ip))->toBe($label);
})->with('blocked_addresses');

it('aceita endereços públicos', function (string $ip): void {
    expect(IpClassifier::blockedReason($ip))->toBeNull();
})->with(['93.184.215.34', '8.8.8.8', '1.1.1.1', '2606:4700:4700::1111', '2001:4860:4860::8888']);

it('recusa nome que resolve para qualquer faixa bloqueada', function (string $ip): void {
    $guard = ssrfGuard(['interno.example.com' => [$ip]]);

    expect(blockedReasonOf(fn () => $guard->inspect('https://interno.example.com/hook')))
        ->toBe(BlockedOutboundUrl::BLOCKED_ADDRESS);
})->with('blocked_addresses');

it('recusa quando UM dos endereços resolvidos é interno (resposta mista)', function (): void {
    $guard = ssrfGuard(['misto.example.com' => [WEBHOOK_PUBLIC_IP, '10.0.0.5']]);

    expect(blockedReasonOf(fn () => $guard->inspect('https://misto.example.com/hook')))
        ->toBe(BlockedOutboundUrl::BLOCKED_ADDRESS);
});

it('aceita domínio público, reconstrói a URL e pina a conexão no endereço validado', function (): void {
    $guard = ssrfGuard(['receiver.example.com' => [WEBHOOK_PUBLIC_IP, '2606:2800:21f:cb07:6820:80da:af6b:8b2c']]);

    $target = $guard->inspect('https://Receiver.Example.com./hooks/av?x=1#fragmento');

    expect($target->url)->toBe('https://receiver.example.com/hooks/av?x=1')
        ->and($target->host)->toBe('receiver.example.com')
        ->and($target->port)->toBe(443)
        ->and($target->pinnedAddress)->toBe(WEBHOOK_PUBLIC_IP)
        ->and($target->curlResolveEntry())->toBe('receiver.example.com:443:'.WEBHOOK_PUBLIC_IP);
});

it('pina IPv6 entre colchetes quando só há AAAA público', function (): void {
    $guard = ssrfGuard(['v6.example.com' => ['2606:4700:4700::1111']]);

    expect($guard->inspect('https://v6.example.com/')->curlResolveEntry())
        ->toBe('v6.example.com:443:[2606:4700:4700::1111]');
});

dataset('internal_ip_literals', [
    'decimal inteiro' => ['https://2130706433/'],
    'octal' => ['https://0177.0.0.1/'],
    'hexadecimal por octeto' => ['https://0x7f.0x0.0x0.0x1/'],
    'hexadecimal inteiro' => ['https://0x7f000001/'],
    'forma curta' => ['https://127.1/'],
    'pontuado' => ['https://127.0.0.1/'],
    'zero' => ['https://0/'],
    'metadados pontuado' => ['https://169.254.169.254/latest/meta-data/'],
    'metadados decimal' => ['https://2852039166/'],
    'privada octal misto' => ['https://012.0.0.1/'],
    'IPv6 loopback' => ['https://[::1]/'],
    'IPv6 IPv4 mapeado' => ['https://[::ffff:127.0.0.1]/'],
    'IPv6 IPv4 mapeado em hex' => ['https://[::ffff:7f00:1]/'],
    'IPv6 link-local com zona' => ['https://[fe80::1%25eth0]/'],
    'IPv6 ULA' => ['https://[fd00:ec2::254]/'],
]);

it('recusa IP literal interno em qualquer grafia, sem consultar o DNS', function (string $url): void {
    $dns = fakeDns();
    $guard = app(OutboundUrlGuard::class);

    expect(blockedReasonOf(fn () => $guard->inspect($url)))->toBe(BlockedOutboundUrl::BLOCKED_ADDRESS)
        ->and($dns->lookups)->toBe([]);
})->with('internal_ip_literals');

it('recusa IP literal mesmo público: exige nome de domínio', function (): void {
    expect(blockedReasonOf(fn () => ssrfGuard()->inspect('https://8.8.8.8/hook')))->toBe(BlockedOutboundUrl::IP_LITERAL)
        ->and(blockedReasonOf(fn () => ssrfGuard()->inspect('https://[2606:4700:4700::1111]/')))->toBe(BlockedOutboundUrl::IP_LITERAL);
});

it('recusa literal numérico impossível', function (string $url): void {
    expect(blockedReasonOf(fn () => ssrfGuard()->inspect($url)))->toBe(BlockedOutboundUrl::INVALID_URL);
})->with(['https://256.1.1.1/', 'https://0x1FFFFFFFF/', 'https://1.2.3.4.5/', 'https://1.256.1/']);

it('recusa host sem ponto e sufixos internos sem consultar o DNS', function (string $url): void {
    $dns = fakeDns();

    expect(blockedReasonOf(fn () => app(OutboundUrlGuard::class)->inspect($url)))->toBe(BlockedOutboundUrl::HOST_NOT_ALLOWED)
        ->and($dns->lookups)->toBe([]);
})->with([
    'https://localhost/',
    'https://intranet/',
    'https://api.localhost/',
    'https://printer.local/',
    'https://metadata.google.internal/computeMetadata/v1/',
    'https://router.home.arpa/',
    'https://nas.localdomain/',
]);

it('recusa usuário e senha na URL', function (string $url): void {
    expect(blockedReasonOf(fn () => ssrfGuard()->inspect($url)))->toBe(BlockedOutboundUrl::CREDENTIALS_IN_URL);
})->with(['https://user:pass@receiver.example.com/', 'https://receiver.example.com@127.0.0.1/']);

it('recusa barra invertida, espaço e caractere de controle (diferença entre parsers)', function (string $url): void {
    expect(blockedReasonOf(fn () => ssrfGuard()->inspect($url)))->toBe(BlockedOutboundUrl::INVALID_URL);
})->with(['https://receiver.example.com\\@127.0.0.1/', 'https://receiver.example.com /x', "https://receiver.example.com/a\x00b", "https://receiver.example.com/a\tb", 'https://%31%32%37.0.0.1/']);

it('recusa porta não permitida e aceita a porta configurada', function (): void {
    expect(blockedReasonOf(fn () => ssrfGuard()->inspect('https://receiver.example.com:8443/')))->toBe(BlockedOutboundUrl::PORT_NOT_ALLOWED)
        ->and(blockedReasonOf(fn () => ssrfGuard()->inspect('https://receiver.example.com:22/')))->toBe(BlockedOutboundUrl::PORT_NOT_ALLOWED)
        ->and(blockedReasonOf(fn () => ssrfGuard()->inspect('https://receiver.example.com:6379/')))->toBe(BlockedOutboundUrl::PORT_NOT_ALLOWED);

    config(['assinavelox.webhooks.allowed_ports' => [443, 8443]]);

    expect(ssrfGuard()->inspect('https://receiver.example.com:8443/')->curlResolveEntry())
        ->toBe('receiver.example.com:8443:'.WEBHOOK_PUBLIC_IP);
});

it('recusa esquemas que não são https', function (string $url): void {
    expect(blockedReasonOf(fn () => ssrfGuard()->inspect($url)))->not->toBeNull();
})->with(['ftp://receiver.example.com/', 'gopher://receiver.example.com/', 'file:///etc/passwd', 'dict://receiver.example.com:11211/', 'javascript:alert(1)']);

it('recusa http:// em produção mesmo com allow_http e a exceção de teste configuradas', function (): void {
    config([
        'assinavelox.webhooks.allow_http' => true,
        'assinavelox.webhooks.testing_allowed_cidrs' => ['127.0.0.1/32'],
    ]);
    $guard = ssrfGuard(['loopback-alias.example.com' => ['127.0.0.1']]);

    // Fora de produção as duas exceções valem (é assim que o teste do pino funciona).
    expect(blockedReasonOf(fn () => $guard->inspect('http://receiver.example.com/')))->toBeNull()
        ->and(blockedReasonOf(fn () => $guard->inspect('https://loopback-alias.example.com/')))->toBeNull();

    $this->app['env'] = 'production';

    try {
        expect(blockedReasonOf(fn () => $guard->inspect('http://receiver.example.com/')))->toBe(BlockedOutboundUrl::SCHEME_NOT_ALLOWED)
            ->and(blockedReasonOf(fn () => $guard->inspect('https://loopback-alias.example.com/')))->toBe(BlockedOutboundUrl::BLOCKED_ADDRESS);
    } finally {
        $this->app['env'] = 'testing';
    }
});

it('recusa http:// fora de produção quando allow_http está desligado', function (): void {
    config(['assinavelox.webhooks.allow_http' => false]);

    expect(blockedReasonOf(fn () => ssrfGuard()->inspect('http://receiver.example.com/')))->toBe(BlockedOutboundUrl::SCHEME_NOT_ALLOWED);
});

it('nome que não resolve é recusado com a mesma mensagem de endereço interno (sem oráculo de DNS)', function (): void {
    $guard = ssrfGuard(['interno.example.com' => ['10.0.0.1']]);

    $unresolved = null;
    $internal = null;

    try {
        $guard->inspect('https://nao-existe.example.com/');
    } catch (BlockedOutboundUrl $blocked) {
        $unresolved = $blocked;
    }

    try {
        $guard->inspect('https://interno.example.com/');
    } catch (BlockedOutboundUrl $blocked) {
        $internal = $blocked;
    }

    expect($unresolved?->reason)->toBe(BlockedOutboundUrl::DNS_FAILED)
        ->and($internal?->reason)->toBe(BlockedOutboundUrl::BLOCKED_ADDRESS)
        ->and($unresolved?->userMessage())->toBe($internal?->userMessage())
        ->and($internal?->userMessage())->not->toContain('10.0.0.1');
});

it('aceita domínio internacionalizado convertido para ASCII', function (): void {
    $ascii = (string) idn_to_ascii('ação.example.com', IDNA_DEFAULT | IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
    $guard = ssrfGuard([$ascii => [WEBHOOK_PUBLIC_IP]]);

    expect($guard->inspect('https://ação.example.com/hook')->host)->toBe($ascii);
});

it('recusa no cadastro URL cujo nome resolve para rede interna', function (): void {
    ['organization' => $organization, 'owner' => $owner] = webhookOrg(['intranet.example.com' => ['10.0.0.8']]);
    actingAsMember($owner, $organization);

    $this->post(route('integrations.webhooks.store'), ['url' => 'https://intranet.example.com/hook', 'events' => ['*']])
        ->assertSessionHasErrors('url');

    $this->post(route('integrations.webhooks.store'), ['url' => 'http://receiver.example.com:8080/hook', 'events' => ['*']])
        ->assertSessionHasErrors('url');

    $this->post(route('integrations.webhooks.store'), ['url' => 'https://169.254.169.254/latest', 'events' => ['*']])
        ->assertSessionHasErrors('url');

    expect(WebhookEndpoint::withoutOrganizationScope()->count())->toBe(0);
    Http::assertNothingSent();
});

it('revalida a cada entrega: DNS que passa a apontar para rede interna bloqueia sem enviar nada', function (): void {
    ['organization' => $organization, 'owner' => $owner] = webhookOrg();
    makeEndpoint($organization, $owner);
    Http::fake();

    // Depois do cadastro, o nome passa a resolver para o endereço de metadados (rebinding).
    fakeDns([WEBHOOK_TEST_HOST => ['169.254.169.254']]);

    ['envelope' => $envelope] = webhookEnvelope($organization, $owner);
    recordAudit($envelope, AuditEventType::EnvelopeSent);

    $delivery = WebhookDelivery::withoutOrganizationScope()->sole();

    expect($delivery->status)->toBe(WebhookDelivery::STATUS_FAILED)
        ->and($delivery->last_error)->toBe('ssrf_blocked_address')
        ->and($delivery->history[0]['outcome'])->toBe('blocked')
        ->and($delivery->next_retry_at)->not->toBeNull();

    Http::assertNothingSent();
});
