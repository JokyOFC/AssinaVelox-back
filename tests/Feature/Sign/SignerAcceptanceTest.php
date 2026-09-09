<?php

use App\Enums\AuditEventType;
use App\Enums\EnvelopeStatus;
use App\Enums\FieldType;
use App\Enums\RecipientStatus;
use App\Enums\SignatureKind;
use App\Enums\SigningOrder;
use App\Enums\SigningSessionStatus;
use App\Events\EnvelopeReadyForFinalization;
use App\Models\AuditEvent;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Models\SignatureAcceptance;
use App\Models\SigningField;
use App\Models\SigningFieldValue;
use App\Models\SigningSession;
use App\Services\Signing\Contracts\SignerNotifications;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/Support/SignerHelpers.php';

/*
|--------------------------------------------------------------------------
| Aceite eletrônico (arquitetura §4.5, §3.3)
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->work = storage_path('framework/testing/signer-accept-'.uniqid());
    File::ensureDirectoryExists($this->work);
    signerDisk($this->work);
    $this->withoutVite();
    $this->codes = signerCaptureCodes();
});

afterEach(function () {
    File::deleteDirectory($this->work ?? '');
});

it('grava o aceite com evidências do servidor e consome a sessão', function () {
    $ctx = signerEnvelope([[
        'name' => 'Maria Alves Souza',
        'email' => 'maria@exemplo.test',
        'fields' => [FieldType::Signature, FieldType::Name, FieldType::Date, FieldType::Text],
    ]]);

    $token = $ctx['tokens']['maria@exemplo.test'];
    $props = authenticateSigner($this, $token);

    $fields = collect($props['my_fields'])->keyBy('type');

    $response = $this->post(route('sign.complete', ['token' => $token]), [
        'authorization' => $props['authorization']['token'],
        'consent' => true,
        'signature' => ['method' => 'draw', 'image_base64' => pngDataUri()],
        'fields' => [
            $fields['name']['id'] => 'Maria A. Souza',
            // O cliente manda uma data absurda: o servidor carimba a dele.
            $fields['date']['id'] => '01/01/1999',
            $fields['text']['id'] => 'Apto 302',
        ],
    ], ['HTTP_USER_AGENT' => 'Mozilla/5.0 (Teste)']);

    $response->assertRedirect(route('sign.show', ['token' => $token]));

    /** @var SignatureAcceptance $acceptance */
    $acceptance = SignatureAcceptance::query()->sole();

    expect($acceptance->document_sha256)->toBe($ctx['version']->sha256)
        ->and($acceptance->document_version_id)->toBe($ctx['version']->id)
        ->and($acceptance->terms_version)->toBe('v1-2026-09-08')
        ->and($acceptance->auth_method->value)->toBe('email_otp')
        ->and($acceptance->signature_kind)->toBe(SignatureKind::Drawn)
        ->and($acceptance->user_agent)->toBe('Mozilla/5.0 (Teste)')
        ->and($acceptance->ip_address)->not->toBeNull()
        ->and($acceptance->consent_statement)->toContain('Maria Alves Souza')
        ->and($acceptance->consent_statement)->toContain($ctx['version']->sha256)
        ->and($acceptance->accepted_at->utcOffset())->toBe(0);

    // A data é a do servidor, no fuso da organização — nunca a enviada pelo cliente.
    $dataGravada = SigningFieldValue::query()
        ->whereHas('field', fn ($q) => $q->where('type', FieldType::Date->value))
        ->value('value_text');

    expect($dataGravada)->toBe(now()->setTimezone($ctx['organization']->timezone)->format('d/m/Y'))
        ->and($dataGravada)->not->toBe('01/01/1999');

    // A imagem foi reprocessada e gravada em disco privado; o snapshot referencia o caminho.
    expect(Storage::disk('documents')->exists($acceptance->signature_image_path))->toBeTrue()
        ->and($acceptance->fields_snapshot['document']['sha256'])->toBe($ctx['version']->sha256)
        ->and($acceptance->fields_snapshot['values'])->toHaveCount(4);

    $recipient = $ctx['recipients']['maria@exemplo.test']->fresh();
    expect($recipient->status)->toBe(RecipientStatus::Signed)
        ->and($recipient->signed_at)->not->toBeNull();

    // A sessão foi consumida: a mesma aba não assina de novo.
    expect(SigningSession::query()->sole()->status)->toBe(SigningSessionStatus::Consumed);

    expect(AuditEvent::query()->where('event_type', AuditEventType::AcceptanceRecorded->value)->exists())->toBeTrue();
});

