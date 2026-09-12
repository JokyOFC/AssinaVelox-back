<?php

use App\Models\Envelope;
use App\Models\PlanConsumption;
use App\Models\PublicFormSubmission;
use App\Services\PublicForms\Notifications\PublicFormConfirmationNotification;
use App\Services\PublicForms\SubmissionStatus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

require_once __DIR__.'/Support/PublicFormHelpers.php';

/*
| Antiabuso sem CAPTCHA (docs/fase-2/formulario-publico.md §6): limites por IP e por
| formulário, campo-isca, tempo mínimo de preenchimento, tamanho máximo, links pendentes por
| e-mail. Toda recusa: nenhum envio gravado e nenhum e-mail enviado.
*/

beforeEach(function () {
    Notification::fake();
    RateLimiter::clear('public-form-ip:127.0.0.1');
    ['organization' => $this->organization, 'owner' => $this->owner] = createOrganizationWithOwner(['name' => 'Imobiliária Aurora']);
    publicFormsEnable($this->organization);
    $this->form = publicFormFrom($this->organization, $this->owner, publicFormHtmlTemplate($this->organization, $this->owner));
    $this->values = ['nome' => 'Ana Souza', 'obs' => 'Apto 12', 'valor' => '1.500,00'];
});

function expectNothingStored(): void
{
    expect(PublicFormSubmission::withoutOrganizationScope()->count())->toBe(0)
        ->and(Envelope::withoutOrganizationScope()->count())->toBe(0);
    Notification::assertNothingSent();
}

test('envio válido: grava o envio pendente e manda o link — sem envelope nem consumo de cota', function () {
    publicFormSubmit($this->form, $this->values)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('form_fill.show', ['token' => $this->form->public_token]));

    $submission = PublicFormSubmission::withoutOrganizationScope()->sole();

    expect($submission->status)->toBe(SubmissionStatus::PendingConfirmation)
        ->and($submission->payload)->toBe(['name' => 'Ana Souza', 'email' => 'ana@example.com', 'values' => $this->values])
        ->and($submission->ip_address)->toBe('127.0.0.1')
        ->and($submission->privacy_notice_version)->toBe('v1-public-form-2026-09-11')
        ->and(Envelope::withoutOrganizationScope()->count())->toBe(0)
        ->and(PlanConsumption::withoutOrganizationScope()->count())->toBe(0);

    // O payload fica cifrado em repouso: nem o e-mail nem as respostas aparecem na coluna.
    $raw = (string) DB::table('public_form_submissions')->value('payload');
    expect($raw)->not->toContain('ana@example.com')->not->toContain('Apto 12');

    Notification::assertSentOnDemand(PublicFormConfirmationNotification::class, fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'ana@example.com');

    // A tela seguinte diz para conferir o e-mail, com o endereço mascarado.
    $this->get(route('form_fill.show', ['token' => $this->form->public_token]))
        ->assertInertia(fn ($page) => $page->where('screen', 'submitted')->where('submitted.email_hint', 'an***@example.com'));
});

test('campo-isca preenchido é recusado', function () {
    publicFormSubmit($this->form, $this->values, ['website' => 'https://spam.example'])->assertSessionHasErrors('form');

    expectNothingStored();
});

test('envio mais rápido que o tempo mínimo é recusado; carimbo adulterado, de outro formulário ou velho também', function () {
    publicFormSubmit($this->form, $this->values, ['started' => publicFormTimer($this->form, 0)])
        ->assertSessionHasErrors(['form' => 'O envio foi rápido demais. Confira as respostas e envie de novo.']);

    publicFormSubmit($this->form, $this->values, ['started' => 'nao-e-um-carimbo'])->assertSessionHasErrors('form');
    publicFormSubmit($this->form, $this->values, ['started' => Crypt::encryptString(json_encode(['f' => '01HZZZZZZZZZZZZZZZZZZZZZZZ', 't' => now()->getTimestamp() - 60]))])->assertSessionHasErrors('form');
    publicFormSubmit($this->form, $this->values, ['started' => publicFormTimer($this->form, 7 * 3600)])
        ->assertSessionHasErrors(['form' => 'Esta página ficou aberta por muito tempo. Recarregue-a e preencha de novo.']);
    publicFormSubmit($this->form, $this->values, ['started' => null])->assertSessionHasErrors('form');

    expectNothingStored();
});

test('o carimbo emitido pela página funciona depois do tempo mínimo', function () {
    // Relógio congelado: o FillTimer usa Carbon::now(). Com o relógio real, sob a suíte
    // paralela, abrir e enviar podia passar dos 3 s mínimos e o envio "rápido demais" era
    // aceito (falha intermitente vista na integração I-2D). Nenhuma asserção mudou.
    $this->freezeTime();

    $timer = $this->get(route('form_fill.show', ['token' => $this->form->public_token]))->viewData('page')['props']['antiabuse']['timer'];

    publicFormSubmit($this->form, $this->values, ['started' => $timer])->assertSessionHasErrors('form');

    $this->travel(5)->seconds();

    publicFormSubmit($this->form, $this->values, ['started' => $timer])->assertSessionHasNoErrors();
    expect(PublicFormSubmission::withoutOrganizationScope()->count())->toBe(1);
});

test('limite por IP no formulário: a partir da tentativa excedente, recusa com o tempo de espera', function () {
    config()->set('assinavelox.public_forms.rate.per_ip_form', 2);

    // Tentativas inválidas também contam.
    publicFormSubmit($this->form, $this->values, ['website' => 'x'])->assertSessionHasErrors('form');
    publicFormSubmit($this->form, $this->values, ['name' => ''])->assertSessionHasErrors('name');

    publicFormSubmit($this->form, $this->values)->assertSessionHasErrors(['form' => 'Muitas tentativas de envio. Tente de novo em 10 minutos.']);

    expectNothingStored();

    // Outro IP no mesmo formulário continua passando.
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.8']);
    publicFormSubmit($this->form, $this->values)->assertSessionHasNoErrors();
    expect(PublicFormSubmission::withoutOrganizationScope()->count())->toBe(1);
});

