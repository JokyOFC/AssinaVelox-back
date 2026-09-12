<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\ApiAbility;
use App\Enums\RecipientRole;
use App\Enums\RecipientStatus;
use App\Models\Recipient;
use App\Rules\PhoneE164;
use App\Services\Api\ApiContext;
use App\Services\Api\ApiFormat;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Participante na API v1 — a situação, nunca a prova.
 *
 * Nunca sai: CPF, celular completo (só mascarado), PIN ou estado de PIN, código do desafio,
 * token/link de acesso, IP, user-agent, imagem de assinatura, captura de identidade ou valor
 * de campo preenchido.
 *
 * @mixin Recipient
 */
class RecipientResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Recipient $recipient */
        $recipient = $this->resource;
        $label = $recipient->getAttribute('role_label');
        // Dados pessoais do participante só com `recipients:read` (mínimo privilégio): sem ela, o
        // detalhe e as respostas das rotas de escrita trazem a MESMA forma, com esses campos nulos.
        $personal = ApiContext::allows(ApiAbility::RecipientsRead, $request);

        return [
            'id' => $recipient->ulid,
            'object' => 'recipient',
            'name' => $personal ? $recipient->name : null,
            'email' => $personal ? $recipient->email : null,
            'phone_masked' => $personal && $recipient->phone !== null ? PhoneE164::mask($recipient->phone) : null,
            'role' => $recipient->role->value,
            'role_label' => $recipient->role->label(),
            'label' => is_string($label) && trim($label) !== '' ? trim($label) : null,
            'order' => (int) $recipient->order_index,
            'status' => $recipient->status->value,
            'status_label' => $recipient->status === RecipientStatus::Signed && $recipient->role === RecipientRole::Approver
                ? 'Aprovado'
                : $recipient->status->label(),
            'auth_method' => $personal ? $recipient->auth_method->value : null,
            'notified_at' => $recipient->status !== RecipientStatus::Pending ? ApiFormat::date($recipient->last_notified_at) : null,
            'notifications_count' => (int) $recipient->notification_count,
            'signed_at' => ApiFormat::date($recipient->signed_at),
            'refused_at' => ApiFormat::date($recipient->refused_at),
            'refusal_reason' => $personal ? $recipient->refusal_reason : null,
        ];
    }
}
