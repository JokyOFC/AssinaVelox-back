<?php

/*
|--------------------------------------------------------------------------
| Helpers dos testes da Fase 2 — presencial e lote (C-PRES)
|--------------------------------------------------------------------------
| Incluído com require_once pelos arquivos de teste. Não contém testes.
*/

use App\Enums\FieldType;
use App\Enums\SigningOrder;
use App\Models\Document;
use App\Models\Envelope;
use App\Models\Organization;
use App\Models\Recipient;
use App\Models\User;
use App\Services\Batch\Notifications\BatchCodeNotification;
use App\Services\Batch\Notifications\BatchLinkNotification;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/../../../Support/OrganizationHelpers.php';
require_once __DIR__.'/../../../Sign/Support/SignerHelpers.php';

if (! function_exists('presenceEnable')) {
    /**
     * Liga (ou desliga) as flags da área: interruptor global E plano vigente.
     */
    function presenceEnable(Organization $organization, bool $inPerson = true, bool $batch = true): void
    {
        config()->set('assinavelox.features.in_person', $inPerson);
        config()->set('assinavelox.features.batch_signing', $batch);

        $plan = $organization->currentSubscription()->with('plan')->first()?->plan;

        if ($plan === null) {
            return;
        }

        $features = (array) ($plan->features ?? []);
        $features['in_person'] = $inPerson;
        $features['batch_signing'] = $batch;
        $plan->forceFill(['features' => $features])->save();
    }
}

if (! function_exists('presenceTwoSigners')) {
    /**
     * Envelope enviado com Maria (assinatura) e João (assinatura + texto obrigatório).
     *
     * @return array<string, mixed>
     */
    function presenceTwoSigners(SigningOrder $order = SigningOrder::Parallel): array
    {
        $ctx = signerEnvelope([
            ['name' => 'Maria Alves Souza', 'email' => 'maria@exemplo.test', 'fields' => [FieldType::Signature]],
            ['name' => 'João Pereira Lima', 'email' => 'joao@exemplo.test', 'fields' => [FieldType::Signature, FieldType::Text]],
        ], $order);

        presenceEnable($ctx['organization']);

        $ctx['maria'] = $ctx['recipients']['maria@exemplo.test'];
        $ctx['joao'] = $ctx['recipients']['joao@exemplo.test'];
        $ctx['document'] = Document::query()->withoutGlobalScopes()->whereKey($ctx['version']->document_id)->firstOrFail();

        return $ctx;
    }
}

if (! function_exists('presenceKiosk')) {
    /**
     * @return array<string, mixed>
     */
    function presenceKiosk(object $test): array
    {
        return $test->get(route('in_person.kiosk.show'))->assertOk()->viewData('page')['props'];
    }
}

if (! function_exists('presenceStartKiosk')) {
    /**
     * O anfitrião abre a sessão presencial NESTE navegador e devolve as props do dispositivo.
     *
     * @return array<string, mixed>
     */
    function presenceStartKiosk(object $test, Organization $organization, User $host, Envelope $envelope, bool $keepSignedIn = false, string $label = 'Tablet do balcão'): array
    {
        actingAsMember($host, $organization);

        $test->post(route('in_person.store'), [
            'envelope' => $envelope->ulid,
            'device_label' => $label,
            'keep_signed_in' => $keepSignedIn,
        ])->assertRedirect(route('in_person.kiosk.show'));

        return presenceKiosk($test);
    }
}

if (! function_exists('presenceAuthenticate')) {
    /**
     * Chama o participante no dispositivo, pede e confirma o código DELE (lido do e-mail
     * capturado) e, por padrão, busca os PDFs — como o navegador faz ao abrir a tela.
     *
     * @return array<string, mixed>
     */
    function presenceAuthenticate(object $test, Recipient $recipient, bool $present = true): array
    {
        $test->post(route('in_person.kiosk.participant'), ['recipient' => $recipient->ulid])
            ->assertRedirect(route('in_person.kiosk.show'))
            ->assertSessionHasNoErrors();

        $test->post(route('in_person.kiosk.otp.send'))->assertRedirect()->assertSessionHasNoErrors();

        $codes = $test->codes;

        $test->post(route('in_person.kiosk.otp.verify'), ['code' => $codes[count($codes) - 1]])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $props = presenceKiosk($test);

        if ($present) {
            foreach ($props['participant']['signing']['documents'] as $document) {
                $test->get($document['pdf_url'])->assertOk();
            }
        }

        return $props;
    }
}

if (! function_exists('presenceAccept')) {
    /**
     * @param  array<string, mixed>  $props
     * @param  array<string, mixed>  $fields
     */
    function presenceAccept(object $test, array $props, array $fields = []): TestResponse
    {
        return $test->post(route('in_person.kiosk.complete'), [
            'authorization' => $props['participant']['signing']['authorization']['token'],
            'consent' => true,
            'signature' => ['method' => 'draw', 'image_base64' => pngDataUri()],
            'fields' => $fields,
        ]);
    }
}

if (! function_exists('presenceCaptureBatchCodes')) {
    /**
     * Códigos do lote, lidos do e-mail no momento do envio (o banco só tem o HMAC).
     *
     * @return ArrayObject<int, string>
     */
    function presenceCaptureBatchCodes(): ArrayObject
    {
        /** @var ArrayObject<int, string> $codes */
        $codes = new ArrayObject;

        Event::listen(NotificationSending::class, function (NotificationSending $event) use ($codes): void {
            if ($event->notification instanceof BatchCodeNotification) {
                $codes[] = $event->notification->code;
            }
        });

        return $codes;
    }
}

