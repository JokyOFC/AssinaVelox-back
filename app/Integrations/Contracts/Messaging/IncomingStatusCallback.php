<?php

namespace App\Integrations\Contracts\Messaging;

/**
 * Aviso de status recebido no webhook, como chegou: corpo BRUTO (a assinatura é calculada
 * sobre os bytes, não sobre o JSON reinterpretado) e cabeçalhos com nome em minúsculas.
 */
final readonly class IncomingStatusCallback
{
    /**
     * @param  array<string, string>  $headers  nome em minúsculas → primeiro valor
     */
    public function __construct(
        public string $rawBody,
        public array $headers,
    ) {}

    public function header(string $name): ?string
    {
        $value = $this->headers[strtolower($name)] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
