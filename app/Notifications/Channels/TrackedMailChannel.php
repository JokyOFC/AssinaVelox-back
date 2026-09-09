<?php

namespace App\Notifications\Channels;

use App\Integrations\Contracts\EmailProvider;
use App\Integrations\Dto\OutboundEmail;
use App\Integrations\Email\DeliveryRecorder;
use App\Models\DeliveryAttempt;
use App\Notifications\Contracts\TracksDelivery;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use RuntimeException;

/**
 * Canal de notificação que envia pelo contrato `EmailProvider` e registra a tentativa em
 * `delivery_attempts` (arquitetura §3.1 e §8).
 *
 * Por que não o canal `mail` do Laravel: o canal padrão entrega ao mailer e esquece. Aqui
 * cada mensagem precisa de uma linha auditável (canal, propósito, destinatário, estado,
 * identificador do provedor, correlation_id) e de repetição idempotente.
 *
 * O corpo é montado com o mesmo template Markdown das notificações do Laravel — sem pixel
 * de rastreamento, sem link de "abrir no navegador", sem redirecionador de cliques.
 */
class TrackedMailChannel
{
    public function __construct(
        private readonly EmailProvider $provider,
        private readonly DeliveryRecorder $recorder,
        private readonly Markdown $markdown,
    ) {}

    public function send(object $notifiable, Notification $notification): ?DeliveryAttempt
    {
        if (! $notification instanceof TracksDelivery) {
            throw new RuntimeException($notification::class.' precisa implementar '.TracksDelivery::class.' para usar o canal rastreado.');
        }

        if (! method_exists($notification, 'toMail')) {
            throw new RuntimeException($notification::class.' precisa de toMail() para o canal rastreado.');
        }

        $message = $notification->toMail($notifiable);

        if (! $message instanceof MailMessage) {
            throw new RuntimeException($notification::class.'::toMail() precisa devolver uma MailMessage.');
        }

        $context = $notification->deliveryContext($notifiable);
        $address = $this->resolveAddress($notifiable, $message, $context->toAddress);
        $context = $context->withAddress($address);

        // Idempotência: a mesma tentativa (mesmo correlation_id) já concluída não é repetida.
        $already = $this->recorder->successFor($context);

        if ($already !== null) {
            return $already;
        }

        $attempt = $this->recorder->queue($context, $this->provider->name());

        $receipt = $this->provider->send($this->buildEmail($message, $context->toAddress, $context->correlationId, $context->purpose->value));

        return $this->recorder->record($attempt, $receipt);
    }

    private function buildEmail(MailMessage $message, string $address, string $correlationId, string $purpose): OutboundEmail
    {
        $view = $message->markdown ?? 'notifications::email';
        $data = $message->data();

        if ($message->theme !== null) {
            $this->markdown->theme($message->theme);
        }

        [$fromAddress, $fromName] = [$message->from[0] ?? null, $message->from[1] ?? null];
        $replyTo = $message->replyTo[0][0] ?? null;
        $subject = (string) $message->subject;

        return new OutboundEmail(
            toAddress: $address,
            toName: null,
            subject: $subject !== '' ? $subject : (string) config('app.name'),
            htmlBody: (string) $this->markdown->render($view, $data),
            textBody: (string) $this->markdown->renderText($view, $data),
            fromAddress: is_string($fromAddress) ? $fromAddress : null,
            fromName: is_string($fromName) ? $fromName : null,
            replyToAddress: is_string($replyTo) ? $replyTo : null,
            headers: [
                // Sem indexação por buscadores nem pré-visualização agressiva de clientes.
                'X-Auto-Response-Suppress' => 'OOF, AutoReply',
            ],
            tags: [$purpose],
            attachments: [],
            correlationId: $correlationId,
        );
    }

    /**
     * O endereço do notifiable manda (AnonymousNotifiable roteado, User->email); o contexto
     * é o fallback quando o notifiable não sabe se rotear.
     */
    private function resolveAddress(object $notifiable, MailMessage $message, string $fallback): string
    {
        $routed = method_exists($notifiable, 'routeNotificationFor')
            ? $notifiable->routeNotificationFor('mail', $message)
            : null;

        if (is_array($routed)) {
            $routed = array_key_first($routed);
        }

        return is_string($routed) && $routed !== '' ? $routed : $fallback;
    }
}
