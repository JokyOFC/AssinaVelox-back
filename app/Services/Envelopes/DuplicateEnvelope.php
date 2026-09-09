<?php

namespace App\Services\Envelopes;

use App\Enums\ActorType;
use App\Enums\AuditEventType;
use App\Enums\DocumentProcessingStatus;
use App\Enums\EnvelopeStatus;
use App\Enums\RecipientStatus;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Models\SigningField;
use App\Models\User;
use App\Services\Documents\DocumentStorage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Duplica um envelope em um novo RASCUNHO independente (POST envelopes.duplicate).
 *
 * Copia: metadados, documento (linha + versão `original` **e** a versão exibível, com os
 * bytes no disco), destinatários e campos. NÃO copia: número, código de verificação, datas de envio,
 * aceites, valores de campo, links, sessões, trilha de auditoria nem status — o novo
 * envelope nasce `draft` com todo destinatário em `pending`.
 */
final class DuplicateEnvelope
{
    public function __construct(private readonly DocumentStorage $storage) {}

    public function handle(Envelope $source, User $user): Envelope
    {
        $source->loadMissing(['recipients', 'fields', 'document.originalVersion', 'document.currentVersion']);

        $copy = DB::transaction(function () use ($source, $user): Envelope {
            $settings = $source->settings ?? [];
            unset($settings['cancel_reason']);

            $copy = Envelope::query()->create([
                'organization_id' => $source->organization_id,
                'folder_id' => $source->folder_id,
                'created_by_user_id' => $user->getKey(),
                'title' => self::copyTitle($source->title),
                'message' => $source->message,
                'status' => EnvelopeStatus::Draft,
                'signing_order' => $source->signing_order,
                'current_order' => 1,
                'terms_version' => (string) config('assinavelox.terms_version'),
                'settings' => $settings,
            ]);

            $version = $this->copyDocument($source, $copy);

            $recipientMap = [];

            foreach ($source->recipients as $recipient) {
                $clone = new Recipient;
                $clone->forceFill([
                    'envelope_id' => $copy->getKey(),
                    'organization_id' => $copy->organization_id,
                    'name' => $recipient->name,
                    'email' => $recipient->email,
                    'phone' => $recipient->phone,
                    'role' => $recipient->role,
                    'role_label' => $recipient->role_label,
                    'order_index' => $recipient->order_index,
                    'status' => RecipientStatus::Pending,
                    'auth_method' => $recipient->auth_method,
                    'notification_count' => 0,
                    'last_notified_at' => null,
                    'signed_at' => null,
                    'refused_at' => null,
                    'refusal_reason' => null,
                ])->save();

                $recipientMap[$recipient->getKey()] = $clone->getKey();
            }

            if ($version !== null) {
                foreach ($source->fields as $field) {
                    $recipientId = $recipientMap[$field->recipient_id] ?? null;

                    if ($recipientId === null) {
                        continue;
                    }

                    $clone = new SigningField;
                    $clone->forceFill([
                        'envelope_id' => $copy->getKey(),
                        'organization_id' => $copy->organization_id,
                        'document_version_id' => $version->getKey(),
                        'recipient_id' => $recipientId,
                        'type' => $field->type,
                        'page' => $field->page,
                        'x' => $field->x,
                        'y' => $field->y,
                        'width' => $field->width,
                        'height' => $field->height,
                        'box_type' => $field->box_type,
                        'page_width_pt' => $field->page_width_pt,
                        'page_height_pt' => $field->page_height_pt,
                        'page_rotation' => $field->page_rotation,
                        'required' => $field->required,
                        'label' => $field->label,
                        'options' => $field->options,
                        'sort_order' => $field->sort_order,
                    ])->save();
                }
            }

            return $copy;
        });

        EnvelopeAudit::record($copy, AuditEventType::EnvelopeCreated, ['duplicated_from' => $source->ulid]);
        EnvelopeAudit::record($source, AuditEventType::EnvelopeDuplicated, ['copy' => $copy->ulid]);

        EnvelopeReadiness::refresh($copy);

        return $copy;
    }

    /**
     * "{título} (cópia)" respeitando o limite de 160 caracteres da coluna.
     */
    private static function copyTitle(string $title): string
    {
        $suffix = ' (cópia)';

        if (mb_strlen($title) + mb_strlen($suffix) <= 160) {
            return $title.$suffix;
        }

        return mb_substr($title, 0, 160 - mb_strlen($suffix)).$suffix;
    }

