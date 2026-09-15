<?php

namespace App\Services\Anchors;

use App\Enums\FieldType;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Envelope;
use App\Models\FieldSuggestion;
use App\Models\Recipient;
use App\Models\User;
use App\Services\Envelopes\EnvelopeReadiness;
use App\Services\Envelopes\FieldGeometry;
use App\Services\Envelopes\PageBox;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Revisão de sugestões pelo remetente (Fase 3 §3.2): confirmar ou descartar.
 *
 * Confirmar NÃO grava `signing_fields`: devolve o campo para o editor, que o acrescenta à
 * lista local e o salva pelo caminho de sempre (`PUT envelopes.fields.sync` → FieldSync, com
 * toda a validação de geometria, papel e flag). Assim existe uma única porta de entrada de
 * campos, e o que o remetente ajustar depois (arrastar, redimensionar, trocar participante)
 * vale como qualquer campo. A sugestão registra quem revisou e quando.
 *
 * "Confirmar todas" só vale para sugestões do TEXTO do PDF com participante definido; as
 * vindas do OCR exigem revisão explícita, uma a uma.
 */
class SuggestionReview
{
    /**
     * @return array{field: array<string, mixed>}
     *
     * @throws ValidationException
     */
    public function accept(Envelope $envelope, FieldSuggestion $suggestion, User $user, ?string $recipientUlid = null): array
    {
        $result = DB::transaction(function () use ($envelope, $suggestion, $user, $recipientUlid): array {
            [$locked, $document, $version] = $this->lock($envelope, $suggestion);

            $recipient = $this->recipient($envelope, $recipientUlid, $locked);
            $box = $this->pageBox($version, $locked->page);

            if (FieldGeometry::validate($locked->type, (float) $locked->x, (float) $locked->y, (float) $locked->width, (float) $locked->height, $box) !== []) {
                throw ValidationException::withMessages([
                    'suggestion' => 'A posição sugerida não cabe na página. Descarte a sugestão e posicione o campo manualmente.',
                ]);
            }

            $locked->forceFill([
                'status' => SuggestionStatus::Accepted,
                'recipient_id' => $recipient->getKey(),
                'resolved_by_user_id' => $user->getKey(),
                'resolved_at' => now(),
            ])->save();

            return ['field' => self::fieldPayload($locked, $document, $recipient)];
        });

        EnvelopeReadiness::refresh($envelope);

        return $result;
    }

    /**
     * @throws ValidationException
     */
    public function discard(Envelope $envelope, FieldSuggestion $suggestion, User $user): void
    {
        DB::transaction(function () use ($envelope, $suggestion, $user): void {
            [$locked] = $this->lock($envelope, $suggestion, allowReplaced: true);

            $locked->forceFill([
                'status' => SuggestionStatus::Discarded,
                'resolved_by_user_id' => $user->getKey(),
                'resolved_at' => now(),
            ])->save();
        });

        EnvelopeReadiness::refresh($envelope);
    }

    /**
     * Confirma, de uma vez, as sugestões do TEXTO do PDF que já têm participante. As do OCR e
     * as sem participante ficam para a revisão individual.
     *
     * @return array{fields: list<array<string, mixed>>, skipped: int}
     */
    public function acceptAllFromText(Envelope $envelope, User $user): array
    {
        $candidates = FieldSuggestion::withoutOrganizationScope()
            ->where('envelope_id', $envelope->getKey())
            ->where('status', SuggestionStatus::Pending->value)
            ->where('via', FieldSuggestion::VIA_TEXT)
            ->orderBy('page')
            ->orderBy('y')
            ->get();

        $fields = [];
        $skipped = 0;

        foreach ($candidates as $suggestion) {
            if ($suggestion->recipient_id === null) {
                $skipped++;

                continue;
            }

            try {
                $fields[] = $this->accept($envelope, $suggestion, $user)['field'];
            } catch (ValidationException) {
                $skipped++;
            }
        }

        return ['fields' => $fields, 'skipped' => $skipped];
    }

