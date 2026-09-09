<?php

namespace App\Services\Signing;

use App\Models\Envelope;
use App\Services\Signing\Contracts\RevalidatesEnvelopeExpiration;

/**
 * Resolve QUEM revalida o prazo: a implementação registrada no container (módulo de envio)
 * ou o guarda padrão deste módulo.
 */
final class EnvelopeExpiration
{
    public static function resolve(): RevalidatesEnvelopeExpiration
    {
        if (app()->bound(RevalidatesEnvelopeExpiration::class)) {
            /** @var RevalidatesEnvelopeExpiration */
            return app(RevalidatesEnvelopeExpiration::class);
        }

        return app(EnvelopeExpirationGuard::class);
    }

    public static function revalidate(Envelope $envelope): Envelope
    {
        return self::resolve()->revalidate($envelope);
    }
}
