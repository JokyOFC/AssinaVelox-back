<?php

namespace App\Services\Identity;

use App\Http\Middleware\ResolveSignerToken;
use App\Services\Signing\SignerContext;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

/**
 * Quando a Permissions-Policy pode trocar `camera=()` por `camera=(self)`
 * (docs/fase-2/identidade.md §5.4). Usado por `SecurityHeaders`.
 *
 * Só nas rotas PÚBLICAS de captura:
 *
 * - `sign.capture.store` — o controller marca {@see IdentityCaptures::CAMERA_ATTRIBUTE}
 *   depois de conferir flag, sessão e exigência;
 * - `sign.show` — a etapa de captura mora na página pública do participante; a câmera só é
 *   liberada quando o convite está ativo, a flag `identity_capture` da organização está ligada
 *   e há foto exigida para ESTA pessoa.
 *
 * Qualquer outra rota, e qualquer erro ao decidir, mantém `camera=()`.
 */
final class CameraPermission
{
    public static function allows(Request $request): bool
    {
        try {
            if ($request->attributes->get(IdentityCaptures::CAMERA_ATTRIBUTE) === true) {
                // Integração (I-2B): o dispositivo presencial (C-PRES) marca o atributo quando
                // o participante da vez tem foto exigida, pelas mesmas regras do link individual.
                return $request->routeIs('sign.capture.*', 'in_person.kiosk.show', 'in_person.kiosk.capture.*');
            }

            $route = $request->route();

            if (! $route instanceof Route || $route->getName() !== 'sign.show') {
                return false;
            }

            $context = $request->attributes->get(ResolveSignerToken::ATTRIBUTE);

            return $context instanceof SignerContext
                && app(IdentityCaptures::class)->cameraAllowedFor($context);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * `camera=()` → `camera=(self)`; sem diretiva de câmera, acrescenta. Nenhuma outra
     * diretiva muda.
     */
    public static function apply(string $policy): string
    {
        if (preg_match('/(^|,\s*)camera=\([^)]*\)/', $policy) === 1) {
            return (string) preg_replace('/(^|,\s*)camera=\([^)]*\)/', '$1camera=(self)', $policy);
        }

        return trim($policy) === '' ? 'camera=(self)' : rtrim($policy, ", \t").', camera=(self)';
    }
}