    /**
     * Campo no formato do editor (`WizardField` sem os ids do cliente).
     *
     * @return array<string, mixed>
     */
    public static function fieldPayload(FieldSuggestion $suggestion, Document $document, Recipient $recipient): array
    {
        return [
            'suggestion_id' => $suggestion->ulid,
            'document_id' => $document->ulid,
            'recipient_id' => $recipient->ulid,
            'type' => $suggestion->type->value,
            'page' => $suggestion->page,
            'x' => (float) $suggestion->x,
            'y' => (float) $suggestion->y,
            'w' => (float) $suggestion->width,
            'h' => (float) $suggestion->height,
            'required' => in_array($suggestion->type, [FieldType::Signature, FieldType::Initials], true) ? true : $suggestion->required,
            'label' => $suggestion->label,
            'via' => $suggestion->via,
        ];
    }

    // -- Internos -----------------------------------------------------------------------

    /**
     * @return array{0: FieldSuggestion, 1: Document, 2: DocumentVersion}
     *
     * @throws ValidationException
     */
    private function lock(Envelope $envelope, FieldSuggestion $suggestion, bool $allowReplaced = false): array
    {
        $lockedEnvelope = Envelope::withoutOrganizationScope()->whereKey($envelope->getKey())->lockForUpdate()->first();

        if (! $lockedEnvelope instanceof Envelope || ! $lockedEnvelope->status->isDraftLike()) {
            throw ValidationException::withMessages(['suggestion' => 'Ação indisponível no status atual.']);
        }

        $locked = FieldSuggestion::withoutOrganizationScope()
            ->whereKey($suggestion->getKey())
            ->where('envelope_id', $envelope->getKey())
            ->lockForUpdate()
            ->first();

        if (! $locked instanceof FieldSuggestion || ! $locked->isPending()) {
            throw ValidationException::withMessages(['suggestion' => 'Esta sugestão já foi revisada.']);
        }

        $document = Document::withoutOrganizationScope()->find($locked->document_id);
        $version = $document instanceof Document && $document->current_version_id !== null
            ? DocumentVersion::withoutOrganizationScope()->find($document->current_version_id)
            : null;

        if (! $document instanceof Document || ! $version instanceof DocumentVersion
            || (int) $version->getKey() !== (int) $locked->document_version_id) {
            if ($allowReplaced && $document instanceof Document && $version instanceof DocumentVersion) {
                return [$locked, $document, $version];
            }

            $locked->forceFill(['status' => SuggestionStatus::Superseded])->save();

            throw ValidationException::withMessages([
                'suggestion' => 'O arquivo mudou depois da detecção. Rode a detecção de novo.',
            ]);
        }

        return [$locked, $document, $version];
    }

    /**
     * @throws ValidationException
     */
    private function recipient(Envelope $envelope, ?string $recipientUlid, FieldSuggestion $suggestion): Recipient
    {
        $query = Recipient::withoutOrganizationScope()->where('envelope_id', $envelope->getKey());

        $recipient = $recipientUlid !== null && $recipientUlid !== ''
            ? $query->where('ulid', $recipientUlid)->first()
            : ($suggestion->recipient_id !== null ? $query->whereKey($suggestion->recipient_id)->first() : null);

        if (! $recipient instanceof Recipient) {
            throw ValidationException::withMessages([
                'recipient_id' => $recipientUlid !== null && $recipientUlid !== ''
                    ? 'Este participante não pertence ao documento.'
                    : 'Escolha o participante deste campo antes de confirmar.',
            ]);
        }

        if (! $recipient->role->allowsFields()) {
            throw ValidationException::withMessages([
                'recipient_id' => 'Visualizadores só recebem cópia do documento e não podem ter campos.',
            ]);
        }

        if ($suggestion->type->isImageBased() && ! $recipient->role->allowsVisualSignature()) {
            throw ValidationException::withMessages([
                'recipient_id' => 'Aprovadores aprovam o conteúdo sem assinar: não podem ter campo de assinatura ou rubrica.',
            ]);
        }

        return $recipient;
    }

    private function pageBox(DocumentVersion $version, int $page): PageBox
    {
        $meta = $version->pageMeta($page);
        $fallback = PageBox::fromPageMeta(['width_pt' => 595.276, 'height_pt' => 841.89, 'rotation' => 0]);

        if ($meta === null) {
            return $fallback;
        }

        $box = PageBox::fromPageMeta($meta);

        return $box->isDegenerate() ? $fallback : $box;
    }
}
