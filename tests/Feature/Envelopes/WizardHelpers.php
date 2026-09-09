<?php

/*
|--------------------------------------------------------------------------
| Helpers dos testes do wizard (B-FIELDS)
|--------------------------------------------------------------------------
| Incluído com require_once pelos arquivos de teste. Não contém testes.
*/

use App\Enums\DocumentProcessingStatus;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Envelope;
use App\Models\Organization;
use App\Models\Recipient;
use App\Models\User;
use Database\Factories\DocumentVersionFactory;

if (! function_exists('draftWithDocument')) {
    /**
     * Rascunho com documento `ready` e versão exibível (pages_meta preenchido).
     *
     * @param  array<int, array<string, mixed>>|null  $pagesMeta
     */
    function draftWithDocument(
        Organization $organization,
        User $owner,
        int $pages = 3,
        ?array $pagesMeta = null,
        array $envelopeAttributes = [],
    ): Envelope {
        $envelope = Envelope::factory()->forOrganization($organization, $owner)->draft()->create($envelopeAttributes);

        $document = Document::factory()->forEnvelope($envelope)->create([
            'processing_status' => DocumentProcessingStatus::Ready,
            'page_count' => $pages,
        ]);

        $version = DocumentVersion::factory()->forDocument($document)->create([
            'page_count' => $pages,
            'pages_meta' => $pagesMeta ?? DocumentVersionFactory::pagesMeta($pages),
        ]);

        $document->forceFill(['current_version_id' => $version->id])->save();

        return $envelope->fresh();
    }
}

if (! function_exists('addRecipient')) {
    function addRecipient(Envelope $envelope, string $name, string $email, int $order = 1): Recipient
    {
        return Recipient::factory()->forEnvelope($envelope, $order)->create([
            'name' => $name,
            'email' => $email,
        ]);
    }
}

if (! function_exists('fieldPayload')) {
    /**
     * Campo de assinatura válido em uma página A4 retrato.
     *
     * @return array<string, mixed>
     */
    function fieldPayload(Recipient $recipient, array $overrides = []): array
    {
        return array_merge([
            'id' => null,
            'client_id' => 'tmp-'.$recipient->ulid,
            'recipient_id' => $recipient->ulid,
            'type' => 'signature',
            'page' => 1,
            'x' => 0.1,
            'y' => 0.1,
            'w' => 0.3,
            'h' => 0.06,
            'required' => true,
            'label' => null,
            'placeholder' => null,
        ], $overrides);
    }
}
