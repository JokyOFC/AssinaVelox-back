<?php

namespace App\Services\Anchors;

use App\Enums\FieldType;
use App\Models\AnchorScan;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Envelope;
use App\Models\FieldAnchorRule;
use App\Models\FieldSuggestion;
use App\Models\Recipient;
use App\Models\SigningField;
use App\Services\Envelopes\FieldSync;
use App\Services\Envelopes\PageBox;
use Illuminate\Support\Collection;
use Normalizer;

/**
 * Ocorrências do pdftool/OCR → `field_suggestions` PENDENTES (Fase 3 §3.2).
 *
 * - Marcador `{{assinatura|rubrica|data:papel}}`: o campo cobre o marcador; o participante é
 *   procurado pelo papel (rótulo do participante, nome, ou "1", "2"… = posição na lista). Sem
 *   correspondência — ou com papel incompatível (aprovador não recebe assinatura/rubrica) — a
 *   sugestão fica sem participante e o remetente escolhe antes de confirmar.
 * - `{{texto:nome}}`: `nome` é o rótulo do campo, não um papel; o remetente escolhe quem preenche.
 * - Texto literal (regra do modelo ou busca manual): tipo, participante, posição, deslocamento
 *   e tamanho vêm da regra/do pedido — nunca do documento.
 *
 * Nada do texto do documento é gravado: só a geometria, o tipo e o identificador do marcador
 * (`[a-z0-9_-]`). Sugestões que repetem um campo já posicionado (mesmo tipo, mesma página,
 * sobreposição > 50 %) ou outra sugestão (> 80 %) são descartadas.
 */
class SuggestionBuilder
{
    /**
     * @param  list<AnchorMatch>  $matches
     */
    public function create(
        AnchorScan $scan,
        Envelope $envelope,
        Document $document,
        DocumentVersion $version,
        array $matches,
        AnchorQuery $query,
        string $via,
    ): int {
        $recipients = Recipient::withoutOrganizationScope()
            ->where('envelope_id', $envelope->getKey())
            ->orderBy('order_index')
            ->orderBy('id')
            ->get();

        $eligible = $recipients->filter(fn (Recipient $recipient): bool => $recipient->role->allowsFields())->values();

        $existingFields = SigningField::withoutOrganizationScope()
            ->where('envelope_id', $envelope->getKey())
            ->where('document_version_id', $version->getKey())
            ->get(['type', 'page', 'x', 'y', 'width', 'height']);

        /** @var list<int> $existingRules regras ainda existentes (a FK é anulada se a regra sair) */
        $existingRules = array_values(array_map('intval', FieldAnchorRule::withoutOrganizationScope()
            ->whereIn('id', array_values(array_filter(array_map(
                static fn (array $literal): ?int => $literal['rule'],
                $query->literals,
            ))))
            ->pluck('id')
            ->all()));

        /** @var list<array{type: string, page: int, geometry: array{x: float, y: float, width: float, height: float}}> $placed */
        $placed = [];
        $firstSeen = [];
        $count = 0;
        $pageCount = max(1, (int) ($version->page_count ?? $document->page_count ?? 1));

        foreach ($matches as $match) {
            if ($count >= FieldSync::MAX_FIELDS || $match->page > $pageCount) {
                continue;
            }

            $row = $match->isMarker()
                ? $this->fromMarker($match, $eligible)
                : $this->fromLiteral($match, $query, $eligible, $existingRules, $firstSeen);

            if ($row === null) {
                continue;
            }

            $geometry = SuggestionGeometry::place(
                $this->pageBox($version, $match->page),
                $row['anchor'],
                $row['type'],
                $row['placement'],
                $row['offset_x'],
                $row['offset_y'],
                $row['width'],
                $row['height'],
                coverAnchorWidth: $match->isMarker(),
            );

            if ($geometry === null || $this->duplicates($geometry, $row['type'], $match->page, $existingFields, $placed)) {
                continue;
            }

            $suggestion = new FieldSuggestion;
            $suggestion->forceFill([
                'organization_id' => $envelope->organization_id,
                'envelope_id' => $envelope->getKey(),
                'document_id' => $document->getKey(),
                'document_version_id' => $version->getKey(),
                'anchor_scan_id' => $scan->getKey(),
                'field_anchor_rule_id' => $row['rule'],
                'recipient_id' => $row['recipient']?->getKey(),
                'source' => $row['source'],
                'via' => $via,
                'type' => $row['type'],
                'page' => $match->page,
                'x' => $geometry['x'],
                'y' => $geometry['y'],
                'width' => $geometry['width'],
                'height' => $geometry['height'],
                // Assinatura e rubrica são sempre obrigatórias (mesma regra do FieldSync).
                'required' => in_array($row['type'], [FieldType::Signature, FieldType::Initials], true) ? true : $row['required'],
                'label' => $row['label'],
                'role_hint' => $match->isMarker() ? $match->key : null,
                'confidence' => $match->confidence,
                'status' => SuggestionStatus::Pending,
            ])->save();

            $placed[] = ['type' => $row['type']->value, 'page' => $match->page, 'geometry' => $geometry];
            $count++;
        }

        return $count;
    }

