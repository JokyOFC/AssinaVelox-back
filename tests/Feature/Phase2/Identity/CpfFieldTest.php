<?php

use App\Enums\AuditEventType;
use App\Enums\FieldType;
use App\Events\EnvelopeReadyForFinalization;
use App\Integrations\Contracts\CpfVerificationProvider;
use App\Integrations\Cpf\CpfVerificationFactory;
use App\Integrations\Cpf\FakeCpfVerificationProvider;
use App\Integrations\Cpf\OwnServiceCpfVerificationProvider;
use App\Models\AuditEvent;
use App\Models\SignatureAcceptance;
use App\Models\SigningField;
use App\Models\SigningFieldValue;
use App\Services\Identity\CpfNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/OrganizationHelpers.php';
require_once __DIR__.'/../../Sending/Support/SendingHelpers.php';
require_once __DIR__.'/../../Sign/Support/SignerHelpers.php';
require_once __DIR__.'/Support/IdentityHelpers.php';

/*
|--------------------------------------------------------------------------
| Fase 2 §2.11 (C-ID) — campo `cpf`: dígitos no servidor, máscara fora do documento e
| consulta cadastral (classe B) só com a flag `cpf_lookup`
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->work = storage_path('app/tmp/tests/'.Str::ulid());
    signerDisk($this->work);
    $this->withoutVite();
    $this->codes = signerCaptureCodes();
    // Sem rede nos testes: o SSR do Inertia (que só tenta um servidor local) fica desligado
    // para que o bloqueio de requisições externas valha para as integrações de verdade.
    config()->set('inertia.ssr.enabled', false);
    Http::preventStrayRequests();
    Event::fake([EnvelopeReadyForFinalization::class]);
});

afterEach(function () {
    File::deleteDirectory($this->work ?? '');
});

/**
 * Envelope enviado com um signatário que tem assinatura + campo CPF obrigatório.
 *
 * @return array{ctx: array<string, mixed>, token: string, field: SigningField}
 */
function cpfEnvelope(): array
{
    $ctx = signerEnvelope([
        ['name' => 'Maria Alves Souza', 'email' => 'maria@exemplo.test', 'fields' => [FieldType::Signature, FieldType::Cpf]],
    ]);

    /** @var SigningField $field */
    $field = SigningField::withoutOrganizationScope()
        ->where('envelope_id', $ctx['envelope']->getKey())
        ->where('type', FieldType::Cpf->value)
        ->firstOrFail();

    return ['ctx' => $ctx, 'token' => $ctx['tokens']['maria@exemplo.test'], 'field' => $field];
}

/**
 * Provedor de consulta cadastral de teste, ligado ao contêiner (driver `bound`).
 */
function bindCpfProvider(string $status, ?Throwable $throw = null): object
{
    $provider = new class($status, $throw) implements CpfVerificationProvider
    {
        public int $calls = 0;

        public function __construct(private readonly string $status, private readonly ?Throwable $throw) {}

        public function verify(string $cpf, array $context = [], ?string $correlationId = null): array
        {
            $this->calls++;

            if ($this->throw !== null) {
                throw $this->throw;
            }

            // Um provedor real poderia devolver nome e nascimento: nada disso pode ser gravado.
            return ['status' => $this->status, 'provider' => 'provedor_teste', 'checked_at' => Carbon::now()->toIso8601String(), 'details' => ['name' => 'MARIA ALVES SOUZA', 'birth_date' => '1980-01-01']];
        }

        public function isConfigured(): bool
        {
            return true;
        }

        public function name(): string
        {
            return 'provedor_teste';
        }
    };

    config()->set('assinavelox.cpf_lookup.driver', 'bound');
    app()->instance(CpfVerificationProvider::class, $provider);

    return $provider;
}

