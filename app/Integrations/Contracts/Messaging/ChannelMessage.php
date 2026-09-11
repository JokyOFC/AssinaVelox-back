<?php

namespace App\Integrations\Contracts\Messaging;

use App\Enums\DeliveryPurpose;

/**
 * Mensagem de saída por SMS ou WhatsApp (Fase 2 §2.9), independente do provedor.
 *
 * - `template` + `parameters`: o que o WhatsApp exige (template pré-aprovado por finalidade);
 * - `text`: o corpo pronto para SMS (e para o simulador mostrar).
 *
 * `sensitive` lista os parâmetros que são SEGREDO (o código, o link com token): nenhum
 * adaptador pode registrá-los em log, `delivery_attempts.meta` ou exceção. O `text` também
 * os contém — por isso nunca é logado.
 *
 * `idempotencyKey` é nossa (T5): repetir a mesma chave não pode gerar segunda mensagem.
 */
final readonly class ChannelMessage
{
    /**
     * @param  array<string, string>  $parameters
     * @param  list<string>  $sensitive  nomes em `$parameters` que nunca podem ser registrados
     */
    public function __construct(
        public string $toE164,
        public DeliveryPurpose $purpose,
        public string $template,
        public array $parameters,
        public string $text,
        public string $correlationId,
        public string $idempotencyKey,
        public array $sensitive = [],
    ) {}

    /**
     * Parâmetros sem os segredos — o único formato que pode ir para log ou metadado.
     *
     * @return array<string, string>
     */
    public function safeParameters(): array
    {
        $safe = $this->parameters;

        foreach ($this->sensitive as $name) {
            if (array_key_exists($name, $safe)) {
                $safe[$name] = '[omitido]';
            }
        }

        return $safe;
    }
}
