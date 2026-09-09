<?php

namespace App\Jobs\Envelopes;

use App\Enums\EnvelopeStatus;
use App\Models\Envelope;
use App\Models\Organization;
use App\Services\Envelopes\Sending\Exceptions\SendingException;
use App\Services\Envelopes\Sending\ResendInvitations;
use App\Services\Organizations\EnvelopeVisibility;
use App\Support\CurrentOrganization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * "Lembrar todos os pendentes" da tela Assinaturas (ROUTES §1.2 `recipients.resend_pending`).
 *
 * Roda em fila porque percorre todos os envelopes `in_progress` visíveis ao usuário. O
 * throttle por destinatário (10 min) continua valendo dentro do job — um destinatário que
 * recebeu convite há pouco é simplesmente pulado.
 *
 * `ShouldBeUnique` por organização evita que dois cliques disparem duas varreduras.
 */
class ResendPendingInvitations implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public readonly int $organizationId,
        public readonly ?int $userId = null,
    ) {
        $this->onQueue((string) config('assinavelox.queues.notifications', 'notifications'));
    }

    public function uniqueId(): string
    {
        return 'resend-pending:'.$this->organizationId.':'.($this->userId ?? 'all');
    }

    public function uniqueFor(): int
    {
        return 600;
    }

    public function handle(ResendInvitations $resends): void
    {
        $organization = Organization::query()->find($this->organizationId);

        if ($organization === null) {
            return;
        }

        // O job roda fora da requisição: define a organização corrente para que o escopo
        // global continue valendo (arquitetura §7).
        CurrentOrganization::instance()->set($organization);

        $query = Envelope::query()->where('status', EnvelopeStatus::InProgress->value);

        if ($this->userId !== null) {
            $membership = $organization->memberships()->where('user_id', $this->userId)->first();

            if ($membership === null) {
                return;
            }

            $query = EnvelopeVisibility::envelopes($membership)->where('status', EnvelopeStatus::InProgress->value);
        }

        $sent = 0;
        $skipped = 0;

        $query->orderBy('id')->chunkById(100, function ($envelopes) use ($resends, &$sent, &$skipped): void {
            foreach ($envelopes as $envelope) {
                try {
                    $result = $resends->all($envelope);
                    $sent += $result['sent'];
                    $skipped += $result['skipped'];
                } catch (SendingException) {
                    // Envelope sem pendentes elegíveis: nada a fazer.
                }
            }
        });

        Log::info('Reenvio em lote de convites concluído.', [
            'organization' => $organization->ulid,
            'sent' => $sent,
            'skipped' => $skipped,
        ]);
    }
}
