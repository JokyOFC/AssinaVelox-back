<?php

namespace App\Services\Risk\Notifications;

use App\Models\Organization;
use App\Services\Risk\RiskDecision;
use App\Support\MailText;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Aviso aos proprietários e administradores da organização (roadmap §3.7, "mensagem clara ao
 * usuário e ao administrador"):
 *
 * - `restricted`: o envio de NOVOS documentos foi suspenso até revisão; o que continua
 *   funcionando; como pedir revisão (LGPD art. 20).
 * - `decision`: resultado de uma revisão humana.
 *
 * Nunca traz limiares, pontuações ou dados de terceiros — só o critério geral, que a página
 * de revisão explica regra a regra.
 */
class OrganizationRiskNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public const KIND_RESTRICTED = 'restricted';

    public const KIND_DECISION = 'decision';

    public function __construct(
        public readonly Organization $organization,
        public readonly string $kind,
        public readonly ?RiskDecision $decision = null,
    ) {
        $this->onQueue((string) config('assinavelox.queues.notifications', 'notifications'));
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = MailText::escape($this->organization->name);
        $url = route('risk.appeal.show');

        if ($this->kind === self::KIND_RESTRICTED) {
            return (new MailMessage)
                ->subject('Envio de novos documentos suspenso para revisão — '.$this->organization->name)
                ->line('Nossos controles de segurança identificaram sinais incomuns na conta **'.$name.'** e suspenderam, por precaução, o envio de novos documentos até que uma pessoa da equipe AssinaVelox revise o caso.')
                ->line('Documentos já enviados continuam disponíveis para leitura, assinatura e download. Aceites e evidências já registrados não foram alterados.')
                ->line('Você pode ver os critérios que motivaram a suspensão e pedir a revisão pela página abaixo.')
                ->action('Ver motivo e pedir revisão', $url);
        }

        $decision = $this->decision ?? RiskDecision::Clear;

        return (new MailMessage)
            ->subject('Revisão de segurança concluída — '.$this->organization->name)
            ->line('A equipe AssinaVelox concluiu a revisão de segurança da conta **'.$name.'**.')
            ->line('Resultado: '.$decision->label().'. '.$decision->description())
            ->action('Ver detalhes', $url);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $restricted = $this->kind === self::KIND_RESTRICTED;

        return [
            'organization_id' => $this->organization->getKey(),
            'title' => $restricted ? 'Envio de novos documentos suspenso para revisão' : 'Revisão de segurança concluída',
            'body' => $restricted
                ? 'Documentos já enviados continuam disponíveis. Veja o motivo e peça a revisão.'
                : ($this->decision ?? RiskDecision::Clear)->description(),
            'url' => route('risk.appeal.show'),
            'event' => $restricted ? 'risk_restricted' : 'risk_review_decided',
        ];
    }
}
