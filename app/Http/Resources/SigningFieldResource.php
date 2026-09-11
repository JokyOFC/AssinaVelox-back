<?php

namespace App\Http\Resources;

use App\Enums\FieldType;
use App\Enums\RecipientStatus;
use App\Models\SigningField;
use App\Services\Identity\CpfNumber;
use App\Services\Impersonation\ImpersonationManager;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Campo de assinatura para o wizard (ROUTES §2.6 `WizardField`) e para o detalhe
 * (ROUTES §2.7 `fields[]`).
 *
 * A geometria sai exatamente como está no banco: frações [0,1] do CropBox EXIBIDO, origem
 * no canto superior esquerdo, y para baixo — a mesma convenção do canvas do editor e do
 * `compose` do pdftool (docs/campos-e-geometria.md). `page_width_pt`/`page_height_pt` são
 * as dimensões exibidas da página em pontos, para o front conferir a escala.
 *
 * @mixin SigningField
 */
class SigningFieldResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $value = $this->relationLoaded('value') ? $this->value : null;
        $recipient = $this->relationLoaded('recipient') ? $this->recipient : null;

        return [
            'id' => $this->ulid,
            'client_id' => $this->ulid,
            'recipient_id' => $recipient?->ulid,
            'recipient_client_id' => $recipient?->ulid,
            'type' => $this->type->value,
            'page' => (int) $this->page,
            'x' => (float) $this->x,
            'y' => (float) $this->y,
            'w' => (float) $this->width,
            'h' => (float) $this->height,
            'required' => (bool) $this->required,
            'label' => $this->label,
            'placeholder' => $this->options['placeholder'] ?? null,
            'options' => $this->options,
            'auto' => (bool) ($this->options['auto'] ?? false),
            'box_type' => $this->box_type->value,
            'page_width_pt' => $this->page_width_pt === null ? null : (float) $this->page_width_pt,
            'page_height_pt' => $this->page_height_pt === null ? null : (float) $this->page_height_pt,
            'page_rotation' => (int) $this->page_rotation,
            'value' => $this->displayValue($value?->value_text, $request),
            'signed' => $recipient?->status === RecipientStatus::Signed,
            // Fase 2 §2.3: arquivo onde o campo está (ULID de `documents`). `null` quando o
            // chamador não carregou `documentVersion.document`.
            'document_id' => $this->relationLoaded('documentVersion')
                ? $this->documentVersion?->document?->ulid
                : null,
        ];
    }

    /**
     * Valor preenchido pelo participante, como pode sair FORA do documento:
     *
     * - durante o "acessar como", nada: o suporte não vê conteúdo de documentos
     *   (ReadOnlyRoutes; Termos §4.5) — e o valor de um campo é conteúdo;
     * - CPF, só mascarado (docs/fase-2/identidade.md §2.1: "Fora do documento só sai
     *   mascarado"). O CPF completo continua no PDF e no `fields_snapshot` do aceite.
     */
    private function displayValue(?string $text, Request $request): ?string
    {
        if ($text === null) {
            return null;
        }

        if (ImpersonationManager::active($request)) {
            return null;
        }

        return $this->type === FieldType::Cpf ? CpfNumber::mask($text) : $text;
    }
}
