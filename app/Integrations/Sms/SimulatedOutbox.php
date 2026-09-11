<?php

namespace App\Integrations\Sms;

use App\Enums\DeliveryChannel;
use App\Enums\DeliveryStatus;
use App\Integrations\Contracts\Messaging\ChannelMessage;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Registro dos simuladores de SMS e WhatsApp — a "caixa de saída" que nada transmite.
 *
 * - Em memória, sempre (singleton): é onde os testes leem a mensagem e o código.
 * - No cache, só com `assinavelox.channels.simulated_outbox.persist` (padrão: ambiente
 *   `local`), para o desenvolvedor ler o código "enviado" com
 *   `php artisan tinker` → `app(App\Integrations\Sms\SimulatedOutbox::class)->recent()`.
 *
 * O código e o link ficam SÓ aqui — nunca em log, `delivery_attempts` ou trilha. Em produção
 * os simuladores estão desativados (`channels.allow_simulated = false`) e nada é registrado.
 */
final class SimulatedOutbox
{
    public const CACHE_KEY = 'assinavelox:channels:simulated-outbox';

    /** @var list<array<string, mixed>> */
    private array $messages = [];

    public function __construct(private readonly Repository $config) {}

    public function record(DeliveryChannel $channel, string $provider, string $messageId, ChannelMessage $message, DeliveryStatus $status): void
    {
        $this->messages[] = [
            'message_id' => $messageId,
            'channel' => $channel->value,
            'provider' => $provider,
            'to' => $message->toE164,
            'purpose' => $message->purpose->value,
            'template' => $message->template,
            'parameters' => $message->parameters,
            'text' => $message->text,
            'correlation_id' => $message->correlationId,
            'idempotency_key' => $message->idempotencyKey,
            'status' => $status->value,
            'recorded_at' => Carbon::now()->toIso8601String(),
            'simulated' => true,
        ];

        $this->persist();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(?DeliveryChannel $channel = null): array
    {
        if ($channel === null) {
            return $this->messages;
        }

        return array_values(array_filter(
            $this->messages,
            fn (array $entry): bool => $entry['channel'] === $channel->value,
        ));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function last(?DeliveryChannel $channel = null): ?array
    {
        $all = $this->all($channel);

        return $all === [] ? null : $all[count($all) - 1];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(DeliveryChannel $channel, string $messageId): ?array
    {
        foreach ($this->messages as $entry) {
            if ($entry['channel'] === $channel->value && $entry['message_id'] === $messageId) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByIdempotencyKey(DeliveryChannel $channel, string $key): ?array
    {
        foreach ($this->messages as $entry) {
            if ($entry['channel'] === $channel->value && $entry['idempotency_key'] === $key) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Códigos "enviados" a um número, na ordem (conveniência de teste e de desenvolvimento).
     *
     * @return list<string>
     */
    public function codesFor(string $toE164, ?DeliveryChannel $channel = null): array
    {
        $codes = [];

        foreach ($this->all($channel) as $entry) {
            if ($entry['to'] === $toE164 && isset($entry['parameters']['code'])) {
                $codes[] = (string) $entry['parameters']['code'];
            }
        }

        return $codes;
    }

    public function markStatus(DeliveryChannel $channel, string $messageId, DeliveryStatus $status): void
    {
        foreach ($this->messages as $index => $entry) {
            if ($entry['channel'] === $channel->value && $entry['message_id'] === $messageId) {
                $this->messages[$index]['status'] = $status->value;
            }
        }

        $this->persist();
    }

    /**
     * Últimas mensagens gravadas no cache (ambiente local).
     *
     * @return list<array<string, mixed>>
     */
    public function recent(): array
    {
        $stored = Cache::get(self::CACHE_KEY);

        return is_array($stored) ? array_values($stored) : $this->messages;
    }

    public function clear(): void
    {
        $this->messages = [];

        if ($this->shouldPersist()) {
            Cache::forget(self::CACHE_KEY);
        }
    }

    private function persist(): void
    {
        if (! $this->shouldPersist()) {
            return;
        }

        $max = max(1, (int) $this->config->get('assinavelox.channels.simulated_outbox.max_messages', 50));
        $ttl = max(1, (int) $this->config->get('assinavelox.channels.simulated_outbox.ttl_minutes', 30));

        Cache::put(self::CACHE_KEY, array_slice($this->messages, -$max), Carbon::now()->addMinutes($ttl));
    }

    private function shouldPersist(): bool
    {
        return (bool) $this->config->get('assinavelox.channels.simulated_outbox.persist', false)
            && (bool) $this->config->get('assinavelox.channels.allow_simulated', false);
    }
}
