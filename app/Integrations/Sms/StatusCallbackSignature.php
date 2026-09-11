<?php

namespace App\Integrations\Sms;

use App\Enums\DeliveryStatus;
use App\Integrations\Contracts\Messaging\ChannelStatusEvent;
use App\Integrations\Contracts\Messaging\IncomingStatusCallback;
use App\Integrations\Contracts\Messaging\StatusCallbackVerification;
use DateTimeImmutable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Contrato PROPOSTO por nós para o webhook de status de SMS/WhatsApp (docs/fase-2/canais-e-pin.md §5).
 *
 * O serviço próprio do proprietário não tem documentação; enquanto ela não chega, este é o
 * formato que o simulador usa e que o adaptador real terá de traduzir (ou que o serviço pode
 * adotar):
 *
 *  - cabeçalho `X-AssinaVelox-Timestamp`: segundos Unix do envio do aviso;
 *  - cabeçalho `X-AssinaVelox-Signature`: `v1=<hex>` com HMAC-SHA256(segredo,
 *    "{timestamp}.{corpo bruto}"). Vários valores separados por vírgula (rotação de segredo);
 *  - janela: |agora − timestamp| ≤ `assinavelox.channels.status_webhook.tolerance_seconds`;
 *  - corpo JSON: `{"events": [{"event_id", "message_id", "status", "occurred_at", "error"}]}`
 *    ou um único objeto com esses campos.
 *
 * Comparação em tempo constante. O segredo nunca sai daqui (nem em log, nem em exceção).
 */
final class StatusCallbackSignature
{
    public const SIGNATURE_HEADER = 'X-AssinaVelox-Signature';

    public const TIMESTAMP_HEADER = 'X-AssinaVelox-Timestamp';

    public static function sign(string $secret, int $timestamp, string $rawBody): string
    {
        return 'v1='.hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
    }

    /**
     * Cabeçalhos prontos (usado pelos testes e pelo simulador).
     *
     * @return array<string, string>
     */
    public static function headers(string $secret, string $rawBody, ?int $timestamp = null): array
    {
        $timestamp ??= time();

        return [
            self::TIMESTAMP_HEADER => (string) $timestamp,
            self::SIGNATURE_HEADER => self::sign($secret, $timestamp, $rawBody),
        ];
    }

    public static function verify(?string $secret, IncomingStatusCallback $callback, int $toleranceSeconds, ?int $now = null): StatusCallbackVerification
    {
        if ($secret === null || $secret === '') {
            return StatusCallbackVerification::invalid('secret_not_configured');
        }

        $signature = $callback->header(self::SIGNATURE_HEADER);
        $timestamp = $callback->header(self::TIMESTAMP_HEADER);

        if ($signature === null || $timestamp === null) {
            return StatusCallbackVerification::invalid('missing_signature');
        }

        if (preg_match('/^\d{1,12}$/', $timestamp) !== 1) {
            return StatusCallbackVerification::invalid('invalid_timestamp');
        }

        $now ??= time();

        if (abs($now - (int) $timestamp) > max(1, $toleranceSeconds)) {
            return StatusCallbackVerification::invalid('outside_window');
        }

        $expected = self::sign($secret, (int) $timestamp, $callback->rawBody);

        foreach (explode(',', $signature) as $candidate) {
            if (hash_equals($expected, trim($candidate))) {
                return StatusCallbackVerification::valid();
            }
        }

        return StatusCallbackVerification::invalid('invalid_signature');
    }

    /**
     * Eventos de um aviso JÁ validado. Campos fora do contrato são ignorados; valores longos
     * são descartados (nunca truncados em silêncio para casar com outra mensagem).
     *
     * @return list<ChannelStatusEvent>
     */
    public static function parseEvents(IncomingStatusCallback $callback): array
    {
        try {
            $body = json_decode($callback->rawBody, true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        if (! is_array($body)) {
            return [];
        }

        $items = isset($body['events']) && is_array($body['events']) ? $body['events'] : [$body];
        $events = [];

        foreach (array_slice($items, 0, 100) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $messageId = $item['message_id'] ?? null;
            $status = $item['status'] ?? null;

            if (! is_string($messageId) || $messageId === '' || strlen($messageId) > 191 || ! is_string($status)) {
                continue;
            }

            $eventId = $item['event_id'] ?? null;
            $eventId = is_string($eventId) && $eventId !== '' && strlen($eventId) <= 191 ? $eventId : null;

            $events[] = new ChannelStatusEvent(
                providerMessageId: $messageId,
                status: self::mapStatus($status),
                eventId: $eventId,
                occurredAt: self::date($item['occurred_at'] ?? null),
                detail: is_string($item['error'] ?? null) ? Str::limit($item['error'], 250, '') : null,
            );
        }

        return $events;
    }

    public static function mapStatus(string $status): DeliveryStatus
    {
        return match (strtolower(trim($status))) {
            'queued', 'accepted' => DeliveryStatus::Queued,
            'sent' => DeliveryStatus::Sent,
            'delivered', 'read' => DeliveryStatus::Delivered,
            'failed', 'undelivered', 'rejected', 'expired' => DeliveryStatus::Failed,
            default => DeliveryStatus::Unknown,
        };
    }

    private static function date(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || $value === '' || strlen($value) > 40) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }
}