it('recusa o aceite sem o token de autorização e sem a declaração marcada', function () {
    $ctx = signerEnvelope();
    $token = $ctx['tokens']['maria@exemplo.test'];
    $props = authenticateSigner($this, $token);

    $this->post(route('sign.complete', ['token' => $token]), [
        'consent' => true,
        'signature' => ['method' => 'draw', 'image_base64' => pngDataUri()],
    ])->assertSessionHasErrors('authorization');

    $this->post(route('sign.complete', ['token' => $token]), [
        'authorization' => $props['authorization']['token'],
        'signature' => ['method' => 'draw', 'image_base64' => pngDataUri()],
    ])->assertSessionHasErrors('consent');

    expect(SignatureAcceptance::query()->count())->toBe(0);
});

it('recusa o aceite quando a tela apresentada mudou (campos alterados depois de renderizada)', function () {
    $ctx = signerEnvelope();
    $token = $ctx['tokens']['maria@exemplo.test'];
    $props = authenticateSigner($this, $token);

    // O remetente move o campo depois que a tela foi montada.
    SigningField::query()->where('envelope_id', $ctx['envelope']->id)->update(['x' => 0.5]);

    $this->post(route('sign.complete', ['token' => $token]), [
        'authorization' => $props['authorization']['token'],
        'consent' => true,
        'signature' => ['method' => 'draw', 'image_base64' => pngDataUri()],
    ])->assertSessionHasErrors('signature');

    expect(SignatureAcceptance::query()->count())->toBe(0);
});

it('bloqueia campo obrigatório em branco', function () {
    $ctx = signerEnvelope([[
        'name' => 'Maria Alves',
        'email' => 'maria@exemplo.test',
        'fields' => [FieldType::Signature, FieldType::Text],
    ]]);

    $token = $ctx['tokens']['maria@exemplo.test'];
    $props = authenticateSigner($this, $token);

    $this->post(route('sign.complete', ['token' => $token]), [
        'authorization' => $props['authorization']['token'],
        'consent' => true,
        'signature' => ['method' => 'draw', 'image_base64' => pngDataUri()],
        'fields' => [],
    ])->assertSessionHasErrors();

    expect(SignatureAcceptance::query()->count())->toBe(0)
        ->and(SigningFieldValue::query()->count())->toBe(0);
});

it('rejeita SVG, imagem gigante e base64 quebrado; normaliza PNG com metadados', function () {
    $ctx = signerEnvelope();
    $token = $ctx['tokens']['maria@exemplo.test'];
    $props = authenticateSigner($this, $token);

    $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><text>assinatura</text></svg>';

    $tentativas = [
        'svg' => base64_encode($svg),
        'quebrado' => 'isto-nao-e-base64!!!',
        // 5000×2000 = 10 MP, acima do limite de 8 MP lido do cabeçalho.
        'gigante' => base64_encode(pngBytes(5000, 2000)),
    ];

    foreach ($tentativas as $payload) {
        $this->post(route('sign.complete', ['token' => $token]), [
            'authorization' => $props['authorization']['token'],
            'consent' => true,
            'signature' => ['method' => 'upload', 'image_base64' => $payload],
        ])->assertSessionHasErrors('signature');
    }

    expect(SignatureAcceptance::query()->count())->toBe(0);

    // PNG legítimo com um chunk tEXt (metadado): aceito, mas reescrito sem o metadado.
    $marcador = 'SEGREDO-NO-METADADO';
    $comMetadado = pngWithTextChunk(pngBytes(200, 80), 'Comment', $marcador);

    expect(str_contains($comMetadado, $marcador))->toBeTrue();

    $this->post(route('sign.complete', ['token' => $token]), [
        'authorization' => $props['authorization']['token'],
        'consent' => true,
        'signature' => ['method' => 'upload', 'image_base64' => base64_encode($comMetadado)],
    ])->assertRedirect();

    $acceptance = SignatureAcceptance::query()->sole();
    $gravado = Storage::disk('documents')->get($acceptance->signature_image_path);

    expect($gravado)->not->toContain($marcador)
        ->and(substr($gravado, 1, 3))->toBe('PNG');
});

