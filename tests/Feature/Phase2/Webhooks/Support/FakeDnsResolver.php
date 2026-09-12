<?php

namespace Tests\Feature\Phase2\Webhooks\Support;

use App\Support\Http\DnsResolver;

/**
 * Resolvedor falso: nenhum teste depende de DNS real. Registra as consultas para provar que
 * IP literal e host interno são recusados ANTES de qualquer resolução.
 */
final class FakeDnsResolver implements DnsResolver
{
    /** @var array<string, list<string>> */
    public array $records = [];

    /** @var list<string> */
    public array $lookups = [];

    /**
     * @param  list<string>  $addresses
     */
    public function set(string $host, array $addresses): self
    {
        $this->records[strtolower($host)] = $addresses;

        return $this;
    }

    public function resolve(string $host): array
    {
        $this->lookups[] = $host;

        return $this->records[strtolower($host)] ?? [];
    }
}
