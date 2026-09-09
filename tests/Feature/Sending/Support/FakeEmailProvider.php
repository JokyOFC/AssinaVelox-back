<?php

namespace Tests\Feature\Sending\Support;

use App\Integrations\Contracts\EmailProvider;
use App\Integrations\Dto\DeliveryReceipt;
use App\Integrations\Dto\OutboundEmail;

/**
 * Duplo do provedor de e-mail: guarda as mensagens que passaram e devolve o recibo pedido.
 *
 * Guardar o corpo é o ponto: é assim que os testes verificam que o token do convite chega
 * ao destinatário pelo e-mail **e** que ele não aparece em nenhum outro lugar (trilha,
 * `delivery_attempts`, log).
 */
class FakeEmailProvider implements EmailProvider
{
    /** @var list<OutboundEmail> */
    public array $sent = [];

    public function __construct(private readonly string $outcome = 'sent') {}

    public function name(): string
    {
        return 'fake_provider';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function send(OutboundEmail $email): DeliveryReceipt
    {
        $this->sent[] = $email;

        return match ($this->outcome) {
            'failed' => DeliveryReceipt::failed($this->name(), 'Servidor recusou a mensagem.', $email->correlationId),
            'unknown' => DeliveryReceipt::unknown($this->name(), 'Resposta inconclusiva.', $email->correlationId),
            default => DeliveryReceipt::sent($this->name(), 'msg-'.count($this->sent), $email->correlationId),
        };
    }

    /**
     * @return list<OutboundEmail>
     */
    public function to(string $address): array
    {
        return array_values(array_filter($this->sent, fn (OutboundEmail $email): bool => $email->toAddress === $address));
    }

    /**
     * Última mensagem enviada para o endereço (a mais recente da conversa).
     */
    public function lastTo(string $address): ?OutboundEmail
    {
        $messages = $this->to($address);

        return $messages === [] ? null : $messages[count($messages) - 1];
    }

    public function bodies(): string
    {
        return implode("\n", array_map(
            fn (OutboundEmail $email): string => $email->htmlBody.$email->textBody,
            $this->sent,
        ));
    }

    public function count(): int
    {
        return count($this->sent);
    }
}
