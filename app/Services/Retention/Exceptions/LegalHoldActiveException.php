<?php

namespace App\Services\Retention\Exceptions;

use App\Models\LegalHold;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Tentativa de apagar algo coberto por um bloqueio de preservação ativo. A tentativa já foi
 * registrada na trilha quando esta exceção chega a quem chamou.
 *
 * Renderiza sozinha: 409 em JSON; nas telas, volta com a mensagem de erro (flash), para que
 * qualquer caminho de exclusão possa só chamar a guarda sem tratar a resposta.
 */
final class LegalHoldActiveException extends RuntimeException
{
    public function __construct(public readonly LegalHold $hold, string $message)
    {
        parent::__construct($message);
    }

    /**
     * Não é falha do sistema: a tentativa já está em `retention_events`. Nada no log de erro.
     */
    public function report(): void {}

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return new JsonResponse(['message' => $this->getMessage(), 'code' => 'legal_hold_active'], 409);
        }

        return back()->with('error', $this->getMessage());
    }
}
