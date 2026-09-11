<?php

namespace App\Integrations\Cnpj;

use App\Integrations\Exceptions\IntegrationException;

/**
 * A consulta de CNPJ não chegou a uma resposta confiável: tempo esgotado, erro do servidor,
 * limite de taxa da fonte ou resposta malformada (docs/fase-2/identidade.md §3).
 *
 * Indisponível NÃO é "não encontrado": tempo esgotado é resultado desconhecido (regra T5),
 * nunca vira sucesso nem ausência. Quem chama mostra o formulário manual, que nunca bloqueia.
 */
final class CnpjLookupUnavailable extends IntegrationException
{
    public function __construct(
        public readonly string $reason,
        string $message = 'A consulta de CNPJ está indisponível no momento.',
    ) {
        parent::__construct($message);
    }
}
