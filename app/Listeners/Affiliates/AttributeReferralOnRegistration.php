<?php

namespace App\Listeners\Affiliates;

use App\Models\User;
use App\Services\Affiliates\AffiliatesFeature;
use App\Services\Affiliates\Attribution;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cadastro concluído (evento `Registered` do Fortify) → atribuição da organização criada ao
 * afiliado do cookie (Fase 3 §3.10). Descoberto automaticamente (app/Listeners).
 *
 * Captura ADITIVA: não muda nada do cadastro — roda depois dele, com a flag desligada não faz
 * nada e nunca lança (uma falha aqui não pode impedir ninguém de se cadastrar).
 */
final class AttributeReferralOnRegistration
{
    public function __construct(private readonly Attribution $attribution) {}

    public function handle(Registered $event): void
    {
        if (! AffiliatesFeature::enabled() || ! $event->user instanceof User || ! app()->bound('request')) {
            return;
        }

        try {
            $this->attribution->attributeRegistration($event->user, request());
        } catch (Throwable $exception) {
            Log::error('affiliates.attribution.failed', [
                'user' => $event->user->getKey(),
                'exception' => $exception::class,
                'message' => mb_substr($exception->getMessage(), 0, 300),
            ]);
        }
    }
}