    /**
     * Papel do marcador → participante. Mesmo "slug" do pdftool (`marker_key_slug`).
     *
     * @param  Collection<int, Recipient>  $eligible
     */
    public static function resolveRecipient(?string $key, Collection $eligible, FieldType $type): ?Recipient
    {
        if ($key === null || $key === '') {
            return null;
        }

        $compatible = $eligible
            ->filter(fn (Recipient $recipient): bool => ! $type->isImageBased() || $recipient->role->allowsVisualSignature())
            ->values();

        foreach ($compatible as $recipient) {
            if ($recipient->role_label !== null && self::slug($recipient->role_label) === $key) {
                return $recipient;
            }
        }

        foreach ($compatible as $recipient) {
            $first = explode(' ', trim((string) $recipient->name))[0];

            if (self::slug((string) $recipient->name) === $key || self::slug($first) === $key) {
                return $recipient;
            }
        }

        if (preg_match('/^(?:signatario|participante|parte|p)?[_-]?(\d{1,2})$/', $key, $matches) === 1) {
            $position = (int) $matches[1];

            return $position >= 1 ? $compatible->get($position - 1) : null;
        }

        return null;
    }

    /**
     * Espelho de `marker_key_slug` (tools/pdftool/pdftool/anchors.py): sem acentos, minúsculas,
     * espaços e pontos viram `_`, só `[a-z0-9_-]`, até 40 caracteres.
     */
    public static function slug(string $value): string
    {
        $decomposed = Normalizer::normalize($value, Normalizer::FORM_KD);
        $value = is_string($decomposed) ? $decomposed : $value;
        $value = (string) preg_replace('/\p{Mn}+/u', '', $value);
        $value = mb_strtolower(trim($value));
        $value = (string) preg_replace('/[ .]+/', '_', $value);
        $value = (string) preg_replace('/[^a-z0-9_-]/', '', $value);

        return substr($value, 0, 40);
    }

    // -- Linhas -------------------------------------------------------------------------

    /**
     * @param  Collection<int, Recipient>  $eligible
     * @return array{type: FieldType, anchor: array{x: float, y: float, width: float, height: float}, placement: AnchorPlacement, offset_x: float, offset_y: float, width: float|null, height: float|null, recipient: Recipient|null, required: bool, label: string|null, source: string, rule: int|null}|null
     */
    private function fromMarker(AnchorMatch $match, Collection $eligible): ?array
    {
        $type = FieldType::tryFrom((string) $match->fieldType);

        if ($type === null) {
            return null;
        }

        return [
            'type' => $type,
            'anchor' => $match->lineBox,
            'placement' => AnchorPlacement::Over,
            'offset_x' => 0.0,
            'offset_y' => 0.0,
            'width' => null,
            'height' => null,
            'recipient' => $type === FieldType::Text ? null : self::resolveRecipient($match->key, $eligible, $type),
            'required' => true,
            'label' => $type === FieldType::Text && $match->key !== null
                ? mb_substr(ucfirst(str_replace(['_', '-'], ' ', $match->key)), 0, 120)
                : null,
            'source' => FieldSuggestion::SOURCE_MARKER,
            'rule' => null,
        ];
    }

