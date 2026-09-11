<?php

use App\Enums\FieldType;
use App\Events\EnvelopeReadyForFinalization;
use App\Integrations\Contracts\CpfVerificationProvider;
use App\Models\SignatureAcceptance;
use App\Models\SigningField;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/OrganizationHelpers.php';
require_once __DIR__.'/../../Sign/Support/SignerHelpers.php';
require_once __DIR__.'/../../Phase2/Identity/Support/IdentityHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão adversarial da Fase 2, onda B — o aviso diz "apenas pelos dígitos" enquanto o CPF vai a um provedor
|--------------------------------------------------------------------------
| Com a flag `cpf_lookup`, RecordAcceptance chama CpfLookup::checkFields(): o CPF digitado é
| enviado a um provedor de consulta CADASTRAL (hoje o serviço próprio desabilitado, mas o
| contrato existe e o driver `bound` já liga qualquer adaptador) e o resultado fica no
| `fields_snapshot` e na trilha. O aviso de privacidade exibido ANTES do código e a declaração
| continuam dizendo que o CPF é "conferido apenas pelos dígitos" (ConsentText::withWaveBNotice(),
| app/Services/Signing/ConsentText.php:604) — o participante não é informado do compartilhamento
| com terceiro nem da finalidade (LGPD art. 9º). A integração corrigiu os textos para canal, PIN,
| CPF e fotos, mas não considerou a consulta cadastral.
*/

beforeEach(function () {
    $this->work = storage_path('app/tmp/tests/'.Str::ulid());
    signerDisk($this->work);
    $this->withoutVite();
    $this->codes = signerCaptureCodes();
    config()->set('inertia.ssr.enabled', false);
    Http::preventStrayRequests();
    Event::fake([EnvelopeReadyForFinalization::class]);
});

afterEach(function () {
    File::deleteDirectory($this->work ?? '');
});

it('com a consulta cadastral ligada, o aviso de privacidade continua dizendo "conferido apenas pelos dígitos"', function () {
    $ctx = signerEnvelope([
        ['name' => 'Maria Alves Souza', 'email' => 'maria@exemplo.test', 'fields' => [FieldType::Signature, FieldType::Cpf]],
    ]);
    identityEnableFlags($ctx['organization'], ['cpf_field', 'cpf_lookup']);

    $provider = new class implements CpfVerificationProvider
    {
        public int $calls = 0;

        public function verify(string $cpf, array $context = [], ?string $correlationId = null): array
        {
            $this->calls++;

            return ['status' => 'valid', 'provider' => 'provedor_revisao', 'checked_at' => Carbon::now()->toIso8601String(), 'details' => []];
        }

        public function isConfigured(): bool
        {
            return true;
        }

        public function name(): string
        {
            return 'provedor_revisao';
        }
    };

    config()->set('assinavelox.cpf_lookup.driver', 'bound');
    app()->instance(CpfVerificationProvider::class, $provider);

    $token = $ctx['tokens']['maria@exemplo.test'];

    // O que o participante lê antes de pedir o código.
    $identify = $this->get(route('sign.show', ['token' => $token]))->viewData('page')['props'];
    $notice = (string) $identify['privacy']['notice'];

    $props = authenticateSigner($this, $token);
    $field = SigningField::withoutOrganizationScope()->where('type', FieldType::Cpf->value)->sole();

    identityAccept($this, $token, $props, [$field->ulid => '529.982.247-25'])->assertSessionHasNoErrors();

    $acceptance = SignatureAcceptance::withoutOrganizationScope()->sole();
    $check = collect($acceptance->fields_snapshot['values'])->firstWhere('field_ulid', $field->ulid)['cpf_check'];

    // O CPF saiu para o provedor e o resultado ficou gravado…
    expect($provider->calls)->toBe(1)
        ->and($check['provider'])->toBe('provedor_revisao')
        ->and($check['status'])->toBe('verified');

    // …mas o aviso só fala dos dígitos e não menciona a consulta nem o provedor.
    expect($notice)->toContain('conferido apenas pelos dígitos')
        ->and(str_contains(mb_strtolower($notice), 'consulta cadastral'))->toBeTrue('O aviso de privacidade não informa a consulta cadastral do CPF a um provedor.');
});
