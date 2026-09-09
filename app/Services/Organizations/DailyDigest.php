<?php

namespace App\Services\Organizations;

use App\Enums\EnvelopeStatus;
use App\Enums\MembershipStatus;
use App\Enums\RecipientStatus;
use App\Models\Envelope;
use App\Models\Membership;
use App\Notifications\Organizations\DailyDigestNotification;
use App\Support\CurrentOrganization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * "Resumo diário de pendências" (ROUTES §2.14, evento `daily_digest`).
 *
 * A tela de Notificações não só oferece o interruptor — ela promete o HORÁRIO, no rodapé:
 * "Resumo diário de pendências enviado às 08:00 (America/Sao_Paulo)". Até esta correção
 * nenhum `Schedule::command` o enviava: o usuário ligava a opção, recebia "Preferências
 * salvas." e nunca recebia e-mail nenhum.
 *
 * Regras deliberadas:
 *
 *  - **um por dia útil**: sábado e domingo não recebem, como a descrição da tela diz;
 *  - **só quando há o que contar**: sem envelope pendente, nenhum e-mail sai. Um resumo
 *    diário que chega dizendo "nada pendente" é ruído e ensina a ignorar o próximo;
 *  - **cada um vê o que é seu**: a lista respeita `EnvelopeVisibility` — quem é `member` vê
 *    apenas os documentos que criou;
 *  - **idempotente**: a marca do último envio fica na própria membership
 *    (`notification_preferences` não serve; a marca vai em `daily_digest_sent_on`), então
 *    duas execuções no mesmo dia não mandam dois e-mails.
 */
class DailyDigest
{
    /** Quantos documentos entram no corpo do e-mail. */
    public const MAX_ITEMS = 10;

    public function __construct(private readonly NotificationPreferences $preferences) {}

    /**
     * @return array{sent: int, skipped: int}
     */
    public function run(?Carbon $now = null, ?int $limit = null): array
    {
        $now ??= Carbon::now();

        $sent = 0;
        $skipped = 0;

        $query = Membership::query()
            ->where('status', MembershipStatus::Active->value)
            ->with(['user', 'organization'])
            ->orderBy('id');

        if ($limit !== null) {
            $query->limit($limit);
        }

        foreach ($query->get() as $membership) {
            if (! $this->preferences->wants($membership, 'daily_digest', 'mail')) {
                $skipped++;

                continue;
            }

            $organization = $membership->organization;
            $user = $membership->user;

            $localNow = $now->copy()->setTimezone($organization->timezone);

            // "Um e-mail por dia útil", como a tela promete.
            if ($localNow->isWeekend()) {
                $skipped++;

                continue;
            }

            $today = $localNow->toDateString();

            if ((string) $membership->getAttribute('daily_digest_sent_on') === $today) {
                $skipped++;

                continue;
            }

            $items = $this->itemsFor($membership, $organization->timezone);

            // Marca o dia mesmo sem itens: sem isso a varredura reconsultaria a mesma
            // membership a cada rodada do agendador.
            $membership->forceFill(['daily_digest_sent_on' => $today])->save();

            if ($items === []) {
                $skipped++;

                continue;
            }

            $user->notify(
                (new DailyDigestNotification($membership, $items, (string) Str::ulid()))
                    ->restrictChannels(['mail']),
            );

            $sent++;
        }

        return ['sent' => $sent, 'skipped' => $skipped];
    }

    /**
     * Documentos em andamento visíveis a esta membership, com quantos ainda faltam assinar.
     *
     * @return list<array{title: string, code: string, pending: int, expires_at: string|null}>
     */
    private function itemsFor(Membership $membership, string $timezone): array
    {
        return CurrentOrganization::instance()->runAs($membership->organization, function () use ($membership, $timezone): array {
            $envelopes = EnvelopeVisibility::envelopes($membership)
                ->where('status', EnvelopeStatus::InProgress->value)
                ->orderBy('expires_at')
                ->orderBy('id')
                ->limit(self::MAX_ITEMS)
                ->get();

            $items = [];

            foreach ($envelopes as $envelope) {
                $pending = $this->pendingCount($envelope);

                if ($pending === 0) {
                    continue;
                }

                $items[] = [
                    'title' => (string) $envelope->title,
                    'code' => (string) $envelope->display_code,
                    'pending' => $pending,
                    'expires_at' => $envelope->expires_at?->setTimezone($timezone)->format('d/m/Y'),
                ];
            }

            return $items;
        }, $membership);
    }

    private function pendingCount(Envelope $envelope): int
    {
        return $envelope->recipients()
            ->whereIn('status', [
                RecipientStatus::Pending->value,
                RecipientStatus::Notified->value,
                RecipientStatus::Viewed->value,
            ])
            ->count();
    }
}
