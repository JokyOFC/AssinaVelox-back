/*
 * AssinaVelox — embed.js v1 (Fase 3 §3.9, docs/fase-3/widget-embutido.md §9).
 *
 * Script do SITE HOSPEDEIRO. Sem dependências e SEM `import`: o build do Vite gera um arquivo
 * único, servido em /embed/v1/embed.js pela rota `embed.script`.
 *
 *   AssinaVelox.mount({
 *       url,        // `data.url` devolvido pela API v1 (uso único, token no fragmento)
 *       container,  // elemento ou seletor CSS
 *       onReady, onCompleted, onRefused, onError,
 *   });
 *
 * Segurança das mensagens (nos dois lados): `postMessage` sempre com `targetOrigin` exato
 * (nunca "*"); quem recebe confere `event.origin`, `event.source` e o formato (tipo, versão e
 * id da sessão). Nenhuma mensagem carrega dado pessoal ou token — só estado e ids públicos.
 */

type EmbedEventType =
    | 'assinavelox:boot'
    | 'assinavelox:ready'
    | 'assinavelox:completed'
    | 'assinavelox:refused'
    | 'assinavelox:error'
    | 'assinavelox:resize';

interface EmbedMessage {
    type: EmbedEventType;
    v: number;
    session: string;
    payload: Record<string, unknown>;
}

interface EmbedEventDetail {
    session: string;
    [key: string]: unknown;
}

interface EmbedErrorDetail extends EmbedEventDetail {
    code: string;
    message: string;
    fatal: boolean;
}

interface MountOptions {
    url: string;
    container: Element | string;
    onReady?: (detail: EmbedEventDetail) => void;
    onCompleted?: (detail: EmbedEventDetail) => void;
    onRefused?: (detail: EmbedEventDetail) => void;
    onError?: (detail: EmbedErrorDetail) => void;
    /** Ajusta a altura do iframe ao conteúdo (padrão: true). */
    autoResize?: boolean;
    /** Altura inicial e mínima do iframe, em px (padrão: 640). */
    minHeight?: number;
    /** Espera máxima por `assinavelox:ready`, em ms (padrão: 20000). */
    readyTimeout?: number;
    /** Título acessível do iframe. */
    title?: string;
}

interface MountedWidget {
    readonly iframe: HTMLIFrameElement;
    readonly session: string;
    /** Pede ao widget que reenvie o estado atual (`assinavelox:ready`). */
    ping(): void;
    /** Remove o iframe e para de ouvir mensagens. */
    destroy(): void;
}

interface AssinaVeloxGlobal {
    readonly version: string;
    mount(options: MountOptions): MountedWidget;
}

