<?php

namespace App\Services\HubSpot;

use App\Models\HubSpotActionExecution;

/**
 * Linha da tabela "Execuções recentes" da tela HubSpot. Sem token, sem corpo recebido.
 */
final class HubSpotExecutionPresenter
{
    /** @var array<string, string> */
    private const LABELS = [
        HubSpotActionExecution::STATUS_PROCESSING => 'Processando',
        HubSpotActionExecution::STATUS_SENT => 'Enviado para assinatura',
        HubSpotActionExecution::STATUS_AWAITING_PREPARATION => 'Aguardando preparo do documento',
        HubSpotActionExecution::STATUS_NEEDS_REVIEW => 'Precisa de revisão no painel',
        HubSpotActionExecution::STATUS_FAILED => 'Recusado',
    ];

    public static function label(string $status): string
    {
        return self::LABELS[$status] ?? $status;
    }

    /**
     * @return array<string, mixed>
     */
    public static function row(HubSpotActionExecution $execution): array
    {
        $envelope = $execution->envelope;

        return [
            'id' => $execution->ulid,
            'created_at' => $execution->created_at?->toIso8601String(),
            'status' => $execution->status,
            'status_label' => self::label($execution->status),
            'error_code' => $execution->error_code,
            'object_type' => $execution->object_type,
            'object_id' => $execution->object_id,
            'sync_status' => $execution->sync_status,
            'synced_value' => $execution->synced_value,
            'envelope' => $envelope === null ? null : [
                'id' => $envelope->ulid,
                'title' => $envelope->title,
                'url' => route('envelopes.show', $envelope->ulid),
            ],
        ];
    }
}
