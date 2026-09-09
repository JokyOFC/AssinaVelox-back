<?php

use App\Enums\AuditEventType;
use App\Enums\EnvelopeStatus;
use App\Enums\FieldType;
use App\Enums\RecipientStatus;
use App\Enums\SigningOrder;
use App\Models\AuditEvent;
use App\Models\Recipient;
use App\Models\SignatureAcceptance;
use App\Services\Signing\SignerTokens;
use Illuminate\Support\Facades\File;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/Support/SignerHelpers.php';

/*
|--------------------------------------------------------------------------
| Resolução do link e abertura da página (arquitetura §4.1, ROUTES §3.2)
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->work = storage_path('framework/testing/signer-'.uniqid());
    File::ensureDirectoryExists($this->work);
    signerDisk($this->work);
    $this->withoutVite();
});

afterEach(function () {
    File::deleteDirectory($this->work ?? '');
});

it('abre a etapa de identificação com dados mínimos e sem expor o documento', function () {
    $ctx = signerEnvelope();
    $token = $ctx['tokens']['maria@exemplo.test'];

    $response = $this->get(route('sign.show', ['token' => $token]));

    $response->assertOk();

    $props = $response->viewData('page')['props'];

    expect($props['screen'])->toBe('identify')
        ->and($props['recipient']['email_masked'])->toBe('m••••@exemplo.test')
        // O e-mail completo nunca sai nas props.
        ->and(json_encode($props))->not->toContain('maria@exemplo.test')
        // Nem o documento, nem os campos, antes de confirmar a identidade.
        ->and($props['document'])->toBeNull()
        ->and($props['my_fields'])->toBe([])
        ->and($props['other_fields'])->toBe([])
        ->and($props['consent_text'])->toBe('')
        ->and($props['authorization'])->toBeNull()
        ->and($props['privacy']['summary'])->toContain($ctx['organization']->name);
});

it('registra abertura detectada uma única vez e não consome o convite (GET de scanner)', function () {
    $ctx = signerEnvelope();
    $token = $ctx['tokens']['maria@exemplo.test'];
    $recipient = $ctx['recipients']['maria@exemplo.test'];
    $link = $ctx['links']['maria@exemplo.test'];

    $this->get(route('sign.show', ['token' => $token]))->assertOk();
    $this->get(route('sign.show', ['token' => $token]))->assertOk();
    $this->get(route('sign.show', ['token' => $token]))->assertOk();

    // Status avança para `viewed` — "abertura detectada", não leitura.
    expect($recipient->fresh()->status)->toBe(RecipientStatus::Viewed);

    // Um único evento, mesmo com três aberturas.
    expect(AuditEvent::query()->where('event_type', AuditEventType::InvitationOpened->value)->count())->toBe(1);

    // O convite continua utilizável e nada foi assinado.
    $link->refresh();
    expect($link->revoked_at)->toBeNull()
        ->and($link->isUsable())->toBeTrue()
        ->and($link->use_count)->toBe(1)
        ->and(SignatureAcceptance::query()->count())->toBe(0);

    // E o link segue abrindo depois das visitas do scanner.
    $this->get(route('sign.show', ['token' => $token]))->assertOk();
});

it('responde 404 idêntico para token desconhecido, revogado, vencido e fora da vez', function () {
    $ctx = signerEnvelope([
        ['name' => 'Maria Alves', 'email' => 'maria@exemplo.test'],
        ['name' => 'Carlos Mendes', 'email' => 'carlos@exemplo.test'],
    ], SigningOrder::Sequential);

    $desconhecido = SignerTokens::generate();

    $revogado = $ctx['tokens']['maria@exemplo.test'];
    $ctx['links']['maria@exemplo.test']->forceFill(['revoked_at' => now()])->save();

    // Carlos é o segundo da fila: existe link, mas não é a vez dele.
    $foraDaVez = $ctx['tokens']['carlos@exemplo.test'];

    $respostas = collect([$desconhecido, $revogado, $foraDaVez])
        ->map(fn (string $token) => $this->get(route('sign.show', ['token' => $token])));

    foreach ($respostas as $response) {
        $response->assertNotFound();
        expect($response->viewData('page')['props']['screen'])->toBe('invalid');
    }

    // Corpos idênticos: a resposta não diferencia os motivos.
    expect($respostas->map(fn ($r) => $r->viewData('page')['props'])->unique(fn ($p) => json_encode($p))->count())->toBe(1);
});

it('não abre link de envelope que ainda está em rascunho', function () {
    $ctx = signerEnvelope();
    $ctx['envelope']->forceFill(['status' => EnvelopeStatus::Draft])->save();

    $this->get(route('sign.show', ['token' => $ctx['tokens']['maria@exemplo.test']]))->assertNotFound();
});

it('expira o envelope no acesso quando o prazo venceu, sem depender do agendador', function () {
    $ctx = signerEnvelope();
    $ctx['envelope']->forceFill(['expires_at' => now()->subMinute()])->save();

    $response = $this->get(route('sign.show', ['token' => $ctx['tokens']['maria@exemplo.test']]));

    $response->assertOk();
    expect($response->viewData('page')['props']['screen'])->toBe('expired')
        ->and($ctx['envelope']->fresh()->status)->toBe(EnvelopeStatus::Expired)
        ->and($ctx['recipients']['maria@exemplo.test']->fresh()->status)->toBe(RecipientStatus::Expired)
        ->and(AuditEvent::query()->where('event_type', AuditEventType::EnvelopeExpired->value)->exists())->toBeTrue();
});

it('mostra cancelado quando o remetente cancelou', function () {
    $ctx = signerEnvelope();
    $ctx['envelope']->forceFill(['status' => EnvelopeStatus::Canceled, 'canceled_at' => now()])->save();

    $response = $this->get(route('sign.show', ['token' => $ctx['tokens']['maria@exemplo.test']]));

    expect($response->viewData('page')['props']['screen'])->toBe('canceled');
});

it('lista os demais participantes sem e-mail nenhum', function () {
    $ctx = signerEnvelope([
        ['name' => 'Maria Alves', 'email' => 'maria@exemplo.test'],
        ['name' => 'Carlos Mendes', 'email' => 'carlos@exemplo.test'],
    ], SigningOrder::Sequential);

    $props = $this->get(route('sign.show', ['token' => $ctx['tokens']['maria@exemplo.test']]))
        ->viewData('page')['props'];

    expect($props['others'])->toHaveCount(1)
        ->and($props['others'][0]['name'])->toBe('Carlos Mendes')
        ->and($props['others'][0]['signs_after_me'])->toBeTrue()
        ->and(json_encode($props['others']))->not->toContain('@');
});

it('isola organizações: o link de uma não abre nada da outra', function () {
    $a = signerEnvelope([['name' => 'Maria Alves', 'email' => 'maria@a.test', 'fields' => [FieldType::Signature]]]);
    $b = signerEnvelope([['name' => 'João Braga', 'email' => 'joao@b.test', 'fields' => [FieldType::Signature]]]);

    $props = $this->get(route('sign.show', ['token' => $a['tokens']['maria@a.test']]))->viewData('page')['props'];

    expect($props['sender']['organization_name'])->toBe($a['organization']->name)
        ->and($props['envelope']['display_code'])->toBe($a['envelope']->display_code)
        ->and(json_encode($props))->not->toContain($b['organization']->name)
        ->and(json_encode($props))->not->toContain('João Braga');
});

it('não serve o PDF sem sessão autenticada', function () {
    $ctx = signerEnvelope();

    $this->get(route('sign.document', ['token' => $ctx['tokens']['maria@exemplo.test']]))->assertNotFound();
});

it('não deixa o token bruto aparecer na trilha de auditoria', function () {
    $ctx = signerEnvelope();
    $token = $ctx['tokens']['maria@exemplo.test'];

    $this->get(route('sign.show', ['token' => $token]))->assertOk();

    $trilha = AuditEvent::query()->get()->map(fn (AuditEvent $e) => json_encode($e->payload))->implode(' ');

    expect($trilha)->not->toContain($token)
        ->and($trilha)->not->toContain(SignerTokens::digest($token));
});

it('mostra o comprovante para quem já assinou e o link continua abrindo', function () {
    $ctx = signerEnvelope();
    $recipient = $ctx['recipients']['maria@exemplo.test'];

    $recipient->forceFill(['status' => RecipientStatus::Signed, 'signed_at' => now()])->save();

    SignatureAcceptance::query()->create([
        'recipient_id' => $recipient->id,
        'envelope_id' => $ctx['envelope']->id,
        'document_version_id' => $ctx['version']->id,
        'organization_id' => $ctx['organization']->id,
        'accepted_at' => now(),
        'ip_address' => '203.0.113.44',
        'user_agent' => 'Teste',
        'auth_method' => 'email_otp',
        'terms_version' => 'v1-2026-09-08',
        'consent_statement' => 'Declaração de teste.',
        'document_sha256' => $ctx['version']->sha256,
    ]);

    $props = $this->get(route('sign.show', ['token' => $ctx['tokens']['maria@exemplo.test']]))
        ->assertOk()
        ->viewData('page')['props'];

    expect($props['screen'])->toBe('already_signed_pending_others')
        ->and($props['receipt']['final_pdf_available'])->toBeFalse()
        // IP mascarado por padrão (`evidence_show_ip = masked`).
        ->and($props['receipt']['ip'])->toBe('203.0.***.***')
        ->and($props['receipt']['document_sha256'])->toBe($ctx['version']->sha256);
});

it('mostra recusa apenas para quem recusou e cancelamento para os demais', function () {
    $ctx = signerEnvelope([
        ['name' => 'Maria Alves', 'email' => 'maria@exemplo.test'],
        ['name' => 'Carlos Mendes', 'email' => 'carlos@exemplo.test', 'order' => 1, 'status' => RecipientStatus::Viewed],
    ], SigningOrder::Parallel);

    /** @var Recipient $maria */
    $maria = $ctx['recipients']['maria@exemplo.test'];
    $maria->forceFill(['status' => RecipientStatus::Refused, 'refused_at' => now(), 'refusal_reason' => 'Valor divergente do combinado.'])->save();

    /** @var Recipient $carlos */
    $carlos = $ctx['recipients']['carlos@exemplo.test'];
    $carlos->forceFill(['status' => RecipientStatus::Canceled])->save();

    $ctx['envelope']->forceFill(['status' => EnvelopeStatus::Refused, 'refused_at' => now()])->save();

    $dela = $this->get(route('sign.show', ['token' => $ctx['tokens']['maria@exemplo.test']]))->viewData('page')['props'];
    $dele = $this->get(route('sign.show', ['token' => $ctx['tokens']['carlos@exemplo.test']]))->viewData('page')['props'];

    expect($dela['screen'])->toBe('refused')
        ->and($dela['refusal']['reason'])->toBe('Valor divergente do combinado.')
        // Quem não recusou nunca vê "você recusou".
        ->and($dele['screen'])->toBe('canceled')
        ->and($dele['refusal'])->toBeNull();
});
