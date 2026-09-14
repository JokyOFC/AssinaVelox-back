<?php

namespace App\Integrations\LocalSigner\Contracts;

use App\Enums\LocalSignerComponent;
use App\Integrations\LocalSigner\Dto\LocalSignerCertificate;
use App\Integrations\LocalSigner\Dto\LocalSignerSignature;
use App\Integrations\LocalSigner\Dto\LocalSignerStatus;
use App\Integrations\LocalSigner\Exceptions\LocalSignerUnavailable;
use App\Integrations\LocalSigner\FakeLocalSigner;
use App\Integrations\LocalSigner\NexuLocalSigner;

/**
 * Ponte com o componente que fala com o token/cartão do participante (Fase 3 §3.4;
 * docs/integracoes/a3-componente-local.md §7).
 *
 * É o MESMO contrato que o front implementa em TypeScript (`detect`, `getSigningCertificate`,
 * `signDigest`): no uso real, quem conversa com o componente é o NAVEGADOR, em
 * `127.0.0.1` da máquina do participante — o servidor não alcança esse endereço e nunca
 * recebe a chave. O lado PHP existe para:
 *
 * - o simulador ({@see FakeLocalSigner}), que faz o papel do
 *   componente no servidor, só em teste/local, para exercitar o fluxo inteiro;
 * - descrever e trancar os componentes reais ({@see NexuLocalSigner}):
 *   protocolo, limites de navegador e o que falta para ligar em produção.
 *
 * O servidor só aceita a assinatura de um componente cujo `detect()` diz `available`.
 * `producesTokenSignatures()` é a única porta para o rótulo A3: o simulador responde `false`
 * sempre (A3 simulado nunca aparece como token real).
 */
interface LocalSignerBridge
{
    public function component(): LocalSignerComponent;

    /** O componente foi usado de verdade aqui (disponível, versão mínima, habilitado)? */
    public function detect(): LocalSignerStatus;

    /**
     * Certificado escolhido pelo titular + cadeia, e o identificador opaco da chave no
     * componente (`keyHandle`). Nenhum material de chave.
     *
     * @throws LocalSignerUnavailable
     */
    public function signingCertificate(): LocalSignerCertificate;

    /**
     * Assina um digest JÁ calculado (sem novo hash), como `POST /v1/sign` do NexU e
     * `signHash` do Lacuna Web PKI. `$digest` em binário.
     *
     * @throws LocalSignerUnavailable
     */
    public function signDigest(string $keyHandle, string $digest, string $hashFunction): LocalSignerSignature;

    /** Tudo o que sai daqui é simulado (nenhum token foi usado)? */
    public function isSimulated(): bool;

    /** Uma assinatura deste componente pode ser de token/cartão (A3)? Simulador: nunca. */
    public function producesTokenSignatures(): bool;
}
