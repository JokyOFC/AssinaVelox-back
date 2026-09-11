<?php

use App\Enums\AuditEventType;
use App\Enums\MembershipRole;
use App\Models\AuditEvent;
use App\Models\PublicForm;
use App\Models\PublicFormSubmission;
use App\Services\PublicForms\PublicFormSchema;
use App\Services\PublicForms\PublicFormStatus;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Pdf\Support\PdfFixtures;

require_once __DIR__.'/Support/PublicFormHelpers.php';

/*
| Gestão e isolamento (docs/fase-2/formulario-publico.md §3, §4 e §2).
*/

beforeEach(function () {
    Notification::fake();
    ['organization' => $this->organization, 'owner' => $this->owner] = createOrganizationWithOwner();
    publicFormsEnable($this->organization, participantRoles: true);
    $this->template = publicFormHtmlTemplate($this->organization, $this->owner);
    actingAsMember($this->owner, $this->organization);
});

afterEach(fn () => PdfFixtures::cleanup($this->work ?? null));

test('criar: rascunho com link imprevisível e configuração inicial; trilha da organização', function () {
    $this->post(route('public_forms.store'), ['template' => $this->template->ulid])->assertSessionHasNoErrors()->assertRedirect();

    $form = PublicForm::query()->sole();

    expect($form->status)->toBe(PublicFormStatus::Draft)
        ->and($form->public_token)->toMatch('/^[A-Za-z0-9]{40}$/')
        ->and($form->public_token)->not->toContain($form->ulid)
        ->and($form->publicVariables())->toBe(['nome', 'obs', 'valor'])
        ->and($form->destination->value)->toBe('review')
        ->and($form->responsible_user_id)->toBe($this->owner->id)
        ->and($form->toArray())->not->toHaveKey('public_token');

    $event = AuditEvent::query()->where('event_type', AuditEventType::PublicFormCreated->value)->sole();
    expect($event->envelope_id)->toBeNull()->and($event->payload['form'])->toBe($form->ulid);

    // Dois formulários nunca compartilham o link.
    $this->post(route('public_forms.store'), ['template' => $this->template->ulid]);
    expect(PublicForm::query()->pluck('public_token')->unique())->toHaveCount(2);
});

test('salvar: papel de quem preenche precisa ser signatário; demais papéis exigem participante fixo', function () {
    $template = templateHtml($this->organization, $this->owner, '<p>{{nome}}</p>', [
        ['key' => 'nome', 'label' => 'Nome', 'type' => 'text', 'required' => true],
    ], [
        ['ref' => 'a', 'name' => 'Contratante', 'participant_role' => 'signer'],
        ['ref' => 'b', 'name' => 'Testemunha', 'participant_role' => 'witness'],
    ], 'Contrato com testemunha');
    $roles = templateRoleIds($template);

    $this->post(route('public_forms.store'), ['template' => $template->ulid]);
    $form = PublicForm::query()->sole();

    $base = [
        'title' => 'Contrato', 'destination' => 'review', 'submissions_limit' => 10, 'submissions_period' => 'day',
        'public_variables' => ['nome'],
    ];

    $this->put(route('public_forms.update', $form), $base + ['filler_role' => $roles['Testemunha']])
        ->assertSessionHasErrors(['filler_role' => 'Quem preenche o formulário precisa ocupar um papel de signatário.']);

    $this->put(route('public_forms.update', $form), $base + ['filler_role' => $roles['Contratante']])
        ->assertSessionHasErrors("fixed_participants.{$roles['Testemunha']}.email");

    // Rascunho incompleto não publica.
    $this->post(route('public_forms.activate', $form))->assertSessionHasErrors('form');

    $this->put(route('public_forms.update', $form), $base + [
        'filler_role' => $roles['Contratante'],
        'fixed_participants' => [$roles['Testemunha'] => ['name' => 'Caio Testemunha', 'email' => 'caio@example.com']],
    ])->assertSessionHasNoErrors();

    $this->post(route('public_forms.activate', $form))->assertSessionHasNoErrors();
    expect($form->fresh()->status)->toBe(PublicFormStatus::Active);
});

test('salvar: variável inexistente e envio automático em modelo HTML são recusados', function () {
    $this->post(route('public_forms.store'), ['template' => $this->template->ulid]);
    $form = PublicForm::query()->sole();

    $input = [
        'title' => 'Ficha', 'destination' => 'review', 'submissions_limit' => 10, 'submissions_period' => 'day',
        'public_variables' => ['nome', 'obs', 'valor', 'senha'], 'filler_role' => $form->fillerRole(),
    ];

    $this->put(route('public_forms.update', $form), $input)->assertSessionHasErrors('public_variables');

    $input['public_variables'] = ['nome', 'obs', 'valor'];
    $this->put(route('public_forms.update', $form), ['destination' => 'auto_send'] + $input)->assertSessionHasErrors('destination');

    $this->put(route('public_forms.update', $form), ['expires_at' => now()->subDays(2)->format('Y-m-d')] + $input)->assertSessionHasErrors('expires_at');

    // Variável obrigatória que o público não preenche precisa de valor fixo.
    $this->put(route('public_forms.update', $form), ['public_variables' => ['obs', 'valor']] + $input)->assertSessionHasErrors('fixed_values.nome');
});