it('aceita assinatura digitada sem gravar imagem', function () {
    $ctx = signerEnvelope();
    $token = $ctx['tokens']['maria@exemplo.test'];
    $props = authenticateSigner($this, $token);

    $this->post(route('sign.complete', ['token' => $token]), [
        'authorization' => $props['authorization']['token'],
        'consent' => true,
        'signature' => ['method' => 'type', 'text' => 'Maria A. Souza', 'font' => 'caveat'],
    ])->assertRedirect();

    $acceptance = SignatureAcceptance::query()->sole();

    expect($acceptance->signature_kind)->toBe(SignatureKind::Typed)
        ->and($acceptance->typed_name)->toBe('Maria A. Souza')
        ->and($acceptance->typed_font)->toBe('caveat')
        ->and($acceptance->signature_image_path)->toBeNull();
});

it('grava um único aceite quando o mesmo POST chega duas vezes', function () {
    $ctx = signerEnvelope();
    $token = $ctx['tokens']['maria@exemplo.test'];
    $props = authenticateSigner($this, $token);

    $payload = [
        'authorization' => $props['authorization']['token'],
        'consent' => true,
        'signature' => ['method' => 'draw', 'image_base64' => pngDataUri()],
    ];

    $this->post(route('sign.complete', ['token' => $token]), $payload)->assertRedirect();

    // O segundo POST nem chega ao serviço: a sessão foi consumida no primeiro, então
    // `EnsureSignerVerified` devolve a pessoa à página com o aviso de sessão encerrada.
    $this->post(route('sign.complete', ['token' => $token]), $payload)
        ->assertRedirect(route('sign.show', ['token' => $token]))
        ->assertSessionHas('error');

    expect(SignatureAcceptance::query()->count())->toBe(1);
});

it('a corrida entre dois processos deixa um único aceite (UNIQUE recipient_id)', function () {
    $ctx = signerEnvelope();
    $recipient = $ctx['recipients']['maria@exemplo.test'];

    $linha = [
        'recipient_id' => $recipient->id,
        'envelope_id' => $ctx['envelope']->id,
        'document_version_id' => $ctx['version']->id,
        'organization_id' => $ctx['organization']->id,
        'accepted_at' => now(),
        'auth_method' => 'email_otp',
        'consent_statement' => 'Declaração.',
        'document_sha256' => $ctx['version']->sha256,
    ];

    SignatureAcceptance::query()->create($linha);

    expect(fn () => SignatureAcceptance::query()->create($linha))
        ->toThrow(QueryException::class);

    expect(SignatureAcceptance::query()->count())->toBe(1);
});

