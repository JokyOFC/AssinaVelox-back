import type { LocalComponentProtocol } from '@/types/external-signing';

/**
 * `LocalSignerBridge` do navegador (brief `integracoes/a3-componente-local.md` §7): conversa com
 * o componente instalado na máquina do participante. A chave do token NUNCA passa por aqui —
 * o componente devolve só o certificado (público) e a assinatura do resumo que o servidor
 * preparou. O PIN do token é digitado na janela do próprio componente, nunca nesta página.
 *
 * Classe B (viabilidade §1.2): a única implementação real é a do NexU (API `/v1`, fork 1.25),
 * e ela só é usada quando o servidor diz que o componente está habilitado
 * (`components[].available && production_enabled`) — hoje a produção está DESABILITADA
 * (`NexuLocalSigner::PRODUCTION_ENABLED = false`), então a página nem tenta a rede local. O
 * simulador não é uma ponte do navegador: ele assina no servidor, com o resumo da própria
 * reserva, e é sempre identificado como simulado.
 *
 * Pendências conhecidas para produção (docs/frontend.md, Fase 3): a CSP da página pública só
 * libera `connect-src 'self'` — os endereços do componente precisam entrar nela; a permissão
 * de acesso à rede local (Chrome 142+) e o TLS local autoassinado ainda não foram testados
 * com token real; e o mapeamento dos erros do NexU (PIN errado × cancelado) está NÃO
 * CONFIRMADO no brief.
 */

export type LocalSignerErrorCode =
    /** Nada respondeu no endereço do componente (não instalado, parado, porta errada). */
    | 'component_unreachable'
    /** Respondeu, mas a versão é anterior à mínima exigida. */
    | 'version_too_old'
    /** O componente não encontrou certificado (token ou cartão não inserido). */
    | 'token_not_found'
    /** A operação no token falhou: PIN errado, token removido ou operação cancelada. */
    | 'operation_failed'
    /** O componente recusou a entrada (resumo ou algoritmo não aceito). */
    | 'invalid_request'
    /** A resposta do componente não tem os campos esperados. */
    | 'invalid_response'
    /** O componente demorou demais (a janela do PIN pode ter ficado aberta). */
    | 'timeout';

export class LocalSignerError extends Error {
    constructor(
        public readonly code: LocalSignerErrorCode,
        message: string,
    ) {
        super(message);
        this.name = 'LocalSignerError';
    }
}

export interface LocalSignerDetection {
    available: boolean;
    component: string;
    version: string | null;
    error: LocalSignerError | null;
}

export interface LocalSigningCertificate {
    /** Base64 DER. */
    certificate: string;
    chain: string[];
    keyAlgorithm: string | null;
    /** Opaco; fica só na memória desta página e nunca vai ao servidor. */
    keyHandle: unknown;
    supportedDigests: string[];
}

export interface LocalSignatureResult {
    /** Base64 da assinatura bruta. */
    signature: string;
    signatureAlgorithm: string | null;
    certificate: string | null;
    chain: string[];
}

export interface LocalSignerBridge {
    readonly component: string;
    readonly simulated: boolean;
    detect(): Promise<LocalSignerDetection>;
    getSigningCertificate(): Promise<LocalSigningCertificate>;
    signDigest(
        keyHandle: unknown,
        digestB64: string,
        hashFunction: 'SHA256',
    ): Promise<LocalSignatureResult>;
}

const DETECT_TIMEOUT_MS = 4000;
/** O participante escolhe o certificado e digita o PIN na janela do componente. */
const INTERACTIVE_TIMEOUT_MS = 180000;

type Json = Record<string, unknown>;

function stringOrNull(value: unknown): string | null {
    return typeof value === 'string' && value !== '' ? value : null;
}

function stringList(value: unknown): string[] {
    return Array.isArray(value)
        ? value.filter((item): item is string => typeof item === 'string')
        : [];
}

/** "1.25.3" ≥ "1.25.0"? Partes não numéricas contam como zero. */
export function versionAtLeast(actual: string, minimum: string): boolean {
    const parse = (value: string) =>
        value.split(/[.+-]/).map((part) => Number.parseInt(part, 10) || 0);
    const a = parse(actual);
    const b = parse(minimum);

    for (let index = 0; index < Math.max(a.length, b.length); index++) {
        const left = a[index] ?? 0;
        const right = b[index] ?? 0;

        if (left !== right) {
            return left > right;
        }
    }

    return true;
}

const UNREACHABLE_MESSAGE =
    'Não conseguimos falar com o componente de assinatura neste computador. Confira se ele está instalado e aberto, se você permitiu o acesso à rede local quando o navegador perguntou e se o certificado de segurança local do componente foi aceito.';

/**
 * NexU (fork 1.25) — `GET /v1/status`, `POST /v1/signing-certificate`, `POST /v1/sign`
 * (resumo pronto, sem novo hash). Tenta HTTPS e depois HTTP em `127.0.0.1`.
 */
