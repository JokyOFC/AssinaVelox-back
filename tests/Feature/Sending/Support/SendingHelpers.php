<?php

/*
|--------------------------------------------------------------------------
| Helpers dos testes de envio (B-SEND)
|--------------------------------------------------------------------------
| Incluído com require_once pelos arquivos de teste. Não contém testes.
*/

use App\Enums\DocumentProcessingStatus;
use App\Enums\SigningOrder;
use App\Integrations\Contracts\EmailProvider;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Envelope;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Recipient;
use App\Models\SigningField;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Documents\EnvelopeReadiness;
use Database\Factories\DocumentVersionFactory;
use Tests\Feature\Sending\Support\FakeEmailProvider;

if (! function_exists('readyEnvelope')) {
    /**
     * Envelope `ready`: documento processado com versão exibível, N signatários e um campo
     * de assinatura obrigatório para cada um — o mínimo que `EnvelopeReadiness` exige.
     *
     * @param  list<array{name: string, email: string}>  $recipients
     */
    function readyEnvelope(
        Organization $organization,
        User $owner,
        array $recipients = [['name' => 'Maria Alves', 'email' => 'maria@exemplo.com']],
        SigningOrder $order = SigningOrder::Sequential,
        array $envelopeAttributes = [],
    ): Envelope {
        $envelope = Envelope::factory()
            ->forOrganization($organization, $owner)
            ->draft()
            ->create(array_merge(['signing_order' => $order], $envelopeAttributes));

        $document = Document::factory()->forEnvelope($envelope)->create([
            'processing_status' => DocumentProcessingStatus::Ready,
            'page_count' => 2,
        ]);

        $version = DocumentVersion::factory()->forDocument($document)->create([
            'page_count' => 2,
            'pages_meta' => DocumentVersionFactory::pagesMeta(2),
        ]);

        $document->forceFill(['current_version_id' => $version->id])->save();

        foreach (array_values($recipients) as $index => $row) {
            $recipient = Recipient::factory()
                ->forEnvelope($envelope, $order === SigningOrder::Sequential ? $index + 1 : 1)
                ->create(['name' => $row['name'], 'email' => $row['email']]);

            SigningField::factory()->forRecipient($recipient, $version)->signature()->create();
        }

        $envelope = $envelope->fresh();

        app(EnvelopeReadiness::class)->recompute($envelope);

        return $envelope->fresh();
    }
}

if (! function_exists('subscriptionFor')) {
    /**
     * Assinatura vigente da organização (criada pelo CreateOrganization no cadastro).
     */
    function subscriptionFor(Organization $organization): Subscription
    {
        return Subscription::withoutOrganizationScope()
            ->where('organization_id', $organization->getKey())
            ->latest('id')
            ->firstOrFail();
    }
}

if (! function_exists('setPlanQuota')) {
    /**
     * Ajusta a cota do plano vigente (o plano free do seeder tem 5).
     */
    function setPlanQuota(Organization $organization, ?int $quota): Plan
    {
        $subscription = subscriptionFor($organization);
        $plan = $subscription->plan;
        $plan->forceFill(['envelope_quota' => $quota])->save();

        return $plan->refresh();
    }
}

if (! function_exists('fakeEmailProvider')) {
    /**
     * Substitui o provedor de e-mail por um duplo que registra as mensagens e permite
     * escolher o recibo devolvido (sent | unknown | failed).
     */
    function fakeEmailProvider(string $outcome = 'sent'): FakeEmailProvider
    {
        $fake = new FakeEmailProvider($outcome);

        app()->instance(EmailProvider::class, $fake);

        return $fake;
    }
}
