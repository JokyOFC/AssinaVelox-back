<?php

namespace App\Support\Http;

/**
 * Resolução de nomes usada ANTES de qualquer conexão de saída (OutboundUrlGuard). Interface
 * para que os testes troquem por um resolvedor falso — nenhum teste depende de DNS real.
 */
interface DnsResolver
{
    /**
     * Todos os endereços A e AAAA do nome (vazio quando não resolve).
     *
     * @return list<string>
     */
    public function resolve(string $host): array;
}