    /**
     * Copia o documento preservando **as duas** versões que importam: a `original`
     * (o arquivo que o remetente enviou) e a EXIBÍVEL (`documents.current_version_id`).
     *
     * Copiar sempre a `original` como exibível — o que este método fazia — quebrava toda
     * origem que passa pelo pipeline: para DOCX e imagem a `original` NÃO é um PDF, e a
     * cópia nascia `ready` apontando para bytes que a pré-visualização servia como
     * `application/pdf`, que o envio congelava em `sent_document_version_id` e cujo
     * sha256 ia parar na declaração de aceite. Sem `pages_meta`, ainda por cima, a
     * geometria dos campos passava a ser validada contra o A4 de fallback.
     *
     * Sem versão exibível (documento `failed`/`blocked`, ou `current_version_id` nulo) a
     * cópia nasce sem versão e fora de `ready`: o remetente envia o arquivo de novo.
     *
     * @return DocumentVersion|null a versão exibível da cópia (a que os campos referenciam)
     */
    private function copyDocument(Envelope $source, Envelope $copy): ?DocumentVersion
    {
        $document = $source->document;

        if ($document === null) {
            return null;
        }

        $displayable = $document->currentVersion;
        $original = $document->originalVersion;

        $ready = $displayable !== null
            && $document->processing_status === DocumentProcessingStatus::Ready;

        $clone = Document::query()->create([
            'envelope_id' => $copy->getKey(),
            'organization_id' => $copy->organization_id,
            'name' => $document->name,
            'original_filename' => $document->original_filename,
            'source_type' => $document->source_type,
            // Sem exibível não há o que preparar: a cópia não pode nascer "pronta".
            'processing_status' => $ready
                ? $document->processing_status
                : DocumentProcessingStatus::Uploaded,
            'failure_code' => $ready ? $document->failure_code : null,
            'failure_message' => $ready ? $document->failure_message : null,
            'page_count' => $ready ? $document->page_count : 0,
        ]);

        // `$ready` já garante que existe versão exibível.
        if (! $ready) {
            return null;
        }

        $number = 1;

        // A `original` só vira uma linha à parte quando a exibível é outra (DOCX/imagem
        // convertidos). Quando coincidem, uma versão basta — é o caso do PDF.
        if ($original !== null && $original->getKey() !== $displayable->getKey()) {
            $this->copyVersion($original, $clone, $copy, $number++);
        }

        $displayableClone = $this->copyVersion($displayable, $clone, $copy, $number);

        $clone->forceFill(['current_version_id' => $displayableClone->getKey()])->save();

        return $displayableClone;
    }

    /**
     * Duplica uma versão (linha + bytes) sob o documento da cópia.
     */
    private function copyVersion(
        DocumentVersion $source,
        Document $document,
        Envelope $envelope,
        int $versionNumber,
    ): DocumentVersion {
        return DocumentVersion::query()->create([
            'document_id' => $document->getKey(),
            'organization_id' => $document->organization_id,
            'version_number' => $versionNumber,
            'kind' => $source->kind,
            'storage_disk' => $source->storage_disk,
            'storage_path' => $this->copyBytes($source, $envelope),
            'mime_type' => $source->mime_type,
            'size_bytes' => $source->size_bytes,
            'sha256' => $source->sha256,
            'page_count' => $source->page_count,
            'pages_meta' => $source->pages_meta,
            'is_encrypted' => $source->is_encrypted,
            'has_signatures' => $source->has_signatures,
            'created_by_type' => ActorType::User,
            'created_by_id' => $envelope->created_by_user_id,
        ]);
    }

    /**
     * Duplica os bytes no MESMO esquema de caminho do resto do produto
     * ({@see DocumentStorage::pathFor()}): `orgs/{org}/envelopes/{env}/{version}.{ext}`.
     * O esquema antigo (`organizations/{id}/documents/{id}/…`) expunha ids internos e era
     * o único lugar do projeto que não passava por `DocumentStorage`.
     */
    private function copyBytes(DocumentVersion $source, Envelope $envelope): string
    {
        $disk = Storage::disk($source->storage_disk);

        if (! $disk->exists($source->storage_path)) {
            return $source->storage_path;
        }

        $target = $this->storage->pathFor(
            $envelope,
            $this->storage->newVersionUlid(),
            pathinfo($source->storage_path, PATHINFO_EXTENSION) ?: 'pdf',
        );

        $disk->copy($source->storage_path, $target);

        return $target;
    }
}
