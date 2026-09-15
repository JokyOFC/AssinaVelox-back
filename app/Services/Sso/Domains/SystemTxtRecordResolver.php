<?php

namespace App\Services\Sso\Domains;

/**
 * Resolvedor TXT do sistema (`dns_get_record(DNS_TXT)`). Registros com várias strings
 * (`entries`) são concatenados, como manda a RFC 7208 §3.3 para TXT longos.
 */
final class SystemTxtRecordResolver implements TxtRecordResolver
{
    public function txt(string $name): array
    {
        $records = @dns_get_record($name, DNS_TXT);

        if (! is_array($records)) {
            return [];
        }

        $values = [];

        foreach ($records as $record) {
            if (isset($record['entries']) && is_array($record['entries'])) {
                $values[] = implode('', array_filter($record['entries'], 'is_string'));
            } elseif (isset($record['txt']) && is_string($record['txt'])) {
                $values[] = $record['txt'];
            }
        }

        return array_values(array_unique($values));
    }
}
