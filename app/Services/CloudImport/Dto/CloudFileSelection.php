<?php

namespace App\Services\CloudImport\Dto;

use App\Services\CloudImport\CloudProvider;
use LogicException;

/**
 * O que o navegador escolheu no Google Picker ou no Dropbox Chooser, já validado no formato
 * pelo controller. Os valores são dados NÃO confiáveis (T6): o adaptador confere de novo.
 *
 * O access token do Google, quando existe, vive só aqui e só durante a importação: fica fora
 * de `var_dump`/`print_r` (`__debugInfo`), fora de stack traces (`SensitiveParameter`) e o
 * objeto se recusa a ser serializado — nunca pode ir para fila, cache ou log.
 */
final class CloudFileSelection
{
    public function __construct(
        public readonly CloudProvider $provider,
        public readonly ?string $externalId = null,
        public readonly ?string $name = null,
        public readonly ?string $link = null,
        public readonly ?int $declaredBytes = null,
        #[\SensitiveParameter]
        private readonly ?string $accessToken = null,
    ) {}

    public function accessToken(): ?string
    {
        return $this->accessToken;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'provider' => $this->provider->value,
            'externalId' => $this->externalId,
            'name' => $this->name,
            'declaredBytes' => $this->declaredBytes,
            'accessToken' => $this->accessToken === null ? null : '[redigido]',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('CloudFileSelection não pode ser serializada: pode conter token de acesso.');
    }
}
