<?php

namespace App\Services\Envelopes;

use App\Models\Envelope;
use Illuminate\Validation\ValidationException;

/**
 * Invariante compartilhada pelos serviços de preparo ({@see FieldSync},
 * {@see RecipientSync}): **só se prepara envelope em `draft`, `preparing` ou `ready`**.
 *
 * A checagem morava só nos controllers (`if (! $envelope->status->isDraftLike())`), sobre
 * o model do route binding, fora de qualquer transação e sem lock. Isso deixava dois
 * buracos:
 *
 * 1. **Uso direto do serviço** (job, comando, outro controller) passava batido pela
 *    guarda. Como `signature_acceptances.recipient_id` e
 *    `signing_field_values.signing_field_id` são `ON DELETE CASCADE`, um sync sobre um
 *    envelope `in_progress` APAGAVA o aceite de quem já tinha assinado e os valores de
 *    campo daquele aceite. Evidência jurídica destruída por um serviço de preparo.
 * 2. **TOCTOU real**: o wizard salva os campos sozinho; um `PUT` que carregou o envelope
 *    como `ready` continuava valendo depois de outra requisição ter concluído o envio.
 *
 * A correção é a mesma que `Sending\SendEnvelope::commitSend()` já usava e que este
 * projeto adota como referência: recarregar sob `lockForUpdate()` dentro da transação e
 * decidir pelo que está no banco, não pelo que a requisição trouxe. A guarda do
 * controller continua existindo, mas só como resposta amigável.
 */
final class PreparationGuard
{
    /**
     * Recarrega o envelope sob lock e devolve a linha travada, ou recusa a operação.
     *
     * @throws ValidationException
     */
    public static function lockForPreparation(Envelope $envelope, string $field): Envelope
    {
        /** @var Envelope|null $locked */
        $locked = Envelope::withoutOrganizationScope()
            ->whereKey($envelope->getKey())
            ->lockForUpdate()
            ->first();

        if ($locked === null || ! $locked->status->isDraftLike()) {
            throw ValidationException::withMessages([
                $field => 'Este documento já saiu da preparação e não pode mais ser alterado.',
            ]);
        }

        return $locked;
    }
}
