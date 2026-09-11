<?php

use App\Enums\FieldType;
use App\Events\EnvelopeReadyForFinalization;
use App\Models\SigningField;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/OrganizationHelpers.php';
require_once __DIR__.'/../../Sign/Support/SignerHelpers.php';
require_once __DIR__.'/../../Phase2/Identity/Support/IdentityHelpers.php';
require_once __DIR__.'/../../Phase2/Org/Support/OrgHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão adversarial da Fase 2, onda B — CPF completo fora do documento
|--------------------------------------------------------------------------
| docs/fase-2/identidade.md §2.1: o CPF é gravado formatado porque é conteúdo do documento, e
| "Fora do documento só sai mascarado: ***.982.247-**". Mas a tela de detalhe do envelope
| (`envelopes.show`, EnvelopeController::show → SigningFieldResource, `'value' => value_text`,
| app/Http/Resources/SigningFieldResource.php:53) entrega o CPF COMPLETO nas props de todos os
| campos, inclusive o `cpf`. Isso também vale durante o "acessar como": `envelopes.show` está
| na lista de GETs permitidos ao suporte (ReadOnlyRoutes), cuja regra é justamente "o suporte não
| vê conteúdo de documentos" — e o suporte vê o CPF do participante.
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

/**
 * Envelope com CPF aceito pela participante.
 *
 * @return array<string, mixed>
 */
function review2bAcceptedCpf(object $test): array
{
    $ctx = signerEnvelope([
        ['name' => 'Maria Alves Souza', 'email' => 'maria@exemplo.test', 'fields' => [FieldType::Signature, FieldType::Cpf]],
        ['name' => 'Henrique Dias', 'email' => 'henrique@exemplo.test', 'fields' => [FieldType::Signature]],
    ]);

    $token = $ctx['tokens']['maria@exemplo.test'];
    $props = authenticateSigner($test, $token);
    $field = SigningField::withoutOrganizationScope()->where('type', FieldType::Cpf->value)->sole();

    identityAccept($test, $token, $props, [$field->ulid => '529.982.247-25'])->assertSessionHasNoErrors();

    $test->flushSession();

    return $ctx + ['cpf_field' => $field];
}

it('o detalhe do envelope entrega o CPF completo nas props, fora do documento', function () {
    $ctx = review2bAcceptedCpf($this);

    actingAsMember($ctx['owner'], $ctx['organization']);

    $props = $this->get(route('envelopes.show', $ctx['envelope']->ulid))->assertOk()->viewData('page')['props'];
    $json = (string) json_encode($props, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    expect(str_contains($json, '529.982.247-25'))->toBeFalse('O CPF completo sai nas props de envelopes.show (fields[].value).');
});

it('no "acessar como", o suporte da plataforma vê o CPF completo do participante', function () {
    platformEnableTools(['impersonation']);
    $ctx = review2bAcceptedCpf($this);

    $admin = User::factory()->platformAdmin()->create(['password' => 'senha-do-admin']);

    $this->actingAs($admin)
        ->post(route('admin.organizations.impersonate', $ctx['organization']->ulid), [
            'user' => $ctx['owner']->id,
            'reason' => 'Chamado #123 — cliente não encontra documento',
            'password' => 'senha-do-admin',
        ])
        ->assertRedirect(route('dashboard'));

    // Controle: o conteúdo do documento é negado ao suporte.
    $this->get(route('envelopes.document.preview', $ctx['envelope']->ulid))->assertForbidden();

    $response = $this->get(route('envelopes.show', $ctx['envelope']->ulid))->assertOk();

    expect(str_contains((string) $response->getContent(), '529.982.247-25'))->toBeFalse('O suporte, em "acessar como", lê o CPF completo em envelopes.show.');
});