export function createNexuBridge(
    protocol: LocalComponentProtocol,
): LocalSignerBridge {
    const bases = [protocol.https_base, protocol.http_base].filter(Boolean);
    let working: string | null = null;

    const call = async (
        method: 'GET' | 'POST',
        path: string,
        body: Json | undefined,
        timeoutMs: number,
    ): Promise<Json> => {
        const candidates = working ? [working] : bases;
        let lastNetworkFailure = true;

        for (const base of candidates) {
            const controller = new AbortController();
            const timer = window.setTimeout(
                () => controller.abort(),
                timeoutMs,
            );

            try {
                const response = await fetch(`${base}${path}`, {
                    method,
                    // Sem cookies nem credenciais: o componente não é o servidor da plataforma.
                    credentials: 'omit',
                    cache: 'no-store',
                    mode: 'cors',
                    headers:
                        method === 'POST'
                            ? { 'Content-Type': 'application/json' }
                            : undefined,
                    ...(method === 'POST'
                        ? { body: JSON.stringify(body ?? {}) }
                        : {}),
                    signal: controller.signal,
                });

                working = base;
                lastNetworkFailure = false;

                let parsed: Json = {};

                try {
                    parsed = (await response.json()) as Json;
                } catch {
                    parsed = {};
                }

                if (response.ok) {
                    return parsed;
                }

                if (response.status === 400) {
                    throw new LocalSignerError(
                        'invalid_request',
                        'O componente recusou o pedido (resumo ou algoritmo não aceito).',
                    );
                }

                throw new LocalSignerError(
                    'operation_failed',
                    'O componente não concluiu a operação no token. O PIN pode estar errado, o token ou cartão pode ter sido removido, ou a operação foi cancelada na janela do componente.',
                );
            } catch (exception) {
                if (exception instanceof LocalSignerError) {
                    throw exception;
                }

                if (controller.signal.aborted && working === base) {
                    throw new LocalSignerError(
                        'timeout',
                        'O componente demorou demais para responder. Confira se a janela dele (escolha do certificado ou PIN) ficou aberta atrás do navegador.',
                    );
                }
                // Falha de rede, CORS, permissão de rede local negada ou CSP: tenta o próximo.
            } finally {
                window.clearTimeout(timer);
            }
        }

        throw new LocalSignerError(
            lastNetworkFailure ? 'component_unreachable' : 'invalid_response',
            UNREACHABLE_MESSAGE,
        );
    };

    return {
        component: protocol.component,
        simulated: false,

        async detect() {
            try {
                const status = await call(
                    'GET',
                    protocol.endpoints.status.path,
                    undefined,
                    DETECT_TIMEOUT_MS,
                );
                const version = stringOrNull(status.applicationVersion);

                if (
                    version &&
                    !versionAtLeast(version, protocol.minimum_version)
                ) {
                    return {
                        available: false,
                        component: protocol.component,
                        version,
                        error: new LocalSignerError(
                            'version_too_old',
                            `O componente instalado é a versão ${version}; é preciso a ${protocol.minimum_version} ou mais recente.`,
                        ),
                    };
                }

                return {
                    available: true,
                    component: protocol.component,
                    version,
                    error: null,
                };
            } catch (exception) {
                return {
                    available: false,
                    component: protocol.component,
                    version: null,
                    error:
                        exception instanceof LocalSignerError
                            ? exception
                            : new LocalSignerError(
                                  'component_unreachable',
                                  UNREACHABLE_MESSAGE,
                              ),
                };
            }
        },

        async getSigningCertificate() {
            const response = await call(
                'POST',
                protocol.endpoints.signing_certificate.path,
                { nonRepudiation: true, closeToken: false },
                INTERACTIVE_TIMEOUT_MS,
            );
            const certificate = stringOrNull(response.certificate);

            if (!certificate) {
                throw new LocalSignerError(
                    'token_not_found',
                    'O componente não encontrou um certificado. Insira o token ou cartão, confira se o leitor está conectado e tente de novo.',
                );
            }

            return {
                certificate,
                chain: stringList(response.certificateChain),
                keyAlgorithm: stringOrNull(response.encryptionAlgorithm),
                keyHandle: response.keyHandle ?? null,
                supportedDigests: stringList(response.supportedDigests),
            };
        },

        async signDigest(keyHandle, digestB64, hashFunction) {
            const response = await call(
                'POST',
                protocol.endpoints.sign.path,
                { keyHandle, hash: digestB64, hashFunction },
                INTERACTIVE_TIMEOUT_MS,
            );
            const signature = stringOrNull(response.signature);

            if (!signature) {
                throw new LocalSignerError(
                    'invalid_response',
                    'O componente não devolveu a assinatura. Nada foi enviado à plataforma.',
                );
            }

            return {
                signature,
                signatureAlgorithm: stringOrNull(response.signatureAlgorithm),
                certificate: stringOrNull(response.certificate),
                chain: stringList(response.certificateChain),
            };
        },
    };
}