it('avança a vez no sequencial e não conclui o envelope antes da hora', function () {
    Event::fake([EnvelopeReadyForFinalization::class]);

    $ctx = signerEnvelope([
        ['name' => 'Maria Alves', 'email' => 'maria@exemplo.test'],
        ['name' => 'Carlos Mendes', 'email' => 'carlos@exemplo.test'],
    ], SigningOrder::Sequential);

    $token = $ctx['tokens']['maria@exemplo.test'];
    $props = authenticateSigner($this, $token);

    $this->post(route('sign.complete', ['token' => $token]), [
        'authorization' => $props['authorization']['token'],
        'consent' => true,
        'signature' => ['method' => 'draw', 'image_base64' => pngDataUri()],
    ])->assertRedirect();

    $envelope = $ctx['envelope']->fresh();

    expect($envelope->current_order)->toBe(2)
        // Continua `in_progress`: falta o Carlos. Nada de `completed` inventado.
        ->and($envelope->status)->toBe(EnvelopeStatus::InProgress);

    Event::assertNotDispatched(EnvelopeReadyForFinalization::class);

    // Carlos continua pendente de assinatura em qualquer caso. Emitir o link novo e escrever
    // o convite é do módulo de envio, pelo contrato SignerNotifications: com a implementação
    // registrada no AppServiceProvider ele passa a `notified`; sem binding, o fluxo público
    // apenas registra no log e o aceite continua válido — nenhum aceite é desfeito porque um
    // e-mail não saiu. A asserção é escrita para valer nos dois casos, para que este teste
    // não quebre conforme o binding entra ou sai; o teste seguinte cobre o contrato em si.
    $carlos = $ctx['recipients']['carlos@exemplo.test']->fresh();

    expect($carlos->status->isPendingSignature())->toBeTrue()
        ->and($carlos->status)->toBe(
            app()->bound(SignerNotifications::class) ? RecipientStatus::Notified : RecipientStatus::Pending,
        );
});

it('avisa o módulo de envio, pelo contrato, que chegou a vez do próximo', function () {
    $spy = new class implements SignerNotifications
    {
        /** @var list<string> */
        public array $invited = [];

        public function inviteRecipients(Envelope $envelope, array $recipients): void
        {
            foreach ($recipients as $recipient) {
                $this->invited[] = $recipient->email;
            }
        }

        public function notifySenderSigned(Envelope $envelope, Recipient $signedBy): void {}

        public function notifySenderRefused(Envelope $envelope, Recipient $refusedBy): void {}

        public function notifyEnvelopeClosed(Envelope $envelope, array $canceled, string $reason): void {}
    };

    app()->instance(SignerNotifications::class, $spy);

    $ctx = signerEnvelope([
        ['name' => 'Maria Alves', 'email' => 'maria@exemplo.test'],
        ['name' => 'Carlos Mendes', 'email' => 'carlos@exemplo.test'],
    ], SigningOrder::Sequential);

    $token = $ctx['tokens']['maria@exemplo.test'];
    $props = authenticateSigner($this, $token);

    $this->post(route('sign.complete', ['token' => $token]), [
        'authorization' => $props['authorization']['token'],
        'consent' => true,
        'signature' => ['method' => 'draw', 'image_base64' => pngDataUri()],
    ])->assertRedirect();

    expect($spy->invited)->toBe(['carlos@exemplo.test'])
        ->and($ctx['envelope']->fresh()->current_order)->toBe(2);
});

it('vai para finalizing e dispara o evento quando o último aceite entra — sem marcar completed', function () {
    Event::fake([EnvelopeReadyForFinalization::class]);

    $ctx = signerEnvelope([
        ['name' => 'Maria Alves', 'email' => 'maria@exemplo.test', 'order' => 1],
        ['name' => 'Carlos Mendes', 'email' => 'carlos@exemplo.test', 'order' => 1, 'status' => RecipientStatus::Signed],
    ], SigningOrder::Parallel);

    // Carlos já aceitou.
    SignatureAcceptance::query()->create([
        'recipient_id' => $ctx['recipients']['carlos@exemplo.test']->id,
        'envelope_id' => $ctx['envelope']->id,
        'document_version_id' => $ctx['version']->id,
        'organization_id' => $ctx['organization']->id,
        'accepted_at' => now()->subMinutes(5),
        'auth_method' => 'email_otp',
        'consent_statement' => 'Declaração.',
        'document_sha256' => $ctx['version']->sha256,
    ]);

    $token = $ctx['tokens']['maria@exemplo.test'];
    $props = authenticateSigner($this, $token);

    $this->post(route('sign.complete', ['token' => $token]), [
        'authorization' => $props['authorization']['token'],
        'consent' => true,
        'signature' => ['method' => 'draw', 'image_base64' => pngDataUri()],
    ])->assertRedirect();

    $envelope = $ctx['envelope']->fresh();

    expect($envelope->status)->toBe(EnvelopeStatus::Finalizing)
        // A conclusão é do incremento 4: nada de completed, de arquivo final nem de assinatura.
        ->and($envelope->completed_at)->toBeNull()
        ->and($envelope->final_document_version_id)->toBeNull()
        ->and($envelope->finalization_key)->not->toBeNull();

    Event::assertDispatched(EnvelopeReadyForFinalization::class, function (EnvelopeReadyForFinalization $event) use ($envelope) {
        return $event->envelopeId === $envelope->id
            && $event->organizationId === $envelope->organization_id
            && $event->finalizationKey === $envelope->finalization_key;
    });

    expect(AuditEvent::query()->where('event_type', AuditEventType::EnvelopeFinalizing->value)->exists())->toBeTrue()
        ->and(AuditEvent::query()->where('event_type', AuditEventType::EnvelopeCompleted->value)->exists())->toBeFalse();

    // A tela do signatário diz "em finalização", nunca "concluído" — e também não diz
    // "aguardando os outros", porque não falta ninguém (revisão adversarial).
    $props = $this->get(route('sign.show', ['token' => $token]))->viewData('page')['props'];
    expect($props['screen'])->toBe('finalizing')
        ->and($props['receipt']['pending_others'])->toBe(0)
        ->and($props['receipt']['final_pdf_available'])->toBeFalse();
});

