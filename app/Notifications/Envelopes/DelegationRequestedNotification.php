<?php

namespace App\Notifications\Envelopes;

use App\Models\Envelope;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Fase 3 §3.3 (F-FLOW): um participante pediu para delegar e a política do envelope exige a
 * confirmação de quem enviou. Só o sino (canal `database`): o pedido aparece no detalhe do
 * documento, com Confirmar/Recusar. Sem e-mail nesta onda — o catálogo de preferências de
 * notificação não tem o evento `delegation_requested` (pendência em docs/fase-3/etapas-e-delegacao.md).
 * Nunca carrega o e-mail do delegado nem o motivo.
 */
class DelegationRequestedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Envelope $envelope,
        public readonly string $fromName,
    ) {
        $this->onQueue((string) config('assinavelox.queues.notifications', 'notifications'));
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'organization_id' => $this->envelope->organization_id,
            'title' => 'Pedido de delegação aguardando você',
            'body' => sprintf('%s pediu para passar a participação em "%s" a outra pessoa.', $this->fromName, $this->envelope->title),
            'url' => route('envelopes.show', $this->envelope),
            'event' => 'delegation_requested',
        ];
    }
}