(function install(root: Window & { AssinaVelox?: AssinaVeloxGlobal }) {
    const PROTOCOL_VERSION = 1;
    const PREFIX = 'assinavelox:';
    const SESSION_PATH = /\/embed\/v1\/sessoes\/([0-9A-Za-z]{26})\/?$/;
    const ERROR_MESSAGES: Record<string, string> = {
        timeout:
            'O widget de assinatura não respondeu. Confira se esta origem está cadastrada em "API e integrações → Widget de assinatura".',
        invalid_link: 'O link de assinatura não é válido.',
        link_used:
            'Este link de assinatura já foi usado. Crie uma nova sessão pela API.',
        link_expired:
            'Este link de assinatura venceu. Crie uma nova sessão pela API.',
        link_revoked: 'Esta sessão de assinatura foi revogada.',
    };

    if (root.AssinaVelox !== undefined) {
        return;
    }

    function fail(message: string): never {
        throw new Error(`AssinaVelox.mount: ${message}`);
    }

    function resolveContainer(container: Element | string): Element {
        if (typeof container === 'string') {
            const found = document.querySelector(container);

            if (found === null) {
                fail(`nenhum elemento corresponde a "${container}".`);
            }

            return found;
        }

        if (typeof Element !== 'undefined' && container instanceof Element) {
            return container;
        }

        return fail('`container` precisa ser um elemento ou um seletor CSS.');
    }

    function parseUrl(raw: string): { url: URL; session: string } {
        if (typeof raw !== 'string' || raw === '') {
            fail('`url` é obrigatória (use `data.url` da API).');
        }

        let url: URL;

        try {
            url = new URL(raw);
        } catch {
            return fail('`url` não é um endereço válido.');
        }

        const loopback = ['localhost', '127.0.0.1', '[::1]'].includes(
            url.hostname,
        );

        if (
            url.protocol !== 'https:' &&
            !(loopback && url.protocol === 'http:')
        ) {
            fail('`url` precisa ser HTTPS.');
        }

        const match = SESSION_PATH.exec(url.pathname);

        if (match === null) {
            fail('`url` não é uma URL de assinatura embutida da AssinaVelox.');
        }

        if (!url.hash.startsWith('#t=') || url.hash.length < 24) {
            fail(
                '`url` sem o token de uso único (use a URL completa devolvida pela API).',
            );
        }

        return { url, session: match[1] };
    }

    function isMessage(data: unknown, session: string): data is EmbedMessage {
        if (typeof data !== 'object' || data === null) {
            return false;
        }

        const candidate = data as Partial<EmbedMessage>;

        return (
            typeof candidate.type === 'string' &&
            candidate.type.startsWith(PREFIX) &&
            candidate.v === PROTOCOL_VERSION &&
            candidate.session === session &&
            typeof candidate.payload === 'object' &&
            candidate.payload !== null
        );
    }

    function safeCall<T>(
        callback: ((detail: T) => void) | undefined,
        detail: T,
    ) {
        if (typeof callback !== 'function') {
            return;
        }

        try {
            callback(detail);
        } catch (error) {
            // O erro do callback do site não pode derrubar o widget.
            setTimeout(() => {
                throw error;
            });
        }
    }

    function mount(options: MountOptions): MountedWidget {
        if (typeof options !== 'object' || options === null) {
            fail('passe um objeto de opções.');
        }

        const { url, session } = parseUrl(options.url);
        const container = resolveContainer(options.container);
        const origin = url.origin;
        const minHeight = Math.max(420, Number(options.minHeight) || 640);
        const autoResize = options.autoResize !== false;
        const readyTimeout = Math.max(
            1000,
            Number(options.readyTimeout) || 20000,
        );

        const iframe = document.createElement('iframe');
        iframe.title = options.title ?? 'Assinatura de documento — AssinaVelox';
        iframe.referrerPolicy = 'no-referrer';
        // O widget não navega o site hospedeiro nem abre nada fora de uma aba nova.
        iframe.setAttribute(
            'sandbox',
            'allow-scripts allow-same-origin allow-forms allow-popups allow-popups-to-escape-sandbox allow-downloads',
        );
        iframe.setAttribute('allow', 'fullscreen');
        iframe.style.width = '100%';
        iframe.style.minWidth = '320px';
        iframe.style.height = `${minHeight}px`;
        iframe.style.border = '0';
        iframe.style.display = 'block';

        // O token de uso único NÃO vai no `src` (o atributo fica no DOM do site, ao alcance de
        // scripts de terceiros e gravadores de sessão): o iframe carrega a URL sem o fragmento e
        // o token só é entregue por mensagem, uma vez, quando o próprio widget pede
        // (`assinavelox:boot`), para a origem exata do widget.
        let bootToken: string | null = decodeURIComponent(url.hash.slice(3));
        url.hash = '';
        iframe.src = url.toString();

        let ready = false;
        let destroyed = false;

        const timer = window.setTimeout(() => {
            if (!ready && !destroyed) {
                safeCall<EmbedErrorDetail>(options.onError, {
                    session,
                    code: 'timeout',
                    message: ERROR_MESSAGES.timeout,
                    fatal: true,
                });
            }
        }, readyTimeout);

        function onMessage(event: MessageEvent) {
            if (destroyed) {
                return;
            }

            // Origem EXATA do widget e o PRÓPRIO iframe: qualquer outra janela é ignorada,
            // inclusive outro iframe da mesma origem.
            if (
                event.origin !== origin ||
                event.source !== iframe.contentWindow
            ) {
                return;
            }

            if (!isMessage(event.data, session)) {
                return;
            }

            const message = event.data;
            const detail: EmbedEventDetail = { ...message.payload, session };

            switch (message.type) {
                case 'assinavelox:boot':
                    // O widget pede o token de uso único: entregue UMA vez, só ao PRÓPRIO iframe
                    // e só para a origem exata do widget (conferidos acima); depois, esquecido.
                    if (
                        bootToken !== null &&
                        bootToken !== '' &&
                        iframe.contentWindow !== null
                    ) {
                        iframe.contentWindow.postMessage(
                            {
                                type: 'assinavelox:token',
                                v: PROTOCOL_VERSION,
                                session,
                                payload: { token: bootToken },
                            },
                            origin,
                        );
                    }

                    bootToken = null;
                    break;
                case 'assinavelox:ready':
                    ready = true;
                    window.clearTimeout(timer);
                    safeCall(options.onReady, detail);
                    break;
                case 'assinavelox:completed':
                    safeCall(options.onCompleted, detail);
                    break;
                case 'assinavelox:refused':
                    safeCall(options.onRefused, detail);
                    break;
                case 'assinavelox:error': {
                    const code =
                        typeof message.payload.code === 'string'
                            ? message.payload.code
                            : 'unknown';

                    ready = true;
                    window.clearTimeout(timer);
                    safeCall<EmbedErrorDetail>(options.onError, {
                        ...detail,
                        code,
                        message:
                            typeof message.payload.message === 'string'
                                ? message.payload.message
                                : (ERROR_MESSAGES[code] ??
                                  'O widget de assinatura informou um erro.'),
                        fatal: message.payload.fatal === true,
                    });
                    break;
                }
                case 'assinavelox:resize': {
                    const height = Number(message.payload.height);

                    if (autoResize && Number.isFinite(height) && height > 0) {
                        iframe.style.height = `${Math.min(Math.max(Math.ceil(height), minHeight), 4000)}px`;
                    }

                    break;
                }
                default:
                    break;
            }
        }

        window.addEventListener('message', onMessage);
        container.appendChild(iframe);

        return {
            iframe,
            session,
            ping() {
                if (!destroyed && iframe.contentWindow !== null) {
                    iframe.contentWindow.postMessage(
                        {
                            type: 'assinavelox:ping',
                            v: PROTOCOL_VERSION,
                            session,
                            payload: {},
                        },
                        origin,
                    );
                }
            },
            destroy() {
                destroyed = true;
                window.clearTimeout(timer);
                window.removeEventListener('message', onMessage);
                iframe.remove();
            },
        };
    }

    root.AssinaVelox = Object.freeze({
        version: String(PROTOCOL_VERSION),
        mount,
    });
})(window);