if (! function_exists('presenceCaptureBatchLinks')) {
    /**
     * URLs dos links de lote enviados (o token em claro só existe no e-mail).
     *
     * @return ArrayObject<int, string>
     */
    function presenceCaptureBatchLinks(): ArrayObject
    {
        /** @var ArrayObject<int, string> $urls */
        $urls = new ArrayObject;

        Event::listen(NotificationSending::class, function (NotificationSending $event) use ($urls): void {
            if ($event->notification instanceof BatchLinkNotification) {
                $urls[] = $event->notification->url;
            }
        });

        return $urls;
    }
}

if (! function_exists('batchScenario')) {
    /**
     * Três envelopes pendentes para Maria na organização Horizonte (um deles com campo de
     * texto obrigatório) e um na organização Outra, com o mesmo e-mail.
     *
     * @return array<string, mixed>
     */
    function batchScenario(): array
    {
        ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner(['name' => 'Horizonte Imóveis']);
        presenceEnable($organization);

        $one = signerEnvelope([['name' => 'Maria Alves Souza', 'email' => 'maria@exemplo.test', 'fields' => [FieldType::Signature]]], SigningOrder::Parallel, ['title' => 'Contrato de locação'], $organization, $owner);
        $two = signerEnvelope([['name' => 'Maria Alves Souza', 'email' => 'Maria@Exemplo.test', 'fields' => [FieldType::Signature, FieldType::Text]]], SigningOrder::Parallel, ['title' => 'Termo de vistoria'], $organization, $owner);
        $three = signerEnvelope([['name' => 'Maria Alves Souza', 'email' => 'maria@exemplo.test', 'fields' => [FieldType::Signature]]], SigningOrder::Parallel, ['title' => 'Termo de entrega de chaves'], $organization, $owner);

        ['organization' => $other, 'owner' => $otherOwner] = createOrganizationWithOwner(['name' => 'Outra Conta']);
        $foreign = signerEnvelope([['name' => 'Maria Alves Souza', 'email' => 'maria@exemplo.test', 'fields' => [FieldType::Signature]]], SigningOrder::Parallel, ['title' => 'Contrato de outra conta'], $other, $otherOwner);

        return [
            'organization' => $organization,
            'owner' => $owner,
            'other' => $other,
            'other_owner' => $otherOwner,
            'one' => $one,
            'two' => $two,
            'three' => $three,
            'foreign' => $foreign,
            'maria_one' => array_values($one['recipients'])[0],
            'maria_two' => array_values($two['recipients'])[0],
            'maria_three' => array_values($three['recipients'])[0],
            'maria_foreign' => array_values($foreign['recipients'])[0],
        ];
    }
}

if (! function_exists('batchIssue')) {
    function batchIssue(object $test, Organization $organization, User $owner, Envelope $envelope, Recipient $recipient): TestResponse
    {
        actingAsMember($owner, $organization);

        return $test->post(route('envelopes.recipients.batch', ['envelope' => $envelope->ulid, 'recipient' => $recipient->ulid]));
    }
}

if (! function_exists('batchAsParticipant')) {
    /**
     * Outro navegador, sem conta: descarta o login e a sessão do remetente.
     */
    function batchAsParticipant(object $test): void
    {
        $test->flushSession();
        app('auth')->forgetGuards();
    }
}

if (! function_exists('batchAuthenticate')) {
    /**
     * Abre o link do e-mail, pede e confirma o código do lote e devolve as props da lista.
     *
     * @return array<string, mixed>
     */
    function batchAuthenticate(object $test, string $url): array
    {
        $test->get($url)->assertRedirect(route('sign.batch.show'));
        $test->post(route('sign.batch.otp.send'))->assertRedirect()->assertSessionHasNoErrors();

        $codes = $test->batchCodes;

        $test->post(route('sign.batch.otp.verify'), ['code' => $codes[count($codes) - 1]])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        return $test->get(route('sign.batch.show'))->assertOk()->viewData('page')['props'];
    }
}

if (! function_exists('batchOpen')) {
    /**
     * Abre um item e devolve `current` (documento pronto para revisão), buscando os PDFs.
     *
     * @return array<string, mixed>
     */
    function batchOpen(object $test, string $itemUlid, bool $present = true): array
    {
        $test->post(route('sign.batch.items.open', ['item' => $itemUlid]))
            ->assertRedirect(route('sign.batch.show', ['item' => $itemUlid]))
            ->assertSessionHasNoErrors();

        $props = $test->get(route('sign.batch.show', ['item' => $itemUlid]))->assertOk()->viewData('page')['props'];

        expect($props['screen'])->toBe('item')
            ->and($props['current']['id'])->toBe($itemUlid);

        if ($present) {
            foreach ($props['current']['documents'] as $document) {
                $test->get($document['pdf_url'])->assertOk();
            }
        }

        return $props['current'];
    }
}

if (! function_exists('batchAuthorize')) {
    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $fields
     * @param  array<string, mixed>  $extra
     */
    function batchAuthorize(object $test, string $itemUlid, array $current, array $fields = [], array $extra = []): TestResponse
    {
        return $test->post(route('sign.batch.items.authorize', ['item' => $itemUlid]), array_merge([
            'authorization' => $current['authorization']['token'],
            'consent' => true,
            'signature' => ['method' => 'draw', 'image_base64' => pngDataUri()],
            'fields' => $fields,
        ], $extra));
    }
}
