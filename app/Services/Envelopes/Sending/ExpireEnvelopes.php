<?php

namespace App\Services\Envelopes\Sending;

use App\Enums\AuditEventType;
use App\Enums\EnvelopeStatus;
use App\Enums\RecipientStatus;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Notifications\Envelopes\EnvelopeExpiringNotification;
use App\Notifications\Envelopes\SenderEnvelopeExpiringNotification;
use App\Services\Envelopes\EnvelopeAudit;
use App\Services\Organizations\NotificationPreferences;
use App\Services\Signing\Contracts\RevalidatesEnvelopeExpiration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Expiração do envelope (RECONCILIACAO Q22).
 *
 * Dois gatilhos, de propósito:
 *  - **agendado**: `envelopes:expire` a cada 15 minutos varre o que venceu;
 *  - **no acesso**: `enforce()` é chamado pela página pública a cada visita, para que um
 *    link não continue funcionando nos até 15 minutos entre uma varredura e outra.
 *
 * A transição é idempotente e feita sob lock: duas execuções simultâneas (scheduler +
 * acesso) produzem uma única transição e um único `envelope.expired`.
 *
 * O consumo do plano NÃO é liberado na expiração: o envio aconteceu, os convites saíram e
 * a cota foi consumida por isso. Liberar só faz sentido no cancelamento antes de qualquer
 * assinatura (ver `CancelEnvelope`).
 */
class ExpireEnvelopes implements RevalidatesEnvelopeExpiration
{
    public function __construct(
        private readonly AccessLinks $links,
        private readonly NotificationPreferences $preferences,
    ) {}

    /**
     * CONTRATO `RevalidatesEnvelopeExpiration` (publicado pelo módulo do signatário):
     * revalida o prazo a cada acesso e devolve o envelope já com o status correto para o
     * instante atual. Não lança — envelope terminal, sem prazo ou dentro do prazo volta
     * como está.
     *
     * É a MESMA rotina do agendamento (`sweep()` → `expire()`), então o resultado de um
     * acesso e o de uma varredura são indistinguíveis: transição sob lock, destinatários
     * pendentes para `expired`, links revogados e um único `envelope.expired`.
     */
    public function revalidate(Envelope $envelope): Envelope
    {
        $this->enforce($envelope);

        return $envelope;
    }

    /**
     * Versão booleana da revalidação, para quem só precisa saber se deve mostrar a tela
     * `expired`. Seguro chamar em toda requisição: só toca no banco quando o prazo venceu.
     */
    public function enforce(Envelope $envelope): bool
    {
        if ($envelope->status === EnvelopeStatus::Expired) {
            return true;
        }

        if ($envelope->status !== EnvelopeStatus::InProgress) {
            return false;
        }

        if ($envelope->expires_at === null || $envelope->expires_at->isFuture()) {
            return false;
        }

        $expired = $this->expire($envelope);

        if ($expired) {
            $envelope->refresh();
        }

        return $envelope->status === EnvelopeStatus::Expired;
    }

