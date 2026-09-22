<?php

namespace App\Integrations\Contracts;

use App\Integrations\Dto\IdentityVerificationImage;

/**
 * Verificação do participante por um PROVEDOR EXTERNO: ele recebe a foto tirada na hora e as
 * fotos do documento, compara, e devolve um resultado. Fase 4 §4.1
 * (docs/fase-4/verificacao-facial.md); adaptador real em docs/integracoes/verifiky.md.
 *
 * O que este contrato garante, em qualquer adaptador:
 *
 *  - Quem afirma é o provedor. A plataforma envia imagens e guarda a resposta; ela própria
 *    não compara rostos nem lê documento, e a interface só repete o que o provedor disse.
 *  - Erro, tempo esgotado ou resposta que não se entende NUNCA viram aprovação (T5): viram
 *    `inconclusive`, com um `reason_code` que diz por quê.
 *  - O provedor nunca recebe o conteúdo do documento assinado — só as imagens da captura.
 *  - Os `details` devolvidos já vêm limpos: sem imagem, sem dados lidos do documento, sem
 *    segredo. É o que vai para o banco, para a trilha e para a página de evidências.
 *
 * `status`: `pending` (o provedor ainda está analisando; o resultado chega por webhook ou por
 * {@see self::result()}), `approved`, `rejected`, `inconclusive`, `expired`.
 */
interface IdentityVerificationProvider
{
    /**
     * Envia as imagens e devolve o que o provedor respondeu na hora — que pode já ser o
     * resultado final ou só o protocolo de uma análise em andamento. `reference` é o
     * identificador NOSSO da tentativa: o provedor o devolve no webhook.
     *
     * @param  array{reference?: string, document_type?: string, images?: array<string, mixed>}  $options  `images` traz `selfie`, `document_front` e (opcional) `document_back`, cada uma uma IdentityVerificationImage; o adaptador confere, porque um chamador errado não pode virar aprovação
     * @return array{verification_id: string|null, provider: string, status: 'pending'|'approved'|'rejected'|'inconclusive'|'expired', checked_at: string, details: array<string, mixed>}
     */
    public function start(string $recipientUlid, array $options = [], ?string $correlationId = null): array;

    /**
     * Consulta o resultado de uma verificação já enviada (quando o webhook não chegou).
     *
     * @return array{verification_id: string|null, provider: string, status: 'pending'|'approved'|'rejected'|'inconclusive'|'expired', checked_at: string, details: array<string, mixed>}
     */
    public function result(string $verificationId, ?string $correlationId = null): array;

    public function isConfigured(): bool;

    /** Nome técnico, gravado em `identity_verifications.provider`. */
    public function name(): string;

    /** Nome que aparece para as pessoas ("Verifiky", "Simulador"). */
    public function label(): string;

    /** Simulador identificado? A interface e a evidência rotulam "(simulado)". */
    public function isSimulated(): bool;
}
