<?php

namespace App\Integrations\Sms;

use App\Enums\DeliveryStatus;
use App\Integrations\Contracts\Messaging\ChannelMessage;
use App\Integrations\Contracts\Messaging\ChannelStatusEvent;
use App\Integrations\Contracts\Messaging\IncomingStatusCallback;
use App\Integrations\Contracts\Messaging\StatusCallbackVerification;
use App\Integrations\Contracts\MessagingProvider;
use App\Integrations\Dto\DeliveryReceipt;
use App\Integrations\Dto\DeliveryReceiptStatus;
use App\Rules\PhoneE164;
use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Base dos simuladores IDENTIFICADOS de SMS e WhatsApp. **Nada é transmitido.**
 *
 * - Cada envio vai para o {@see SimulatedOutbox} e gera um aviso `[SIMULADO]` no log com
 *   metadados apenas (destino mascarado, finalidade, template, correlation_id). O código e
 *   o link nunca aparecem no log.
 * - O recibo padrão é `unknown` — registrar não é enviar — com `meta.simulated = true`.
 * - Só funciona com `assinavelox.channels.allow_simulated` (padrão: fora de produção).
 *
 * {@see self::simulate()} troca a resposta para os testes: `sent` (aceito), `fail` (recusado)
 * ou `timeout` (lança ConnectionException, como um cliente HTTP com tempo esgotado).
 */
abstract class SimulatedMessagingProvider implements MessagingProvider
{
    public const MODE_ACCEPT = 'accept';

    public const MODE_SENT = 'sent';

    public const MODE_FAIL = 'fail';

    public const MODE_TIMEOUT = 'timeout';

    private string $mode = self::MODE_ACCEPT;

    public function __construct(
        protected readonly SimulatedOutbox $outbox,
        protected readonly Repository $config,
    ) {}

    abstract protected function label(): string;

    /**
     * Resposta das próximas chamadas (testes).
     */
    public function simulate(string $mode): void
    {
        $this->mode = $mode;
    }

    public function isSimulated(): bool
    {
        return true;
    }

    /**
     * O interruptor sozinho não basta: em produção o simulador é recusado sempre, como os
     * simuladores de CPF e CNPJ (as fábricas os recusam por APP_ENV). Um `.env` de homologação
     * copiado para produção não pode ligar um canal que nunca entrega o código.
     */
    public function isConfigured(): bool
    {
        return (bool) $this->config->get('assinavelox.channels.allow_simulated', false)
            && ! self::inProduction();
    }

    private static function inProduction(): bool
    {
        return app()->environment('production');
    }

    public function acceptsStatusCallbacks(): bool
    {
        $secret = $this->config->get('assinavelox.channels.status_webhook.simulated_secret');

        return $this->isConfigured() && is_string($secret) && $secret !== '';
    }

    public function missingRequirements(): array
    {
        if ($this->isConfigured()) {
            return [];
        }

        return [
            self::inProduction()
                ? 'O simulador de '.$this->label().' nunca funciona em produção (APP_ENV=production), mesmo com ASSINAVELOX_CHANNELS_ALLOW_SIMULATED=true.'
                : 'O simulador de '.$this->label().' está desativado nesta instalação (ASSINAVELOX_CHANNELS_ALLOW_SIMULATED=false) e nunca funciona em produção.',
            'O serviço próprio de '.$this->label().' ainda não foi integrado (faltam documentação e credenciais).',
        ];
    }

    public function send(ChannelMessage $message): DeliveryReceipt
    {
        if (! $this->isConfigured()) {
            return DeliveryReceipt::failed(
                $this->name(),
                'Simulador de '.$this->label().' desativado nesta instalação: nada foi enviado.',
                $message->correlationId,
            );
        }

        $existing = $this->outbox->findByIdempotencyKey($this->channel(), $message->idempotencyKey);

        if ($existing !== null) {
            return new DeliveryReceipt(
                DeliveryReceiptStatus::from((string) ($existing['status'] === DeliveryStatus::Sent->value ? 'sent' : 'unknown')),
                $this->name(),
                (string) $existing['message_id'],
                'Simulador: chave de idempotência repetida — nenhuma mensagem nova.',
                $message->correlationId,
                null,
                ['simulated' => true, 'deduplicated' => true],
            );
        }

        if ($this->mode === self::MODE_TIMEOUT) {
            throw new ConnectionException('Tempo esgotado ao falar com o provedor (simulado).');
        }

        if ($this->mode === self::MODE_FAIL) {
            return DeliveryReceipt::failed($this->name(), 'Falha simulada pelo provedor de '.$this->label().'.', $message->correlationId);
        }

        $messageId = 'sim-'.$this->channel()->value.'-'.Str::lower((string) Str::ulid());
        $status = $this->mode === self::MODE_SENT ? DeliveryStatus::Sent : DeliveryStatus::Unknown;

        $this->outbox->record($this->channel(), $this->name(), $messageId, $message, $status);

        Log::warning('[SIMULADO] '.$this->label().' NÃO enviado — '.class_basename(static::class).' (nada foi transmitido).', [
            'provider' => $this->name(),
            'channel' => $this->channel()->value,
            'purpose' => $message->purpose->value,
            'template' => $message->template,
            'to' => PhoneE164::mask($message->toE164),
            'correlation_id' => $message->correlationId,
            'message_id' => $messageId,
            // Sem o código e sem o link: só os nomes dos parâmetros, com os segredos omitidos.
            'parameters' => $message->safeParameters(),
        ]);

        return new DeliveryReceipt(
            $status === DeliveryStatus::Sent ? DeliveryReceiptStatus::Sent : DeliveryReceiptStatus::Unknown,
            $this->name(),
            $messageId,
            $status === DeliveryStatus::Sent ? null : 'Simulador de '.$this->label().': a mensagem foi apenas registrada, nada foi transmitido.',
            $message->correlationId,
            $status === DeliveryStatus::Sent ? new DateTimeImmutable : null,
            ['simulated' => true],
        );
    }

    public function status(string $providerMessageId, ?string $correlationId = null): ChannelStatusEvent
    {
        $entry = $this->outbox->find($this->channel(), $providerMessageId);

        if ($entry === null) {
            return new ChannelStatusEvent($providerMessageId, DeliveryStatus::Unknown, detail: 'Mensagem desconhecida pelo simulador.');
        }

        return new ChannelStatusEvent(
            $providerMessageId,
            DeliveryStatus::tryFrom((string) $entry['status']) ?? DeliveryStatus::Unknown,
            detail: 'Situação simulada.',
        );
    }

    public function verifyStatusCallback(IncomingStatusCallback $callback): StatusCallbackVerification
    {
        if (! $this->acceptsStatusCallbacks()) {
            return StatusCallbackVerification::invalid('secret_not_configured');
        }

        $secret = $this->config->get('assinavelox.channels.status_webhook.simulated_secret');

        return StatusCallbackSignature::verify(
            is_string($secret) ? $secret : null,
            $callback,
            (int) $this->config->get('assinavelox.channels.status_webhook.tolerance_seconds', 300),
        );
    }

    public function parseStatusCallback(IncomingStatusCallback $callback): array
    {
        return StatusCallbackSignature::parseEvents($callback);
    }
}