    /**
     * Varre os envelopes vencidos. Idempotente e reprocessável.
     *
     * @return int quantidade efetivamente expirada
     */
    public function sweep(?int $limit = null): int
    {
        $limit ??= (int) config('assinavelox.expiration.batch_size', 200);

        $envelopes = Envelope::withoutOrganizationScope()
            ->where('status', EnvelopeStatus::InProgress->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', Carbon::now())
            ->orderBy('expires_at')
            ->limit($limit)
            ->get();

        $count = 0;

        foreach ($envelopes as $envelope) {
            if ($this->expire($envelope)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Expira um envelope: transição, destinatários pendentes → `expired`, links revogados,
     * trilha e notificação. Devolve `false` se outra execução chegou primeiro.
     */
    public function expire(Envelope $envelope): bool
    {
        $correlationId = (string) Str::ulid();

        /** @var array{expired: bool, recipients: list<int>} $result */
        $result = DB::transaction(function () use ($envelope, $correlationId): array {
            /** @var Envelope|null $locked */
            $locked = Envelope::withoutOrganizationScope()
                ->whereKey($envelope->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null || $locked->status !== EnvelopeStatus::InProgress) {
                return ['expired' => false, 'recipients' => []];
            }

            if ($locked->expires_at === null || $locked->expires_at->isFuture()) {
                return ['expired' => false, 'recipients' => []];
            }

            $pending = $locked->recipients()
                ->whereIn('status', [
                    RecipientStatus::Pending->value,
                    RecipientStatus::Notified->value,
                    RecipientStatus::Viewed->value,
                ])
                ->get();

            $locked->transitionTo(EnvelopeStatus::Expired);
            $locked->save();

            foreach ($pending as $recipient) {
                $recipient->transitionTo(RecipientStatus::Expired);
                $recipient->save();
            }

            // Quem já assinou mantém o link: é por ele que a pessoa chega ao próprio
            // comprovante de aceite, e o prazo do envelope não apaga o que ela fez.
            $signed = array_values(array_map(
                static fn ($id): int => (int) $id,
                $locked->recipients()
                    ->where('status', RecipientStatus::Signed->value)
                    ->pluck('id')
                    ->all(),
            ));

            $this->links->revokeForEnvelope($locked, exceptRecipientIds: $signed);

            EnvelopeAudit::record($locked, AuditEventType::EnvelopeExpired, [
                'pending_recipients' => $pending->count(),
                'expires_at' => $locked->expires_at?->toIso8601String(),
            ], null, $correlationId);

            return ['expired' => true, 'recipients' => $pending->modelKeys()];
        }, 3);

        return $result['expired'];
    }

    /**
     * Envelopes que vencem dentro da janela de aviso e ainda têm pendências.
     *
     * @return int quantidade de envelopes avisados
     */
    public function warnExpiring(?int $hours = null, ?int $limit = null): int
    {
        $hours ??= (int) config('assinavelox.expiration.warning_hours', 48);
        $limit ??= (int) config('assinavelox.expiration.batch_size', 200);

        $now = Carbon::now();

        $envelopes = Envelope::withoutOrganizationScope()
            ->where('status', EnvelopeStatus::InProgress->value)
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [$now, $now->copy()->addHours($hours)])
            ->orderBy('expires_at')
            ->limit($limit)
            ->get();

        $warned = 0;

        foreach ($envelopes as $envelope) {
            if ($this->warn($envelope)) {
                $warned++;
            }
        }

        return $warned;
    }

    /**
     * Avisa uma única vez por envelope: a marca fica em `settings.expiring_warned_at`, então
     * reexecutar o comando (ou rodá-lo a cada 15 min) não gera enxurrada de e-mail.
     */
    public function warn(Envelope $envelope): bool
    {
        if ($envelope->setting('expiring_warned_at') !== null) {
            return false;
        }

        $pending = $envelope->recipients()
            ->whereIn('status', [
                RecipientStatus::Pending->value,
                RecipientStatus::Notified->value,
                RecipientStatus::Viewed->value,
            ])
            ->get();

        if ($pending->isEmpty()) {
            return false;
        }

        $settings = $envelope->settings ?? [];
        $settings['expiring_warned_at'] = Carbon::now()->toIso8601String();
        $envelope->forceFill(['settings' => $settings])->save();

        foreach ($pending as $recipient) {
            $this->warnRecipient($envelope, $recipient);
        }

        $this->warnSender($envelope, $pending->count());

        return true;
    }

    private function warnRecipient(Envelope $envelope, Recipient $recipient): void
    {
        // Só quem já tem link ativo é avisado: quem aguarda a vez no sequencial ainda não
        // recebeu convite e um aviso de prazo o confundiria.
        $link = $this->links->activeFor($recipient);

        if ($link === null || ! $link->isUsable()) {
            return;
        }

        // O token não é recuperável (só o digest é guardado): o aviso emite um link novo,
        // que revoga o anterior — comportamento idêntico ao de um reenvio.
        $issued = $this->links->issue($recipient, envelope: $envelope);

        Notification::route('mail', $recipient->email)->notify(
            new EnvelopeExpiringNotification($recipient, $envelope, $issued->url, (string) Str::ulid()),
        );
    }

    private function warnSender(Envelope $envelope, int $pendingCount): void
    {
        $creator = $envelope->creator;

        if ($creator === null) {
            return;
        }

        $membership = $creator->memberships()
            ->where('organization_id', $envelope->organization_id)
            ->first();

        if ($membership === null) {
            return;
        }

        $channels = array_filter([
            $this->preferences->wants($membership, 'envelope_expiring', 'mail') ? 'mail' : null,
            $this->preferences->wants($membership, 'envelope_expiring', 'database') ? 'database' : null,
        ]);

        if ($channels === []) {
            return;
        }

        $creator->notify(
            (new SenderEnvelopeExpiringNotification($envelope, $pendingCount, (string) Str::ulid()))
                ->restrictChannels(array_values($channels)),
        );
    }
}