test('limite por formulário vale para qualquer origem (varredura distribuída)', function () {
    config()->set('assinavelox.public_forms.rate.per_form', 2);

    foreach (['10.0.0.1', '10.0.0.2'] as $index => $ip) {
        $this->withServerVariables(['REMOTE_ADDR' => $ip]);
        publicFormSubmit($this->form, $this->values, ['email' => "p{$index}@example.com"])->assertSessionHasNoErrors();
    }

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.3']);
    publicFormSubmit($this->form, $this->values, ['email' => 'p3@example.com'])->assertSessionHasErrors('form');

    expect(PublicFormSubmission::withoutOrganizationScope()->count())->toBe(2);
});

test('tamanho máximo: resposta longa demais para o campo é recusada', function () {
    publicFormSubmit($this->form, ['nome' => str_repeat('a', 501)] + $this->values)->assertSessionHasErrors('values.nome');
    publicFormSubmit($this->form, ['obs' => str_repeat('b', 5001)] + $this->values)->assertSessionHasErrors('values.obs');
    publicFormSubmit($this->form, ['nome' => ['array' => 'não']] + $this->values)->assertSessionHasErrors('values.nome');
    publicFormSubmit($this->form, $this->values, ['name' => str_repeat('n', 121)])->assertSessionHasErrors('name');

    expectNothingStored();
});

test('validação tipada do modelo: obrigatória ausente e tipo errado são recusados', function () {
    publicFormSubmit($this->form, ['nome' => 'Ana', 'valor' => 'dez reais'])->assertSessionHasErrors('values.valor');
    publicFormSubmit($this->form, ['valor' => '10,00'])->assertSessionHasErrors('values.nome');
    publicFormSubmit($this->form, $this->values, ['privacy' => null])->assertSessionHasErrors('privacy');
    publicFormSubmit($this->form, $this->values, ['email' => 'isso não é e-mail'])->assertSessionHasErrors('email');

    expectNothingStored();
});

test('payload malicioso: só as variáveis públicas são lidas; valor fixo e campos internos não mudam', function () {
    $form = publicFormFrom($this->organization, $this->owner, $this->form->template, [
        'public_variables' => ['nome', 'obs'],
        'fixed_values' => ['valor' => '2.000,00'],
    ]);

    publicFormSubmit($form, [
        'nome' => 'Ana Souza',
        'valor' => '1,00',
        'nao_existe' => 'x',
    ], [
        'organization_id' => 999,
        'status' => 'sent',
        'envelope_id' => 1,
        'destination' => 'auto_send',
    ])->assertSessionHasNoErrors();

    $submission = PublicFormSubmission::withoutOrganizationScope()->sole();

    expect($submission->payload['values'])->toBe(['nome' => 'Ana Souza'])
        ->and($submission->status)->toBe(SubmissionStatus::PendingConfirmation)
        ->and($submission->envelope_id)->toBeNull()
        ->and($submission->organization_id)->toBe($this->organization->id)
        ->and($form->fresh()->fixedValues())->toBe(['valor' => '2.000,00'])
        ->and($form->fresh()->destination->value)->toBe('review');
});

test('links pendentes para o mesmo e-mail têm teto', function () {
    config()->set('assinavelox.public_forms.pending_per_email', 1);

    publicFormSubmit($this->form, $this->values)->assertSessionHasNoErrors();
    publicFormSubmit($this->form, $this->values, ['email' => 'ANA@example.com'])->assertSessionHasErrors('email');

    expect(PublicFormSubmission::withoutOrganizationScope()->count())->toBe(1);
});

test('a página pública não expõe valores fixos, participantes fixos nem o ULID do formulário', function () {
    $template = templateHtml($this->organization, $this->owner, '<p>{{nome}} {{valor}}</p>', [
        ['key' => 'nome', 'label' => 'Nome', 'type' => 'text', 'required' => true],
        ['key' => 'valor', 'label' => 'Valor', 'type' => 'currency', 'required' => true],
    ], [
        ['ref' => 'a', 'name' => 'Cliente', 'participant_role' => 'signer'],
        ['ref' => 'b', 'name' => 'Corretor', 'participant_role' => 'signer'],
    ], 'Proposta');
    $roles = templateRoleIds($template);

    $form = publicFormFrom($this->organization, $this->owner, $template, [
        'public_variables' => ['nome'],
        'fixed_values' => ['valor' => '9.999,00'],
        'filler_role' => $roles['Cliente'],
        'fixed_participants' => [$roles['Corretor'] => ['name' => 'Bruno Corretor', 'email' => 'bruno@aurora.example']],
    ]);

    $response = $this->get(route('form_fill.show', ['token' => $form->public_token]))->assertOk();
    $json = json_encode($response->viewData('page')['props'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    expect($json)->not->toContain('9.999,00')
        ->not->toContain('bruno@aurora.example')
        ->not->toContain('Bruno Corretor')
        ->not->toContain($form->ulid)
        ->toContain('Imobiliária Aurora');

    $response->assertInertia(fn ($page) => $page
        ->where('form.role_name', 'Cliente')
        ->has('form.fields', 1)
        ->where('form.fields.0.key', 'nome')
        ->where('antiabuse.honeypot_field', 'website')
        ->where('privacy.version', 'v1-public-form-2026-09-11'));
});
