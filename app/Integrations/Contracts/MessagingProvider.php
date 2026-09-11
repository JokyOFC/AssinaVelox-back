<?php

namespace App\Integrations\Contracts;

use App\Enums\DeliveryChannel;
use App\Integrations\Contracts\Exceptions\ProviderDisabledException;
use App\Integrations\Contracts\Messaging\ChannelMessage;
use App\Integrations\Contracts\Messaging\ChannelStatusEvent;
use App\Integrations\Contracts\Messaging\IncomingStatusCallback;
use App\Integrations\Contracts\Messaging\StatusCallbackVerification;
use App\Integrations\Dto\DeliveryReceipt;
use Illuminate\Http\Client\ConnectionException;

/**
 * Contrato comum de SMS e WhatsApp (Fase 2 §2.9, docs/fase-2/canais-e-pin.md).
 *
 * Os dois são serviços PRÓPRIOS do proprietário sem documentação: hoje só existem o
 * simulador identificado (`isSimulated() = true`) e o adaptador de produção desabilitado
 * (`isConfigured() = false`, `missingRequirements()` com a lista exata do que falta).
 *
 * Regras de todo adaptador:
 *  - `send()` não lança por RECUSA do provedor: devolve DeliveryReceipt `failed` ou `unknown`.
 *    Pode lançar ConnectionException (tempo esgotado, conexão caída — o resultado é
 *    desconhecido) e ProviderDisabledException (adaptador desabilitado). Quem chama
 *    (App\Services\Signing\Channels\ChannelDelivery) grava o primeiro como `unknown` (T5),
 *    nunca como `sent`.
 *  - `sent` = aceito pelo provedor; `delivered` só por aviso assinado ou consulta.
 *  - repetir a mesma `idempotencyKey` não gera segunda mensagem;
 *  - os parâmetros listados em `ChannelMessage::$sensitive` (código, link) e o `text` nunca
 *    vão para log, exceção ou metadado.
 */
interface MessagingProvider
{
    public function channel(): DeliveryChannel;

    /**
     * Envia a mensagem de desafio/convite com o template da finalidade.
     *
     * @throws ProviderDisabledException
     * @throws ConnectionException tempo esgotado: resultado desconhecido
     */
    public function send(ChannelMessage $message): DeliveryReceipt;

    /**
     * Consulta a situação de uma mensagem já enviada (T5: consultar antes de repetir).
     *
     * @throws ProviderDisabledException
     */
    public function status(string $providerMessageId, ?string $correlationId = null): ChannelStatusEvent;

    /**
     * Valida a assinatura (HMAC + carimbo de tempo + janela) de um aviso de status. Nunca
     * confia no corpo antes disso.
     */
    public function verifyStatusCallback(IncomingStatusCallback $callback): StatusCallbackVerification;

    /**
     * Eventos contidos num aviso JÁ validado.
     *
     * @return list<ChannelStatusEvent>
     */
    public function parseStatusCallback(IncomingStatusCallback $callback): array;

    /**
     * O adaptador pode enviar mensagens agora.
     */
    public function isConfigured(): bool;

    /**
     * O webhook de status deste provedor está ativo (há segredo para validar a assinatura).
     */
    public function acceptsStatusCallbacks(): bool;

    /**
     * Simulador: nada é transmitido. A interface e as evidências dizem "simulado".
     */
    public function isSimulated(): bool;

    /**
     * Nome curto gravado em delivery_attempts.provider.
     */
    public function name(): string;

    /**
     * O que falta para o adaptador funcionar (vazio quando está pronto).
     *
     * @return list<string>
     */
    public function missingRequirements(): array;
}
