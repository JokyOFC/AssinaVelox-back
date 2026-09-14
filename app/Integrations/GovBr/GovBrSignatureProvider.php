<?php

namespace App\Integrations\GovBr;

/**
 * Contrato da API DIRETA de assinatura gov.br (roadmap §3.5) — **classe C: BLOQUEADO**.
 *
 * Só a interface, documentada. NÃO há implementação, fake nem binding no container, de
 * propósito: um adaptador modelaria uma integração que o AssinaVelox não pode ter e daria a
 * impressão de que ela existe (roadmap T4; viabilidade §0, classe C; §3.2, "trilha
 * bloqueada").
 *
 * ## Por que está bloqueado (docs/integracoes/gov-br-assinatura.md §3)
 *
 * A "API de Assinatura Eletrônica gov.br" (OAuth2 no CAS do ITI + `certificadoPublico` +
 * `assinarPKCS7`) existe e é documentada, mas a credencial só é concedida a **órgão ou
 * entidade pública**, para sistema que seja **serviço público**, com integração prévia ao
 * **Login Único** e `redirect_uri` em **domínio oficial de governo** (`gov.br`, `jus.br`…;
 * Portaria SGD/MGI 7.076/2024). O AssinaVelox é SaaS privado, comercial, em domínio próprio e
 * em mercado concorrencial: não atende à finalidade nem ao requisito técnico.
 *
 * ## O que desbloquearia (todos necessários)
 *
 * 1. um **órgão público cliente** que peça a credencial para um serviço público dele;
 * 2. implantação sob o **domínio oficial** desse órgão, com o **Login Único** integrado;
 * 3. **aceite por escrito da SGD** (`integracaoid@gestao.gov.br`) de que um produto de
 *    terceiro nesse arranjo é admitido (NÃO CONFIRMADO no texto oficial).
 *
 * Mesmo desbloqueado, seria um projeto por cliente, não uma funcionalidade do SaaS. O caminho
 * viável hoje é o fluxo de DEVOLUÇÃO (o participante assina no `assinador.iti.br` e envia o
 * PDF): `App\Services\Signing\GovBr\GovBrReturnService` (docs/fase-3/gov-br.md).
 *
 * ## Forma do contrato (para quando/se houver credencial)
 *
 * Fluxo do manual (§5): `authorize` (scope `sign` + `govbr`, `state`, `nonce`) → troca do
 * `code` por token (600 s; `sign` vale para UMA assinatura) → `GET certificadoPublico` →
 * `POST assinarPKCS7 {hashBase64}` (SHA-256 dos byte ranges de uma revisão preparada com
 * placeholder) → CMS/PKCS#7 a embutir. Token, `code` e `state` nunca em log, fila, evento ou
 * exceção (T10). O Content-Type e a estrutura do CMS devolvido são NÃO CONFIRMADOS.
 */
interface GovBrSignatureProvider
{
    /** Classificação da viabilidade: não há implementação permitida. */
    public const AVAILABILITY = 'blocked_class_c';

    /**
     * URL de autorização (OAuth2 authorization code). `state` é de uso único e amarrado ao
     * pedido; `nonce` anti-replay. O manual não menciona PKCE.
     */
    public function authorizationUrl(string $state, string $nonce): string;

    /**
     * Troca o `code` pelo token de acesso. O token é opaco e SECRETO; nunca é persistido.
     */
    public function exchangeCode(#[\SensitiveParameter] string $code, string $state): string;

    /**
     * Certificado público do usuário autenticado (PEM).
     */
    public function publicCertificate(#[\SensitiveParameter] string $accessToken): string;

    /**
     * Assina o SHA-256 (em Base64) e devolve o CMS/PKCS#7 (DER) com o certificado do usuário.
     */
    public function signHash(#[\SensitiveParameter] string $accessToken, string $sha256Base64): string;
}
