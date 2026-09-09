<?php

namespace App\Notifications\Organizations;

use App\Notifications\Concerns\RestrictsChannels;
use App\Support\MailText;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Novidades do produto" — evento `product_news` da tela de Notificações (ROUTES §2.14).
 *
 * É o único evento do catálogo sem gatilho automático possível: um lançamento não acontece
 * sozinho, alguém decide anunciá-lo. O produtor, portanto, é o comando
 * `notifications:product-news`, operado por um administrador da plataforma.
 *
 * O catálogo trava o canal `mail` e deixa só o sino do app: novidade de produto não é
 * transacional, e mandá-la por e-mail exigiria consentimento de marketing que a Fase 1 não
 * coleta. Esta notificação respeita a trava — `via()` só devolve o que o serviço permitir.
 */
class ProductNewsNotification extends Notification implements ShouldQueue
{
    use Queueable, RestrictsChannels;

    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $url = null,
    ) {
        $this->onQueue((string) config('assinavelox.queues.notifications', 'notifications'));
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return $this->channelsFrom(['mail' => 'mail', 'database' => 'database']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject($this->title)
            ->greeting('Olá!')
            ->line(MailText::escape($this->body));

        if ($this->url !== null) {
            $message->action('Saiba mais', $this->url);
        }

        return $message->salutation('Atenciosamente, AssinaVelox');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'product_news',
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->url,
        ];
    }
}
