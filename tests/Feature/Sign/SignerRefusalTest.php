<?php

use App\Enums\AccessLinkPurpose;
use App\Enums\AuditEventType;
use App\Enums\EnvelopeStatus;
use App\Enums\RecipientStatus;
use App\Enums\SigningOrder;
use App\Enums\SigningSessionStatus;
use App\Models\AuditEvent;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Models\RecipientAccessLink;
use App\Models\SignatureAcceptance;
use App\Models\SigningSession;
use App\Services\Signing\Contracts\SignerNotifications;
use Illuminate\Support\Facades\File;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/Support/SignerHelpers.php';

/*
|--------------------------------------------------------------------------
| Recusa (arquitetura §4.6, ROUTES §3.3)
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->work = storage_path('framework/testing/signer-refuse-'.uniqid());
    File::ensureDirectoryExists($this->work);
    signerDisk($this->work);
    $this->withoutVite();
    $this->codes = signerCaptureCodes();
});

afterEach(function () {
    File::deleteDirectory($this->work ?? '');
});

it('exige motivo entre 10 e 500 caracteres', function () {
    $ctx = signerEnvelope();
    $token = $ctx['tokens']['maria@exemplo.test'];
    authenticateSigner($this, $token);

    $this->post(route('sign.refuse', ['token' => $token]), ['reason' => ''])
        ->assertSessionHasErrors('reason');

    $this->post(route('sign.refuse', ['token' => $token]), ['reason' => 'não'])
        ->assertSessionHasErrors('reason');

    $this->post(route('sign.refuse', ['token' => $token]), ['reason' => str_repeat('a', 501)])
        ->assertSessionHasErrors('reason');

    expect($ctx['recipients']['maria@exemplo.test']->fresh()->status)->toBe(RecipientStatus::Viewed);
});

it('recusa encerra o envelope, cancela os pendentes e revoga os links dos demais', function () {
    $ctx = signerEnvelope([
        ['name' => 'Maria Alves', 'email' => 'maria@exemplo.test', 'order' => 1],
        ['name' => 'Carlos Mendes', 'email' => 'carlos@exemplo.test', 'order' => 1],
    ], SigningOrder::Parallel);

    $token = $ctx['tokens']['maria@exemplo.test'];
    authenticateSigner($this, $token);

    $this->post(route('sign.refuse', ['token' => $token]), [
        'reason' => 'O valor do aluguel está diferente do combinado.',
    ])->assertRedirect(route('sign.show', ['token' => $token]));

    $maria = $ctx['recipients']['maria@exemplo.test']->fresh();
    $carlos = $ctx['recipients']['carlos@exemplo.test']->fresh();

    expect($maria->status)->toBe(RecipientStatus::Refused)
        ->and($maria->refusal_reason)->toBe('O valor do aluguel está diferente do combinado.')
        ->and($maria->refused_at)->not->toBeNull()
        // Quem não recusou fica `canceled`, nunca `refused`.
        ->and($carlos->status)->toBe(RecipientStatus::Canceled)
        ->and($ctx['envelope']->fresh()->status)->toBe(EnvelopeStatus::Refused);

    // Nenhum link de TERCEIRO sobrevive: quem não recusou perde o convite na hora.
    expect(RecipientAccessLink::query()
        ->where('envelope_id', $ctx['envelope']->id)
        ->where('recipient_id', '!=', $maria->id)
        ->whereNull('revoked_at')
        ->count())->toBe(0);

    // O link de CONVITE de quem recusou continua resolvendo — é ele que mostra a tela
    // `refused` exigida por ROUTES §3.3 ("Você recusou assinar este documento em {data}"
    // + motivo). Ele não abre nada: a sessão foi revogada e o estado é terminal.
    // O filtro por `purpose` é necessário porque confirmar o código também abre a janela
    // de download (`purpose = download`, arquitetura §4.7), que é outro tipo de link.
    expect(RecipientAccessLink::query()
        ->where('recipient_id', $maria->id)
        ->where('purpose', AccessLinkPurpose::Signing->value)
        ->whereNull('revoked_at')
        ->count())->toBe(1);

    $tela = $this->get(route('sign.show', ['token' => $token]))->assertOk()->viewData('page')['props'];

    expect($tela['screen'])->toBe('refused')
        ->and($tela['refusal']['reason'])->toBe('O valor do aluguel está diferente do combinado.')
        ->and($tela['document'])->toBeNull();

    // O de Carlos, esse sim, deixou de existir para o mundo.
    $this->get(route('sign.show', ['token' => $ctx['tokens']['carlos@exemplo.test']]))->assertNotFound();

    // Sessões encerradas.
    expect(SigningSession::query()->where('recipient_id', $maria->id)->get()->every(
        fn (SigningSession $s) => $s->status === SigningSessionStatus::Revoked,
    ))->toBeTrue();

    expect(AuditEvent::query()->where('event_type', AuditEventType::RecipientRefused->value)->exists())->toBeTrue()
        ->and(AuditEvent::query()->where('event_type', AuditEventType::EnvelopeRefused->value)->exists())->toBeTrue();
});

it('a trilha guarda a medida do motivo, não o texto livre do público', function () {
    $ctx = signerEnvelope();
    $token = $ctx['tokens']['maria@exemplo.test'];
    authenticateSigner($this, $token);

    $motivo = 'Meu nome está grafado errado na cláusula terceira.';

    $this->post(route('sign.refuse', ['token' => $token]), ['reason' => $motivo])->assertRedirect();

    $evento = AuditEvent::query()->where('event_type', AuditEventType::RecipientRefused->value)->sole();

    expect($evento->payload['reason_length'])->toBe(mb_strlen($motivo))
        ->and(json_encode($evento->payload))->not->toContain($motivo)
        // O motivo em si fica onde ele é útil: na coluna do destinatário.
        ->and($ctx['recipients']['maria@exemplo.test']->fresh()->refusal_reason)->toBe($motivo);
});

it('depois da recusa nenhum aceite entra, nem por uma aba já aberta', function () {
    $ctx = signerEnvelope();
    $token = $ctx['tokens']['maria@exemplo.test'];

    // A "aba aberta": sessão autenticada e token de autorização já emitidos.
    $props = authenticateSigner($this, $token);
    $autorizacao = $props['authorization']['token'];

    $this->post(route('sign.refuse', ['token' => $token]), [
        'reason' => 'Preciso revisar com meu advogado antes de assinar.',
    ])->assertRedirect();

    // O link de quem recusou continua resolvendo (é o que mostra a tela `refused`), mas a
    // SESSÃO de assinatura foi revogada no mesmo instante: `signer.verified` devolve a aba
    // para a tela em vez de deixar o POST chegar ao serviço. O token de autorização que a
    // aba ainda tem em mãos não vale nada sem sessão.
    $this->post(route('sign.complete', ['token' => $token]), [
        'authorization' => $autorizacao,
        'consent' => true,
        'signature' => ['method' => 'draw', 'image_base64' => pngDataUri()],
    ])->assertRedirect(route('sign.show', ['token' => $token]));

    expect(SignatureAcceptance::query()->count())->toBe(0);

    // E mesmo que a sessão voltasse: pedir um código novo é recusado fora do estado ativo.
    $this->post(route('sign.otp.send', ['token' => $token]))->assertSessionHasErrors('otp');

    expect($this->get(route('sign.show', ['token' => $token]))->assertOk()
        ->viewData('page')['props']['screen'])->toBe('refused');
});

it('não deixa recusar depois de assinar', function () {
    $ctx = signerEnvelope();
    $token = $ctx['tokens']['maria@exemplo.test'];
    $props = authenticateSigner($this, $token);

    $this->post(route('sign.complete', ['token' => $token]), [
        'authorization' => $props['authorization']['token'],
        'consent' => true,
        'signature' => ['method' => 'draw', 'image_base64' => pngDataUri()],
    ])->assertRedirect();

    // A sessão foi consumida no aceite: a recusa nem chega ao serviço.
    $this->post(route('sign.refuse', ['token' => $token]), [
        'reason' => 'Mudei de ideia depois de assinar.',
    ])->assertRedirect(route('sign.show', ['token' => $token]))
        ->assertSessionHas('error');

    expect($ctx['recipients']['maria@exemplo.test']->fresh()->status)->toBe(RecipientStatus::Signed);
});

it('exige identidade confirmada para recusar', function () {
    $ctx = signerEnvelope();
    $token = $ctx['tokens']['maria@exemplo.test'];

    $this->post(route('sign.refuse', ['token' => $token]), [
        'reason' => 'Não reconheço este documento.',
    ])->assertRedirect(route('sign.show', ['token' => $token]));

    expect($ctx['recipients']['maria@exemplo.test']->fresh()->status)->not->toBe(RecipientStatus::Refused);
});

it('avisa o remetente e os cancelados pelo contrato de notificações', function () {
    $spy = new class implements SignerNotifications
    {
        public ?string $refusedBy = null;

        /** @var array{0: string, 1: list<string>}|null */
        public ?array $closed = null;

        public function inviteRecipients(Envelope $envelope, array $recipients): void {}

        public function notifySenderSigned(Envelope $envelope, Recipient $signedBy): void {}

        public function notifySenderRefused(Envelope $envelope, Recipient $refusedBy): void
        {
            $this->refusedBy = $refusedBy->email;
        }

        public function notifyEnvelopeClosed(Envelope $envelope, array $canceled, string $reason): void
        {
            $this->closed = [$reason, array_map(fn (Recipient $r) => $r->email, $canceled)];
        }
    };

    app()->instance(SignerNotifications::class, $spy);

    $ctx = signerEnvelope([
        ['name' => 'Maria Alves', 'email' => 'maria@exemplo.test', 'order' => 1],
        ['name' => 'Carlos Mendes', 'email' => 'carlos@exemplo.test', 'order' => 1],
    ], SigningOrder::Parallel);

    $token = $ctx['tokens']['maria@exemplo.test'];
    authenticateSigner($this, $token);

    $this->post(route('sign.refuse', ['token' => $token]), [
        'reason' => 'A metragem do imóvel está incorreta.',
    ])->assertRedirect();

    expect($spy->refusedBy)->toBe('maria@exemplo.test')
        ->and($spy->closed)->toBe(['recipient_refused', ['carlos@exemplo.test']]);
});