test('envio automático é aceito em PDF fixo com campo de assinatura', function () {
    $this->work = templatesWorkspace();
    templatesRequirePdftool();

    $template = publicFormPdfTemplate($this->organization, $this->owner, $this->work);
    $this->post(route('public_forms.store'), ['template' => $template->ulid]);
    $form = PublicForm::query()->sole();

    $this->put(route('public_forms.update', $form), [
        'title' => 'Adesão', 'destination' => 'auto_send', 'submissions_limit' => 10, 'submissions_period' => 'week',
        'public_variables' => [], 'filler_role' => $form->fillerRole(),
    ])->assertSessionHasNoErrors();

    expect($form->fresh()->destination->value)->toBe('auto_send');
});

test('pausar, retomar e revogar ficam na trilha; revogado não volta nem é alterado', function () {
    $form = publicFormFrom($this->organization, $this->owner, $this->template);

    $this->post(route('public_forms.pause', $form))->assertSessionHasNoErrors();
    $this->post(route('public_forms.activate', $form))->assertSessionHasNoErrors();
    $this->post(route('public_forms.revoke', $form))->assertSessionHasNoErrors();

    expect($form->fresh()->status)->toBe(PublicFormStatus::Revoked);

    $this->post(route('public_forms.activate', $form))->assertSessionHasErrors('form');
    $this->put(route('public_forms.update', $form), app(PublicFormSchema::class)->configInput($form))->assertSessionHasErrors('form');

    $types = AuditEvent::query()->where('organization_id', $this->organization->id)->pluck('event_type')->map(fn ($type) => $type->value)->all();

    expect($types)->toContain('public_form.paused', 'public_form.activated', 'public_form.revoked');
});

describe('autorização e isolamento', function () {
    test('operador (sem gerenciar modelos) não vê nem cria formulários', function () {
        $form = publicFormFrom($this->organization, $this->owner, $this->template);
        $operator = attachMember($this->organization, MembershipRole::Member);

        actingAsMember($operator, $this->organization);

        $this->get(route('public_forms.index'))->assertForbidden();
        $this->get(route('public_forms.edit', $form))->assertForbidden();
        $this->post(route('public_forms.store'), ['template' => $this->template->ulid])->assertForbidden();
        $this->post(route('public_forms.pause', $form))->assertForbidden();
    });

    test('formulários e envios de outra organização não existem (404) e não aparecem na lista', function () {
        $form = publicFormFrom($this->organization, $this->owner, $this->template);
        $submission = PublicFormSubmission::query()->create([
            'organization_id' => $this->organization->id,
            'public_form_id' => $form->id,
            'status' => 'pending_review',
            'email_digest' => str_repeat('0', 64),
        ]);

        ['organization' => $other, 'owner' => $otherOwner] = createOrganizationWithOwner();
        publicFormsEnable($other);
        $mine = publicFormFrom($other, $otherOwner, publicFormHtmlTemplate($other, $otherOwner));

        actingAsMember($otherOwner, $other);

        $this->get(route('public_forms.edit', $form))->assertNotFound();
        $this->put(route('public_forms.update', $form), [])->assertNotFound();
        $this->post(route('public_forms.pause', $form))->assertNotFound();
        $this->post(route('public_forms.revoke', $form))->assertNotFound();
        $this->post(route('public_forms.submissions.approve', $submission))->assertNotFound();
        $this->post(route('public_forms.submissions.reject', $submission))->assertNotFound();
        // Modelo de outra organização não serve para criar formulário.
        $this->post(route('public_forms.store'), ['template' => $this->template->ulid])->assertNotFound();

        $this->get(route('public_forms.index'))->assertInertia(fn ($page) => $page
            ->has('forms', 1)
            ->where('forms.0.id', $mine->ulid)
            ->has('queue', 0));

        expect($form->fresh()->status)->toBe(PublicFormStatus::Active);
    });

    test('a página pública mostra a organização dona do formulário', function () {
        $form = publicFormFrom($this->organization, $this->owner, $this->template);

        auth()->logout();

        $this->get(route('form_fill.show', ['token' => $form->public_token]))
            ->assertInertia(fn ($page) => $page->where('form.organization_name', $this->organization->name));
    });
});
