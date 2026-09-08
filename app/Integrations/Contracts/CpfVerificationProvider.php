<?php

namespace App\Integrations\Contracts;

/**
 * Fase 2/3 — sem implementação. Verificação de CPF (situação cadastral / nome
 * e data de nascimento) junto a provedor autorizado.
 *
 * Resposta inconclusiva ≠ sucesso: `status` deve ser um de valid | invalid |
 * inconclusive, e a decisão de bloquear ou não cabe ao serviço chamador.
 * Dados pessoais só são enviados com base legal registrada e nunca ficam em log.
 */
interface CpfVerificationProvider
{
    /**
     * @param  array{name?: string, birth_date?: string}  $context
     * @return array{status: 'valid'|'invalid'|'inconclusive', provider: string, checked_at: string, details?: array<string, mixed>}
     */
    public function verify(string $cpf, array $context = [], ?string $correlationId = null): array;

    public function isConfigured(): bool;

    public function name(): string;
}
