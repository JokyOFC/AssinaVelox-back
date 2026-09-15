<?php

namespace App\Services\Envelopes;

use App\Enums\DocumentProcessingStatus;
use App\Enums\FieldType;
use App\Enums\RecipientRole;
use App\Enums\SigningOrder;
use App\Models\Document;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Models\SigningField;
use App\Services\Anchors\SuggestionGate;
use App\Services\Documents\EnvelopeDocuments;
use App\Services\Documents\EnvelopeReadiness as DocumentReadiness;
use Illuminate\Database\Eloquent\Collection;

/**
 * Completude do envelope em preparo e recálculo do estado (docs/arquitetura.md §3.2).
 *
 * `ready` exige todos os documentos `ready` com versão exibível, ≥ 1 signatário e as regras
 * de campo por papel (docs/fase-2/multi-documento-e-papeis.md §5).
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
            // Fase 3 §3.2 (F-ANCHOR): sugestões pendentes. Flag desligada = lista vazia.
            SuggestionGate::issues($envelope),
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
        $documents = self::documents($envelope);

        if ($documents->isEmpty()) {
            return ['Envie o documento que será assinado.'];
        }

        if ($documents->count() === 1) {
            $document = $documents->first();

            return match ($document->processing_status) {
                DocumentProcessingStatus::Ready => $document->current_version_id === null
                    ? ['O documento ainda não tem uma versão preparada para assinatura.']
                    : [],
                DocumentProcessingStatus::Uploaded, DocumentProcessingStatus::Converting => ['O documento ainda está sendo processado.'],
                DocumentProcessingStatus::Failed => ['Falha ao processar o arquivo. Envie um PDF válido.'],
                DocumentProcessingStatus::Blocked => ['O arquivo está protegido por senha ou já assinado digitalmente. Envie outro PDF.'],
            };
        }

        // Vários documentos: a pendência diz QUAL arquivo precisa de atenção.
        $issues = [];

        foreach ($documents as $document) {
            $name = $document->original_filename;

            $issue = match ($document->processing_status) {
                DocumentProcessingStatus::Ready => $document->current_version_id === null
                    ? sprintf('O arquivo "%s" ainda não tem uma versão preparada para assinatura.', $name)
                    : null,
                DocumentProcessingStatus::Uploaded, DocumentProcessingStatus::Converting => sprintf('O arquivo "%s" ainda está sendo processado.', $name),
                DocumentProcessingStatus::Failed => sprintf('Falha ao processar o arquivo "%s". Envie um PDF válido.', $name),
                DocumentProcessingStatus::Blocked => sprintf('O arquivo "%s" está protegido por senha ou já assinado digitalmente. Envie outro PDF.', $name),
            };

            if ($issue !== null) {
                $issues[] = $issue;
            }
        }

        return $issues;
    }

    /**
     * @return list<string>
     */
    public static function recipientIssues(Envelope $envelope): array
    {
        $recipients = self::recipients($envelope);

        // Visualizador e aprovador não bastam: o envelope existe para colher a assinatura
        // de alguém.
        if (! $recipients->contains(fn (Recipient $recipient): bool => $recipient->role === RecipientRole::Signer)) {
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

        $documents = self::documents($envelope);

        /** @var array<int, int> $pageCounts versão exibível corrente => páginas do documento */
        $pageCounts = [];

        foreach ($documents as $document) {
            if ($document->current_version_id !== null) {
                $pageCounts[(int) $document->current_version_id] = (int) $document->page_count;
            }
        }

        if ($pageCounts === []) {
            return [];
        }

        $fields = self::fields($envelope)
            ->filter(fn (SigningField $field): bool => array_key_exists((int) $field->document_version_id, $pageCounts));

        $issues = [];

        // Signatários e testemunhas: pelo menos um campo de assinatura obrigatório.
        $needsSignature = $recipients
            ->filter(fn (Recipient $recipient): bool => $recipient->role->requiresSignatureField())
            ->values();

        $withSignature = $fields
            ->filter(fn (SigningField $field): bool => $field->type === FieldType::Signature && $field->required)
            ->pluck('recipient_id')
            ->unique();

        $missing = $needsSignature
            ->reject(fn (Recipient $recipient): bool => $withSignature->contains($recipient->getKey()))
            ->values();

        if ($missing->isNotEmpty()) {
            $issues[] = $missing->count() === $needsSignature->count()
                ? 'Todo signatário precisa de pelo menos um campo de assinatura.'
                : 'Sem campo de assinatura: '.$missing->pluck('name')->join(', ', ' e ').'.';
        }

        // Aprovador: aprova o conteúdo, não assina — nada de assinatura nem de rubrica.
        $approversWithVisual = $recipients
            ->filter(fn (Recipient $recipient): bool => $recipient->role === RecipientRole::Approver)
            ->filter(fn (Recipient $recipient): bool => $fields->contains(
                fn (SigningField $field): bool => $field->recipient_id === $recipient->getKey() && $field->type->isImageBased(),
            ))
            ->values();

        if ($approversWithVisual->isNotEmpty()) {
            $issues[] = 'Aprovador não recebe campo de assinatura ou rubrica: '.$approversWithVisual->pluck('name')->join(', ', ' e ').'.';
        }

        // Visualizador: só recebe cópia — campo nenhum.
        $viewersWithFields = $recipients
            ->filter(fn (Recipient $recipient): bool => $recipient->role === RecipientRole::Viewer)
            ->filter(fn (Recipient $recipient): bool => $fields->contains(
                fn (SigningField $field): bool => $field->recipient_id === $recipient->getKey(),
            ))
            ->values();

        if ($viewersWithFields->isNotEmpty()) {
            $issues[] = 'Visualizador não recebe campos: '.$viewersWithFields->pluck('name')->join(', ', ' e ').'.';
        }

        $outOfRange = $fields->contains(function (SigningField $field) use ($pageCounts): bool {
            $pageCount = $pageCounts[(int) $field->document_version_id] ?? 0;

            return $pageCount > 0 && ($field->page < 1 || $field->page > $pageCount);
        });

        if ($outOfRange) {
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

    /**
     * Documentos na ordem de apresentação. Com um único documento, o `document` já
     * carregado pelo chamador é reaproveitado (é o mesmo registro).
     *
     * @return \Illuminate\Support\Collection<int, Document>
     */
    private static function documents(Envelope $envelope): \Illuminate\Support\Collection
    {
        if ($envelope->relationLoaded('orderedDocuments')) {
            return $envelope->orderedDocuments->values();
        }

        return EnvelopeDocuments::ordered($envelope);
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