it('não aceita assinatura depois que o prazo venceu, mesmo com sessão já aberta', function () {
    $ctx = signerEnvelope();
    $token = $ctx['tokens']['maria@exemplo.test'];
    $props = authenticateSigner($this, $token);

    $ctx['envelope']->forceFill(['expires_at' => now()->subMinute()])->save();

    $this->post(route('sign.complete', ['token' => $token]), [
        'authorization' => $props['authorization']['token'],
        'consent' => true,
        'signature' => ['method' => 'draw', 'image_base64' => pngDataUri()],
    ])->assertSessionHasErrors();

    expect(SignatureAcceptance::query()->count())->toBe(0)
        ->and($ctx['envelope']->fresh()->status)->toBe(EnvelopeStatus::Expired);
});

it('não aceita assinatura fora da vez no sequencial', function () {
    $ctx = signerEnvelope([
        ['name' => 'Maria Alves', 'email' => 'maria@exemplo.test'],
        ['name' => 'Carlos Mendes', 'email' => 'carlos@exemplo.test'],
    ], SigningOrder::Sequential);

    // Carlos tem link, mas a vez é da Maria: nem a página abre.
    $this->get(route('sign.show', ['token' => $ctx['tokens']['carlos@exemplo.test']]))->assertNotFound();
    $this->post(route('sign.otp.send', ['token' => $ctx['tokens']['carlos@exemplo.test']]))->assertNotFound();
    $this->post(route('sign.complete', ['token' => $ctx['tokens']['carlos@exemplo.test']]), [])->assertNotFound();

    expect(SignatureAcceptance::query()->count())->toBe(0);
});

it('não deixa código, token de sessão nem de autorização aparecerem na trilha', function () {
    $ctx = signerEnvelope();
    $token = $ctx['tokens']['maria@exemplo.test'];
    $props = authenticateSigner($this, $token);
    $autorizacao = $props['authorization']['token'];

    $this->post(route('sign.complete', ['token' => $token]), [
        'authorization' => $autorizacao,
        'consent' => true,
        'signature' => ['method' => 'draw', 'image_base64' => pngDataUri()],
    ])->assertRedirect();

    $trilha = AuditEvent::query()->get()
        ->map(fn (AuditEvent $e) => json_encode($e->payload))
        ->implode(' ');

    $sessao = session()->all();

    expect($trilha)->not->toContain($token)
        ->not->toContain($autorizacao)
        ->not->toContain($this->codes[0])
        // Nem no aceite gravado.
        ->and(json_encode(SignatureAcceptance::query()->sole()->getAttributes()))
        ->not->toContain($this->codes[0])
        ->not->toContain($autorizacao)
        ->and($sessao)->toBeArray();
});
