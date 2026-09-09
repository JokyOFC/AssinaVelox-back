<?php

namespace App\Services\Envelopes;

use App\Enums\DocumentProcessingStatus;
use App\Enums\FieldType;
use App\Enums\SigningOrder;
use App\Models\Document;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Models\SigningField;
use App\Services\Documents\EnvelopeReadiness as DocumentReadiness;
use Illuminate\Database\Eloquent\Collection;

/**
 * Completude do envelope em preparo e recálculo do estado (docs/arquitetura.md §3.2).
 *
 * `ready` exige documento `ready` com versão exibível, ≥ 1 destinatário e todo
 * destinatário com pelo menos um campo de assinatura nessa versão.
 *
 * Divisão com o pipeline documental: quem DECIDE e GRAVA o status é
 * `App\Services\Documents\EnvelopeReadiness` (contrato público do agente B-DOC, também
 * chamado ao fim da conversão). Esta classe é a camada do wizard: delega a decisão e
 * acrescenta a lista de PENDÊNCIAS em PT-BR exibida no passo 4. Assim existe um único
 * escritor do status e uma única definição de completude.
 */
final class EnvelopeReadiness
{
    /**
     * Pendências em PT-BR para o passo 4 do wizard, na ordem dos passos.
     *
     * @return list<string>
     */
    public static function issues(Envelope $envelope): array
    {
        return array_merge(
            self::documentIssues($envelope),
            self::recipientIssues($envelope),
            self::fieldIssues($envelope),
        );
    }

    public static function isReady(Envelope $envelope): bool
    {
        return self::issues($envelope) === [];
    }

    /**
     * Completude por passo (ROUTES §2.6 `completeness`) — delegada ao pipeline documental.
     *
     * @return array{document: bool, recipients: bool, fields: bool}
     */
    public static function completeness(Envelope $envelope): array
    {
        return app(DocumentReadiness::class)->completeness($envelope);
    }

    /**
     * Recalcula e persiste o status depois de qualquer alteração de preparo (metadados,
     * documento, destinatários ou campos). Devolve true quando o status mudou.
     */
    public static function refresh(Envelope $envelope): bool
    {
        $before = $envelope->status;

        $envelope->unsetRelation('recipients')->unsetRelation('fields')->unsetRelation('document');

        return app(DocumentReadiness::class)->recompute($envelope) !== $before;
    }

    // -- Pendências por passo ----------------------------------------------------------

    /**
     * @return list<string>
     */
    public static function documentIssues(Envelope $envelope): array
    {
        $document = self::document($envelope);

        if ($document === null) {
            return ['Envie o documento que será assinado.'];
        }

        return match ($document->processing_status) {
            DocumentProcessingStatus::Ready => $document->current_version_id === null
                ? ['O documento ainda não tem uma versão preparada para assinatura.']
                : [],
            DocumentProcessingStatus::Uploaded, DocumentProcessingStatus::Converting => ['O documento ainda está sendo processado.'],
            DocumentProcessingStatus::Failed => ['Falha ao processar o arquivo. Envie um PDF válido.'],
            DocumentProcessingStatus::Blocked => ['O arquivo está protegido por senha ou já assinado digitalmente. Envie outro PDF.'],
        };
    }

    /**
     * @return list<string>
     */
    public static function recipientIssues(Envelope $envelope): array
    {
        if (self::recipients($envelope)->isEmpty()) {
            return ['Adicione pelo menos um signatário.'];
        }

        // Mesma invariante que grava o status (DocumentReadiness::signingOrderIsCoherent):
        // a ordem escolhida no passo 1 precisa estar refletida nas vezes dos signatários.
        // Sem isso a plataforma anunciaria "Assinatura em ordem" numa lista que ela
        // convidaria de uma vez só — ou o contrário, deixando todos menos o primeiro sem
        // convite nenhum.
        if (! app(DocumentReadiness::class)->signingOrderIsCoherent($envelope)) {
            return [$envelope->signing_order === SigningOrder::Sequential
                ? 'A ordem de assinatura é sequencial, mas os signatários não estão em vezes distintas. Reabra o passo 2 e confirme a lista.'
                : 'A ordem de assinatura é em paralelo, mas os signatários estão em vezes diferentes. Reabra o passo 2 e confirme a lista.'];
        }

        return [];
    }

    /**
     * @return list<string>
     */
    public static function fieldIssues(Envelope $envelope): array
    {
        $recipients = self::recipients($envelope);

        if ($recipients->isEmpty()) {
            return [];
        }

        $document = self::document($envelope);
        $versionId = $document?->current_version_id;

        if ($versionId === null) {
            return [];
        }

        $fields = self::fields($envelope)
            ->filter(fn (SigningField $field): bool => $field->document_version_id === $versionId);

        $issues = [];

        $withSignature = $fields
            ->filter(fn (SigningField $field): bool => $field->type === FieldType::Signature && $field->required)
            ->pluck('recipient_id')
            ->unique();

        $missing = $recipients
            ->reject(fn (Recipient $recipient): bool => $withSignature->contains($recipient->getKey()))
            ->values();

        if ($missing->isNotEmpty()) {
            $issues[] = $missing->count() === $recipients->count()
                ? 'Todo signatário precisa de pelo menos um campo de assinatura.'
                : 'Sem campo de assinatura: '.$missing->pluck('name')->join(', ', ' e ').'.';
        }

        $pageCount = (int) $document->page_count;

        if ($pageCount > 0 && $fields->contains(fn (SigningField $field): bool => $field->page < 1 || $field->page > $pageCount)) {
            $issues[] = 'Há campos posicionados em páginas que não existem no documento.';
        }

        if ($fields->contains(fn (SigningField $field): bool => ! $field->hasValidGeometry())) {
            $issues[] = 'Há campos fora dos limites da página. Reposicione-os no passo 3.';
        }

        $recipientIds = $recipients->modelKeys();

        if ($fields->contains(fn (SigningField $field): bool => ! in_array($field->recipient_id, $recipientIds, true))) {
            $issues[] = 'Há campos atribuídos a signatários que não estão mais no documento.';
        }

        return $issues;
    }

    // -- Acesso às relações (usa o que já estiver carregado) ---------------------------

    private static function document(Envelope $envelope): ?Document
    {
        return $envelope->relationLoaded('document') ? $envelope->document : $envelope->document()->first();
    }

    /**
     * @return Collection<int, Recipient>
     */
    private static function recipients(Envelope $envelope): Collection
    {
        return $envelope->relationLoaded('recipients') ? $envelope->recipients : $envelope->recipients()->get();
    }

    /**
     * @return Collection<int, SigningField>
     */
    private static function fields(Envelope $envelope): Collection
    {
        return $envelope->relationLoaded('fields') ? $envelope->fields : $envelope->fields()->get();
    }
}
