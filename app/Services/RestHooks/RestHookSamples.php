<?php

namespace App\Services\RestHooks;

use App\Enums\EnvelopeStatus;
use App\Enums\RecipientRole;
use App\Enums\RecipientStatus;
use App\Enums\SignatureStatus;
use App\Enums\SigningOrder;
use App\Services\Webhooks\WebhookEventType;
use App\Services\Webhooks\WebhookPayloadFactory;

/**
 * Payload de EXEMPLO por evento — para o editor do n8n, do Zapier ou do Make mapear campos
 * antes do primeiro evento real (REST Hooks "perform list"/amostra).
 *
 * Nada aqui vem do banco: ULIDs fixos e fictícios (todos começam com `01SAMP`), datas fixas,
 * nenhum nome, e-mail, telefone, CPF, título ou resumo real. A FORMA é exatamente a de
 * App\Services\Webhooks\WebhookPayloadFactory (há teste que compara as chaves com um evento
 * real), inclusive os rótulos honestos:
 *
 *  - `recipient.signed` é "aceite eletrônico", não assinatura com certificado;
 *  - `recipient.viewed` é "abertura detectada", não prova de leitura;
 *  - `envelope.completed` traz `data.signature` com o rótulo da verificação pública.
 */
final class RestHookSamples
{
    public const ORGANIZATION_ID = '01SAMP0RGAN1ZAT10N00000000';

    public const EVENT_ID = '01SAMPEVENT000000000000000';

    public const ENVELOPE_ID = '01SAMPENVE10PE000000000000';

    public const RECIPIENT_ID = '01SAMPRECP1ENT000000000000';

    public const DOCUMENT_ID = '01SAMPD0CVMENT000000000000';

    /**
     * @return array<string, mixed>
     */
    public function forEvent(WebhookEventType $type): array
    {
        $data = [];

        if ($type !== WebhookEventType::Ping) {
            $data['envelope'] = $this->envelope($type);
        }

        if ($type->isRecipientEvent()) {
            $data['recipient'] = $this->recipient($type);
        }

        if ($type === WebhookEventType::EnvelopeCompleted) {
            $data['signature'] = [
                'status' => SignatureStatus::None->value,
                'label' => SignatureStatus::None->label(),
                'profile' => null,
                'sent_sha256' => str_repeat('0', 64),
                'final_sha256' => str_repeat('0', 64),
            ];
        }

        if ($type === WebhookEventType::DocumentProcessingFailed) {
            $data['document'] = ['id' => self::DOCUMENT_ID, 'failure_code' => 'conversion_failed'];
        }

        if ($type === WebhookEventType::Ping) {
            $data = [
                'endpoint' => ['id' => '01SAMPEND00000000000000000'],
                'message' => 'Evento de teste da AssinaVelox. Nenhum documento envolvido.',
            ];
        }

        return [
            'id' => self::EVENT_ID,
            'type' => $type->value,
            'version' => WebhookPayloadFactory::VERSION,
            'occurred_at' => '2026-01-15T13:00:00Z',
            'organization' => ['id' => self::ORGANIZATION_ID],
            'data' => $data,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function envelope(WebhookEventType $type): array
    {
        $status = match ($type) {
            WebhookEventType::EnvelopeCompleted => EnvelopeStatus::Completed,
            WebhookEventType::EnvelopeRefused => EnvelopeStatus::Refused,
            WebhookEventType::EnvelopeExpired => EnvelopeStatus::Expired,
            WebhookEventType::EnvelopeCanceled => EnvelopeStatus::Canceled,
            WebhookEventType::DocumentProcessingFailed => EnvelopeStatus::Draft,
            default => EnvelopeStatus::InProgress,
        };

        $sent = $type === WebhookEventType::DocumentProcessingFailed ? null : '2026-01-15T12:00:00Z';

        return [
            'id' => self::ENVELOPE_ID,
            'code' => 'AV-000000',
            'status' => $status->value,
            'status_label' => $status->label(),
            'signing_order' => SigningOrder::Sequential->value,
            'sent_at' => $sent,
            'expires_at' => $sent === null ? null : '2026-01-25T02:59:59Z',
            'completed_at' => $type === WebhookEventType::EnvelopeCompleted ? '2026-01-15T13:00:00Z' : null,
            'refused_at' => $type === WebhookEventType::EnvelopeRefused ? '2026-01-15T13:00:00Z' : null,
            'expired_at' => $type === WebhookEventType::EnvelopeExpired ? '2026-01-25T03:00:00Z' : null,
            'canceled_at' => $type === WebhookEventType::EnvelopeCanceled ? '2026-01-15T13:00:00Z' : null,
            'participants' => [
                'total' => 2,
                'concluded' => match ($type) {
                    WebhookEventType::EnvelopeCompleted => 2,
                    WebhookEventType::RecipientSigned, WebhookEventType::RecipientApproved => 1,
                    default => 0,
                },
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function recipient(WebhookEventType $type): array
    {
        $role = $type === WebhookEventType::RecipientApproved ? RecipientRole::Approver : RecipientRole::Signer;
        $status = match ($type) {
            WebhookEventType::RecipientViewed => RecipientStatus::Viewed,
            WebhookEventType::RecipientRefused => RecipientStatus::Refused,
            default => RecipientStatus::Signed,
        };

        $base = [
            'id' => self::RECIPIENT_ID,
            'role' => $role->value,
            'role_label' => $role->label(),
            'status' => $status->value,
            'order' => 1,
        ];

        // Mesmos textos de WebhookPayloadFactory::recipient().
        return $base + match ($type) {
            WebhookEventType::RecipientViewed => [
                'meaning' => 'opened_detected_not_read',
                'meaning_label' => 'Abertura detectada do convite — não é prova de leitura',
            ],
            WebhookEventType::RecipientSigned => [
                'action' => 'electronic_acceptance',
                'action_label' => 'Aceite eletrônico registrado (não é assinatura com certificado)',
            ],
            WebhookEventType::RecipientApproved => [
                'action' => 'approval',
                'action_label' => 'Aprovação registrada',
            ],
            WebhookEventType::RecipientRefused => [
                'action' => 'refusal',
                'action_label' => 'Recusa registrada (o motivo não é enviado)',
            ],
            default => [],
        };
    }
}
