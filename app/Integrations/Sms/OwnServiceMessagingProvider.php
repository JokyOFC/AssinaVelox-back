<?php

namespace App\Integrations\Sms;

use App\Integrations\Contracts\Exceptions\ProviderDisabledException;
use App\Integrations\Contracts\Messaging\ChannelMessage;
use App\Integrations\Contracts\Messaging\ChannelStatusEvent;
use App\Integrations\Contracts\Messaging\IncomingStatusCallback;
use App\Integrations\Contracts\Messaging\StatusCallbackVerification;
use App\Integrations\Contracts\MessagingProvider;
use App\Integrations\Dto\DeliveryReceipt;

/**
 * Base dos adaptadores de PRODUÇÃO dos serviços próprios (SMS, WhatsApp) — DESABILITADOS.
 *
 * A documentação desses serviços não está disponível (viabilidade, regra fixa 1; roadmap
 * T4). Escrever a chamada HTTP agora seria inventar um contrato. Por isso:
 *
 *  - `isConfigured()` e `acceptsStatusCallbacks()` são sempre `false`, mesmo com as
 *    variáveis de `config/services.php` preenchidas;
 *  - `send()` e `status()` lançam ProviderDisabledException com a lista exata do que falta;
 *  - `verifyStatusCallback()` recusa tudo (`provider_disabled`).
 *
 * Quando a documentação chegar, a implementação substitui estes métodos: timeout curto
 * (`channels.timeout_seconds`), chave de idempotência = `ChannelMessage::$idempotencyKey`,
 * tempo esgotado → recibo `unknown`, e o formato do webhook traduzido para ChannelStatusEvent.
 */
abstract class OwnServiceMessagingProvider implements MessagingProvider
{
    /**
     * @return list<string>
     */
    abstract protected function documentationGaps(): array;

    abstract protected function servicesKey(): string;

    abstract protected function envPrefix(): string;

    public function isConfigured(): bool
    {
        return false;
    }

    public function acceptsStatusCallbacks(): bool
    {
        return false;
    }

    public function isSimulated(): bool
    {
        return false;
    }

    public function missingRequirements(): array
    {
        $missing = $this->documentationGaps();
        $service = (array) config('services.'.$this->servicesKey(), []);

        foreach (['base_url' => 'BASE_URL', 'credentials' => 'CREDENTIALS', 'webhook_secret' => 'WEBHOOK_SECRET'] as $key => $suffix) {
            if (! is_string($service[$key] ?? null) || $service[$key] === '') {
                $missing[] = sprintf('Variável %s_%s não definida (config services.%s.%s).', $this->envPrefix(), $suffix, $this->servicesKey(), $key);
            }
        }

        $missing[] = 'Mesmo com as variáveis definidas, o adaptador continua desabilitado até ser implementado contra a documentação oficial do serviço.';

        return $missing;
    }

    public function send(ChannelMessage $message): DeliveryReceipt
    {
        throw new ProviderDisabledException($this->name(), $this->missingRequirements());
    }

    public function status(string $providerMessageId, ?string $correlationId = null): ChannelStatusEvent
    {
        throw new ProviderDisabledException($this->name(), $this->missingRequirements());
    }

    public function verifyStatusCallback(IncomingStatusCallback $callback): StatusCallbackVerification
    {
        return StatusCallbackVerification::invalid('provider_disabled');
    }

    public function parseStatusCallback(IncomingStatusCallback $callback): array
    {
        return [];
    }
}
