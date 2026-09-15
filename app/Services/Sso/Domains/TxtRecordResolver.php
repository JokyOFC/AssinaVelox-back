<?php

namespace App\Services\Sso\Domains;

/**
 * Consulta de registros TXT para a verificação de domínio do login corporativo
 * (docs/fase-3/sso.md §4). Interface para que os testes troquem por um resolvedor falso —
 * nenhum teste depende de DNS real.
 */
interface TxtRecordResolver
{
    /**
     * Valores TXT do nome (cada registro com as partes já concatenadas); vazio se não houver.
     *
     * @return list<string>
     */
    public function txt(string $name): array;
}