it('recusa CPF com dígitos inválidos no servidor e grava o válido formatado', function () {
    ['token' => $token, 'field' => $field] = cpfEnvelope();
    $props = authenticateSigner($this, $token);

    foreach (['123.456.789-00', '111.111.111-11', '529.982.247-2X', '5299822472'] as $invalid) {
        identityAccept($this, $token, $props, [$field->ulid => $invalid])
            ->assertSessionHasErrors('fields.'.$field->ulid);
    }

    expect(SignatureAcceptance::withoutOrganizationScope()->count())->toBe(0);

    identityAccept($this, $token, $props, [$field->ulid => '529.982.247-25'])->assertSessionHasNoErrors();

    $value = SigningFieldValue::withoutOrganizationScope()->where('signing_field_id', $field->getKey())->firstOrFail();
    $acceptance = SignatureAcceptance::withoutOrganizationScope()->firstOrFail();
    $snapshot = collect($acceptance->fields_snapshot['values'])->firstWhere('field_ulid', $field->ulid);

    expect($value->value_text)->toBe('529.982.247-25')
        ->and($snapshot['cpf_masked'])->toBe('***.982.247-**')
        ->and($snapshot)->not->toHaveKey('cpf_check');
});

it('CPF obrigatório vazio é recusado; opcional vazio é aceito', function () {
    ['token' => $token, 'field' => $field] = cpfEnvelope();
    $props = authenticateSigner($this, $token);

    identityAccept($this, $token, $props, [$field->ulid => '  '])->assertSessionHasErrors('fields.'.$field->ulid);

    $field->forceFill(['required' => false])->save();
    $props = $this->get(route('sign.show', ['token' => $token]))->viewData('page')['props'];

    identityAccept($this, $token, $props, [])->assertSessionHasNoErrors();

    expect(SigningFieldValue::withoutOrganizationScope()->where('signing_field_id', $field->getKey())->value('value_text'))->toBeNull();
});