    /**
     * @param  Collection<int, Recipient>  $eligible
     * @param  list<int>  $existingRules
     * @param  array<string, bool>  $firstSeen
     * @return array{type: FieldType, anchor: array{x: float, y: float, width: float, height: float}, placement: AnchorPlacement, offset_x: float, offset_y: float, width: float|null, height: float|null, recipient: Recipient|null, required: bool, label: string|null, source: string, rule: int|null}|null
     */
    private function fromLiteral(AnchorMatch $match, AnchorQuery $query, Collection $eligible, array $existingRules, array &$firstSeen): ?array
    {
        $literal = $match->literalId === null ? null : $query->literalById($match->literalId);

        if ($literal === null) {
            return null;
        }

        if ($literal['occurrence'] === FieldAnchorRule::OCCURRENCE_FIRST && isset($firstSeen[$literal['id']])) {
            return null;
        }

        $firstSeen[$literal['id']] = true;

        $type = FieldType::tryFrom($literal['field_type']);

        if ($type === null || ! in_array($type->value, FieldAnchorRule::FIELD_TYPES, true)) {
            return null;
        }

        $recipient = $literal['recipient'] === null ? null : $eligible->firstWhere('ulid', $literal['recipient']);

        if ($recipient instanceof Recipient && $type->isImageBased() && ! $recipient->role->allowsVisualSignature()) {
            $recipient = null;
        }

        $rule = $literal['rule'] !== null && in_array($literal['rule'], $existingRules, true)
            ? $literal['rule']
            : null;

        return [
            'type' => $type,
            'anchor' => $match->box,
            'placement' => AnchorPlacement::tryFrom($literal['placement']) ?? AnchorPlacement::Below,
            'offset_x' => $literal['offset_x_pt'],
            'offset_y' => $literal['offset_y_pt'],
            'width' => $literal['width_pt'],
            'height' => $literal['height_pt'],
            'recipient' => $recipient instanceof Recipient ? $recipient : null,
            'required' => $literal['required'],
            'label' => $literal['label'] !== null ? mb_substr($literal['label'], 0, 120) : null,
            'source' => $literal['rule'] !== null ? FieldSuggestion::SOURCE_RULE : FieldSuggestion::SOURCE_LITERAL,
            'rule' => $rule,
        ];
    }

    /**
     * @param  array{x: float, y: float, width: float, height: float}  $geometry
     * @param  \Illuminate\Database\Eloquent\Collection<int, SigningField>  $fields
     * @param  list<array{type: string, page: int, geometry: array{x: float, y: float, width: float, height: float}}>  $placed
     */
    private function duplicates(array $geometry, FieldType $type, int $page, $fields, array $placed): bool
    {
        foreach ($fields as $field) {
            if ($field->type === $type && $field->page === $page && SuggestionGeometry::overlap($geometry, [
                'x' => (float) $field->x,
                'y' => (float) $field->y,
                'width' => (float) $field->width,
                'height' => (float) $field->height,
            ]) > 0.5) {
                return true;
            }
        }

        foreach ($placed as $other) {
            if ($other['type'] === $type->value && $other['page'] === $page && SuggestionGeometry::overlap($geometry, $other['geometry']) > 0.8) {
                return true;
            }
        }

        return false;
    }

    /**
     * Caixa da página vinda de `pages_meta` (mesmo critério do FieldSync: sem meta, A4 retrato).
     */
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