it('o CPF completo nunca vai para a verificação pública nem para a trilha', function () {
    ['ctx' => $ctx, 'token' => $token, 'field' => $field] = cpfEnvelope();
    identityEnableFlags($ctx['organization'], ['cpf_field', 'cpf_lookup']);
    bindCpfProvider('valid');

    $props = authenticateSigner($this, $token);
    identityAccept($this, $token, $props, [$field->ulid => '52998224725'])->assertSessionHasNoErrors();

    $verification = $this->get(route('verify.show', ['code' => $ctx['envelope']->verification_code]))->assertOk();
    $page = json_encode($verification->viewData('page'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $trail = identityAuditPayloads();

    foreach ([$page, $trail, (string) $verification->getContent()] as $haystack) {
        expect($haystack)->not->toContain('52998224725')
            ->and($haystack)->not->toContain('529.982.247-25');
    }

    $event = AuditEvent::query()->withoutGlobalScopes()->where('event_type', AuditEventType::CpfLookupPerformed->value)->firstOrFail();

    expect($event->payload['cpf_masked'])->toBe('***.982.247-**')
        ->and($event->payload['status'])->toBe('verified')
        ->and(CpfNumber::mask('123.456.789-09'))->toBe('***.456.789-**');
});

it('consulta cadastral só roda com a flag cpf_lookup, grava o resultado minimizado e nunca bloqueia', function () {
    // Flag desligada: o provedor não é chamado e o snapshot não muda.
    ['ctx' => $ctx, 'token' => $token, 'field' => $field] = cpfEnvelope();
    $spy = bindCpfProvider('valid');
    $props = authenticateSigner($this, $token);
    identityAccept($this, $token, $props, [$field->ulid => '52998224725'])->assertSessionHasNoErrors();

    expect($spy->calls)->toBe(0)
        ->and(AuditEvent::query()->withoutGlobalScopes()->where('event_type', AuditEventType::CpfLookupPerformed->value)->count())->toBe(0);

    // Flag ligada, provedor positivo: `verified` no snapshot, sem nome nem nascimento.
    ['ctx' => $ctx2, 'token' => $token2, 'field' => $field2] = cpfEnvelope();
    identityEnableFlags($ctx2['organization'], ['cpf_lookup']);
    $spy = bindCpfProvider('valid');
    $props2 = authenticateSigner($this, $token2);
    identityAccept($this, $token2, $props2, [$field2->ulid => '52998224725'])->assertSessionHasNoErrors();

    $acceptance = SignatureAcceptance::withoutOrganizationScope()->where('envelope_id', $ctx2['envelope']->getKey())->firstOrFail();
    $check = collect($acceptance->fields_snapshot['values'])->firstWhere('field_ulid', $field2->ulid)['cpf_check'];

    expect($spy->calls)->toBe(1)
        ->and($check['status'])->toBe('verified')
        ->and($check['provider'])->toBe('provedor_teste')
        ->and($check['simulated'])->toBeFalse()
        ->and($check['label'])->toStartWith('Consulta cadastral')
        ->and(json_encode($acceptance->fields_snapshot))->not->toContain('MARIA ALVES SOUZA')
        ->and(json_encode($acceptance->fields_snapshot))->not->toContain('1980-01-01');

    // Provedor que explode (ou esgota o tempo): aceite gravado, resultado `unavailable`.
    ['ctx' => $ctx3, 'token' => $token3, 'field' => $field3] = cpfEnvelope();
    identityEnableFlags($ctx3['organization'], ['cpf_lookup']);
    bindCpfProvider('valid', new RuntimeException('timeout'));
    $props3 = authenticateSigner($this, $token3);
    identityAccept($this, $token3, $props3, [$field3->ulid => '52998224725'])->assertSessionHasNoErrors();

    $acceptance3 = SignatureAcceptance::withoutOrganizationScope()->where('envelope_id', $ctx3['envelope']->getKey())->firstOrFail();
    $check3 = collect($acceptance3->fields_snapshot['values'])->firstWhere('field_ulid', $field3->ulid)['cpf_check'];

    expect($check3['status'])->toBe('unavailable')
        ->and($check3['reason_code'])->toBe('provider_error');
});

it('serviço próprio de CPF fica desabilitado: inconclusivo, sem rede e com a lista do que falta', function () {
    ['ctx' => $ctx, 'token' => $token, 'field' => $field] = cpfEnvelope();
    identityEnableFlags($ctx['organization'], ['cpf_lookup']);
    config()->set('assinavelox.cpf_lookup.driver', 'disabled');

    $props = authenticateSigner($this, $token);
    identityAccept($this, $token, $props, [$field->ulid => '52998224725'])->assertSessionHasNoErrors();

    $acceptance = SignatureAcceptance::withoutOrganizationScope()->firstOrFail();
    $check = collect($acceptance->fields_snapshot['values'])->firstWhere('field_ulid', $field->ulid)['cpf_check'];

    Http::assertNothingSent();

    expect($check['status'])->toBe('unavailable')
        ->and($check['provider'])->toBe(OwnServiceCpfVerificationProvider::NAME)
        ->and($check['reason_code'])->toBe('not_configured')
        ->and(app(OwnServiceCpfVerificationProvider::class)->isConfigured())->toBeFalse()
        ->and(OwnServiceCpfVerificationProvider::disabledMessage())->toContain('endpoint')
        ->and(OwnServiceCpfVerificationProvider::disabledMessage())->toContain('autenticação')
        ->and(OwnServiceCpfVerificationProvider::MISSING)->toHaveCount(8);
});

it('simulador de CPF é identificado, nunca afirma consulta positiva e é recusado em produção', function () {
    ['ctx' => $ctx, 'token' => $token, 'field' => $field] = cpfEnvelope();
    identityEnableFlags($ctx['organization'], ['cpf_lookup']);
    config()->set('assinavelox.cpf_lookup.driver', 'fake');
    config()->set('assinavelox.channels.allow_simulated', true);

    $props = authenticateSigner($this, $token);
    identityAccept($this, $token, $props, [$field->ulid => '52998224725'])->assertSessionHasNoErrors();

    $acceptance = SignatureAcceptance::withoutOrganizationScope()->firstOrFail();
    $check = collect($acceptance->fields_snapshot['values'])->firstWhere('field_ulid', $field->ulid)['cpf_check'];

    expect($check['simulated'])->toBeTrue()
        ->and($check['provider'])->toBe(FakeCpfVerificationProvider::NAME)
        ->and($check['status'])->not->toBe('verified')
        ->and($check['label'])->toEndWith('(simulado)');

    $previous = app()['env'];
    app()['env'] = 'production';

    try {
        expect(app(CpfVerificationFactory::class)->make())->toBeInstanceOf(OwnServiceCpfVerificationProvider::class);
    } finally {
        app()['env'] = $previous;
    }
});

it('um campo cpf já existente continua validado pelos dígitos com todas as flags desligadas', function () {
    ['token' => $token, 'field' => $field] = cpfEnvelope();
    $props = authenticateSigner($this, $token);

    identityAccept($this, $token, $props, [$field->ulid => '123.456.789-00'])->assertSessionHasErrors('fields.'.$field->ulid);
    identityAccept($this, $token, $props, [$field->ulid => '123.456.789-09'])->assertSessionHasNoErrors();
});
