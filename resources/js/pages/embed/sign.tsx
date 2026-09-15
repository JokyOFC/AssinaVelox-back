import { Head } from '@inertiajs/react';
import { REGEXP_ONLY_DIGITS } from 'input-otp';
import {
    AlertTriangle,
    Check,
    CheckCircle2,
    EyeOff,
    Lock,
    PenLine,
    ShieldCheck,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import type { MouseEvent as ReactMouseEvent, ReactNode } from 'react';
import { DocumentSwitcher } from '@/components/pdf/document-switcher';
import type { PdfDocumentStatus } from '@/components/pdf/use-pdf-document';
import { ConsentBox, defaultConsentLabel } from '@/components/sign/consent-box';
import { FieldChecklist } from '@/components/sign/field-checklist';
import {
    PrivacyNotice,
    type PrivacyNoticeContent,
    defaultPrivacyNotice,
} from '@/components/sign/privacy-notice';
import { SignerDocument } from '@/components/sign/signer-document';
import type {
    OtherField,
    SignerField,
} from '@/components/sign/signer-field-layer';
import { TERMINAL_ICONS, TerminalCard } from '@/components/sign/terminal-card';
import { InitialsCapture } from '@/components/signature/initials-capture';
import {
    SignatureCapture,
    type SignatureValue,
} from '@/components/signature/signature-capture';
import {
    SIGNATURE_PAYLOAD_MAX_BYTES,
    dataUrlBytes,
} from '@/components/signature/signature-image';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/components/ui/input-otp';
import { Spinner } from '@/components/ui/spinner';
import {
    PROTOCOL_VERSION,
    ancestorMatches,
    createMessenger,
    isHostMessage,
    requestHostToken,
} from '@/embed/widget-protocol';
import { isValidCpf } from '@/lib/format';
import type { SignShowProps } from '@/pages/sign/show';
import type { SignatureKind, SignerAuth } from '@/types';

/*
 * Widget de assinatura embutida (Fase 3 §3.9, docs/fase-3/widget-embutido.md §3).
 *
 * Roda DENTRO do iframe do site do cliente. Não usa cookie: o token de uso único chega pelo
 * `embed.js` por mensagem (`assinavelox:boot` → `assinavelox:token`, §6 — assim ele nunca fica
 * no `src` do iframe, no DOM do site) ou, com a URL posta direto no iframe, pelo fragmento
 * (`#t=`), apagado do endereço antes de qualquer requisição. É trocado uma vez pelo token de
 * execução e mantido só em memória (nunca em storage). Tudo o que a pessoa faz —
 * código, PIN, documento, aceite, recusa — passa pelas MESMAS regras da página pública, com as
 * mesmas props (`SignerPageProps`); a diferença é só o transporte (JSON + cabeçalho).
 *
 * Anti-clickjacking (§5): além do `frame-ancestors` exato, o widget não opera com a página oculta
 * (`document.visibilityState`) nem com o iframe menor que o mínimo, e o aceite final exige uma
 * confirmação visual cujo botão só habilita depois de um intervalo e com visibilidade REAL
 * medida pelo IntersectionObserver v2 (`isVisible`), ou, onde ele não existe, pela heurística
 * documentada: botão 100% dentro da área visível, janela com foco e clique confiável.
 */

/**
 * O token de uso único sai do endereço no instante em que este módulo é avaliado — ANTES de o
 * Inertia inicializar o roteador, que copiaria `location.hash` para a URL da página e para o
 * `history.state`. Fica só nesta variável de módulo até a troca (`takeBootToken`), que a esvazia.
 */
let bootToken: string | null = null;

if (typeof window !== 'undefined' && window.location.hash.startsWith('#t=')) {
    bootToken = decodeURIComponent(window.location.hash.slice(3));
    window.history.replaceState(
        window.history.state,
        '',
        window.location.pathname + window.location.search,
    );
}

function takeBootToken(): string {
    const value = bootToken ?? '';
    bootToken = null;

    return value;
}

interface EmbedEndpoints {
    exchange: string;
    state: string;
    otp_send: string;
    otp_verify: string;
    pin: string;
    complete: string;
    refuse: string;
}

interface EmbedSignProps {
    invalid: boolean;
    session_id: string | null;
    parent_origin: string | null;
    protocol_version: number;
    endpoints: EmbedEndpoints | null;
    frame: { min_width: number; min_height: number };
    confirm_delay_ms: number;
    legal: { terms_url: string; privacy_url: string };
}

type EmbedScreen =
    | SignShowProps['screen']
    | 'unavailable'
    | 'unsupported'
    | 'revoked';

type EmbedState = Partial<Omit<SignShowProps, 'screen'>> & {
    screen: EmbedScreen;
    embed?: {
        session_id: string;
        status: string;
        outcome: string | null;
        expires_at: string | null;
    };
};

interface ApiResult {
    ok: boolean;
    status: number;
    data: Record<string, unknown>;
}

interface Failure {
    code: string;
    message: string;
}

type Notice = { tone: 'info' | 'success' | 'error'; text: string } | null;

const DONE_SCREENS: EmbedScreen[] = [
    'completed',
    'finalizing',
    'already_signed_pending_others',
];

const CLOSED_COPY: Record<string, { title: string; body: string }> = {
    expired: {
        title: 'Prazo encerrado',
        body: 'O prazo para responder a este documento terminou. Fale com quem enviou.',
    },
    canceled: {
        title: 'Solicitação encerrada',
        body: 'Esta solicitação de assinatura foi encerrada por quem enviou o documento.',
    },
    unavailable: {
        title: 'Documento indisponível',
        body: 'Este documento não está disponível para você agora. Se for a sua vez, você recebe o convite por e-mail.',
    },
    unsupported: {
        title: 'Abra pelo link do e-mail',
        body: 'Quem enviou pediu uma etapa extra que este site não oferece. Use o link que você recebeu por e-mail para continuar.',
    },
    revoked: {
        title: 'Acesso encerrado',
        body: 'Este acesso ao documento foi encerrado. Peça um novo acesso no site em que você está.',
    },
};

const METHOD_ALIAS: Record<SignatureKind, string> = {
    drawn: 'draw',
    typed: 'type',
    uploaded: 'upload',
};

function toBase64(dataUrl: string): string {
    const comma = dataUrl.indexOf(',');

    return comma >= 0 ? dataUrl.slice(comma + 1) : dataUrl;
}

function messageOf(data: Record<string, unknown>, fallback: string): string {
    const errors = data.errors;

    if (typeof errors === 'object' && errors !== null) {
        const first = Object.values(errors as Record<string, unknown>)[0];

        if (Array.isArray(first) && typeof first[0] === 'string') {
            return first[0];
        }
    }

    return typeof data.message === 'string' && data.message !== ''
        ? data.message
        : fallback;
}

function signerAuthOf(value: unknown): SignerAuth | null {
    if (typeof value !== 'object' || value === null) {
        return null;
    }

    const candidate = value as Partial<SignerAuth>;

    return typeof candidate.method === 'string' &&
        typeof candidate.channel === 'string'
        ? (candidate as SignerAuth)
        : null;
}

/** A aba está visível? (`document.visibilityState`) */
function usePageVisible(): boolean {
    const [visible, setVisible] = useState(
        () => document.visibilityState === 'visible',
    );

    useEffect(() => {
        const update = () => setVisible(document.visibilityState === 'visible');

        document.addEventListener('visibilitychange', update);

        return () => document.removeEventListener('visibilitychange', update);
    }, []);

    return visible;
}

function useFrameSize(): { width: number; height: number } {
    const [size, setSize] = useState(() => ({
        width: window.innerWidth,
        height: window.innerHeight,
    }));

    useEffect(() => {
        const update = () =>
            setSize({ width: window.innerWidth, height: window.innerHeight });

        window.addEventListener('resize', update);

        return () => window.removeEventListener('resize', update);
    }, []);

    return size;
}

/**
 * Visibilidade REAL do elemento. Com o IntersectionObserver v2 (`trackVisibility`), o navegador
 * diz se o elemento está sem nada por cima, sem opacidade e sem transformação — o que um site
 * que tenta enganar o clique precisaria fazer. Sem o v2, a heurística: 100% dentro da área
 * visível do iframe (o foco e o clique confiável são conferidos no clique).
 */
interface VerifiedVisibility {
    visible: boolean;
    method: 'io_v2' | 'heuristic';
    /**
     * Quanto do elemento ficou fora da tela do SITE (px), por cima e por baixo. Com o iframe
     * mais alto que a janela, o cartão de confirmação usa isto para subir ou descer até a parte
     * que a pessoa está vendo — sem nunca afrouxar a exigência de visibilidade.
     */
    clipTop: number;
    clipBottom: number;
    ratio: number;
}

/**
 * No clique, o que vale é a medida MAIS RECENTE — não o último estado do React: `recheck()` puxa
 * os registros ainda não entregues do observador (`takeRecords()`) e devolve se o elemento está
 * visível e desde quando (o instante do primeiro registro visível depois do último invisível).
 */
interface VisibilityCheck {
    visible: boolean;
    since: number | null;
}

function useVerifiedVisibility(element: HTMLElement | null): {
    visibility: VerifiedVisibility;
    recheck: () => VisibilityCheck;
} {
    const [state, setState] = useState<VerifiedVisibility>({
        visible: false,
        method: 'heuristic',
        clipTop: 0,
        clipBottom: 0,
        ratio: 0,
    });
    const observerRef = useRef<IntersectionObserver | null>(null);
    const applyRef = useRef<
        ((entry: IntersectionObserverEntry) => void) | null
    >(null);
    const visibleSinceRef = useRef<number | null>(null);

    const recheck = useCallback((): VisibilityCheck => {
        const observer = observerRef.current;

        if (observer !== null && applyRef.current !== null) {
            for (const entry of observer.takeRecords()) {
                applyRef.current(entry);
            }
        }

        return {
            visible: visibleSinceRef.current !== null,
            since: visibleSinceRef.current,
        };
    }, []);

    useEffect(() => {
        if (element === null) {
            return;
        }

        if (typeof IntersectionObserver === 'undefined') {
            visibleSinceRef.current = Date.now();
            setState({
                visible: true,
                method: 'heuristic',
                clipTop: 0,
                clipBottom: 0,
                ratio: 1,
            });

            return;
        }

        const options = {
            threshold: [0, 0.5, 0.99, 1],
            trackVisibility: true,
            delay: 100,
        } as IntersectionObserverInit;

        const apply = (entry: IntersectionObserverEntry) => {
            const v2 = 'isVisible' in entry;
            const inside = entry.intersectionRatio >= 0.99;
            const visible = v2
                ? inside &&
                  Boolean(
                      (
                          entry as IntersectionObserverEntry & {
                              isVisible?: boolean;
                          }
                      ).isVisible,
                  )
                : inside;

            // Desde quando está visível SEM interrupção: qualquer registro invisível zera.
            if (!visible) {
                visibleSinceRef.current = null;
            } else if (visibleSinceRef.current === null) {
                visibleSinceRef.current = Date.now();
            }

            // Fora da tela por completo (razão 0) não dá para saber para onde ir: fica onde está.
            const partial = entry.intersectionRatio > 0;

            setState({
                visible,
                method: v2 ? 'io_v2' : 'heuristic',
                clipTop: partial
                    ? Math.max(
                          0,
                          entry.intersectionRect.top -
                              entry.boundingClientRect.top,
                      )
                    : 0,
                clipBottom: partial
                    ? Math.max(
                          0,
                          entry.boundingClientRect.bottom -
                              entry.intersectionRect.bottom,
                      )
                    : 0,
                ratio: entry.intersectionRatio,
            });
        };

        applyRef.current = apply;

        const observer = new IntersectionObserver((entries) => {
            for (const entry of entries) {
                apply(entry);
            }
        }, options);

        observerRef.current = observer;
        observer.observe(element);

        return () => {
            observer.disconnect();
            observerRef.current = null;
            applyRef.current = null;
            visibleSinceRef.current = null;
        };
    }, [element]);

    return { visibility: state, recheck };
}

/**
 * Baixa o PDF com o token no cabeçalho e entrega ao PDF.js como `blob:`.
 *
 * `delivered` segue a regra da página pública (`fetchPdfBytes`): a ENTREGA é a resposta 200 do
 * servidor — o instante em que ele marca `document_presented_at` —, não o desenho nem a leitura
 * completa do corpo. Se o corpo não chegar inteiro, a pessoa pode tentar de novo (`retry`).
 */
function useAuthorizedPdf(url: string | null, token: string | null) {
    const [blobUrl, setBlobUrl] = useState<string | null>(null);
    const [failed, setFailed] = useState(false);
    const [delivered, setDelivered] = useState(false);
    const [attempt, setAttempt] = useState(0);
    const retry = useCallback(() => setAttempt((value) => value + 1), []);

    useEffect(() => {
        if (url === null || token === null) {
            setBlobUrl(null);

            return;
        }

        const controller = new AbortController();
        let created: string | null = null;

        setBlobUrl(null);
        setFailed(false);
        setDelivered(false);

        fetch(url, {
            headers: {
                Authorization: `Bearer ${token}`,
                Accept: 'application/pdf',
            },
            credentials: 'omit',
            cache: 'no-store',
            signal: controller.signal,
        })
            .then(async (response) => {
                if (!response.ok) {
                    throw new Error(String(response.status));
                }

                setDelivered(true);

                const blob = await response.blob();
                created = URL.createObjectURL(
                    new Blob([blob], { type: 'application/pdf' }),
                );
                setBlobUrl(created);
            })
            .catch(() => {
                if (!controller.signal.aborted) {
                    setFailed(true);
                }
            });

        return () => {
            controller.abort();

            if (created !== null) {
                URL.revokeObjectURL(created);
            }
        };
    }, [url, token, attempt]);

    return { blobUrl, failed, delivered, retry };
}

export default function EmbedSign(props: EmbedSignProps) {
    if (
        props.invalid ||
        props.session_id === null ||
        props.parent_origin === null ||
        props.endpoints === null
    ) {
        return (
            <Shell>
                <Head title="Documento indisponível" />
                <TerminalCard
                    icon={TERMINAL_ICONS.invalid}
                    title="Este acesso não existe ou não está disponível"
                >
                    Abra o documento pelo site em que ele foi disponibilizado ou
                    pelo link que você recebeu por e-mail.
                </TerminalCard>
            </Shell>
        );
    }

    return (
        <Widget
            sessionId={props.session_id}
            parentOrigin={props.parent_origin}
            endpoints={props.endpoints}
            frame={props.frame}
            confirmDelayMs={props.confirm_delay_ms}
            legal={props.legal}
        />
    );
}

function Shell({ children }: { children: ReactNode }) {
    return (
        <div className="bg-background text-foreground min-h-screen p-3 sm:p-4">
            <div className="mx-auto flex w-full max-w-[1100px] flex-col gap-3">
                {children}
                <p className="text-muted-foreground flex items-center justify-center gap-1.5 text-[11.5px]">
                    <Lock className="size-3" />
                    Ambiente seguro da AssinaVelox
                </p>
            </div>
        </div>
    );
}

function Widget({
    sessionId,
    parentOrigin,
    endpoints,
    frame,
    confirmDelayMs,
    legal,
}: {
    sessionId: string;
    parentOrigin: string;
    endpoints: EmbedEndpoints;
    frame: EmbedSignProps['frame'];
    confirmDelayMs: number;
    legal: EmbedSignProps['legal'];
}) {
    const messenger = useMemo(
        () => createMessenger(parentOrigin, sessionId),
        [parentOrigin, sessionId],
    );
    const tokenRef = useRef<string | null>(null);
    const bootedRef = useRef(false);
    const [token, setToken] = useState<string | null>(null);
    const [state, setState] = useState<EmbedState | null>(null);
    const [failure, setFailure] = useState<Failure | null>(null);
    const [notice, setNotice] = useState<Notice>(null);
    const pageVisible = usePageVisible();
    const size = useFrameSize();
    const tooSmall =
        size.width < frame.min_width || size.height < frame.min_height;
    const announcedRef = useRef<string | null>(null);
    const smallReportedRef = useRef(false);

    const fail = useCallback(
        (code: string, message: string) => {
            setFailure({ code, message });
            messenger.post('assinavelox:error', { code, message, fatal: true });
        },
        [messenger],
    );

    const request = useCallback(
        async (
            url: string,
            method: 'GET' | 'POST',
            body?: Record<string, unknown>,
        ): Promise<ApiResult> => {
            const headers: Record<string, string> = {
                Accept: 'application/json',
            };

            if (body !== undefined) {
                headers['Content-Type'] = 'application/json';
            }

            if (tokenRef.current !== null) {
                headers.Authorization = `Bearer ${tokenRef.current}`;
            }

            const init: RequestInit = {
                method,
                headers,
                credentials: 'omit',
                cache: 'no-store',
            };

            if (body !== undefined) {
                init.body = JSON.stringify(body);
            }

            let response: Response;

            try {
                response = await fetch(url, init);
            } catch {
                return {
                    ok: false,
                    status: 0,
                    data: {
                        code: 'network',
                        message:
                            'Não foi possível falar com a AssinaVelox. Verifique sua conexão e tente de novo.',
                    },
                };
            }

            let data: Record<string, unknown> = {};

            try {
                data = (await response.json()) as Record<string, unknown>;
            } catch {
                data = {};
            }

            return { ok: response.ok, status: response.status, data };
        },
        [],
    );

    /** Erro da sessão (401/410): o widget para e avisa o site. */
    const handleSessionError = useCallback(
        (result: ApiResult): boolean => {
            const code =
                typeof result.data.code === 'string' ? result.data.code : '';

            if (
                result.status === 401 ||
                result.status === 410 ||
                [
                    'session_expired',
                    'session_revoked',
                    'origin_removed',
                    'unauthenticated',
                ].includes(code)
            ) {
                fail(
                    code || 'session_expired',
                    messageOf(
                        result.data,
                        'Sua sessão terminou. Peça um novo acesso no site em que você está.',
                    ),
                );

                return true;
            }

            return false;
        },
        [fail],
    );

    const refresh = useCallback(async () => {
        const result = await request(endpoints.state, 'GET');

        if (!result.ok) {
            if (!handleSessionError(result)) {
                fail(
                    'unavailable',
                    messageOf(
                        result.data,
                        'Não foi possível carregar o documento.',
                    ),
                );
            }

            return;
        }

        setState(result.data.state as EmbedState);
    }, [endpoints.state, request, handleSessionError, fail]);

    // Início: confere o enquadramento, tira o token do endereço e faz a troca (uma única vez).
    useEffect(() => {
        if (bootedRef.current) {
            return;
        }

        bootedRef.current = true;

        // O token já saiu do endereço na avaliação do módulo (ver `bootToken`); aqui ele é tirado
        // da memória do módulo e passa a existir só nesta função até a troca.
        const raw = takeBootToken();

        if (!messenger.framed || !ancestorMatches(parentOrigin)) {
            setFailure({
                code: 'not_framed',
                message:
                    'Abra este documento pelo site em que ele foi disponibilizado.',
            });

            return;
        }

        void (async () => {
            // Com o `embed.js`, o token NÃO vem no endereço (o `src` do iframe fica no DOM do
            // site): o widget pede por mensagem e o site o entrega uma vez (§6). Quem põe a URL
            // completa direto no iframe continua funcionando pelo fragmento.
            const token =
                raw !== ''
                    ? raw
                    : await requestHostToken(
                          messenger,
                          parentOrigin,
                          sessionId,
                      );

            if (token === '') {
                fail(
                    'invalid_link',
                    'Este link de assinatura não é válido. Abra o documento de novo pelo site.',
                );

                return;
            }

            const result = await request(endpoints.exchange, 'POST', {
                token,
            });

            if (!result.ok || typeof result.data.token !== 'string') {
                const code =
                    typeof result.data.code === 'string'
                        ? result.data.code
                        : 'invalid_link';

                fail(
                    code,
                    messageOf(
                        result.data,
                        'Este link de assinatura não é válido.',
                    ),
                );

                return;
            }

            tokenRef.current = result.data.token;
            setToken(result.data.token);
            await refresh();
        })();
    }, [
        endpoints.exchange,
        fail,
        messenger,
        parentOrigin,
        refresh,
        request,
        sessionId,
    ]);

    // Mensagens para o site conforme a tela muda (só estado — nada pessoal).
    useEffect(() => {
        if (state === null) {
            return;
        }

        const screen = state.screen;

        if (announcedRef.current === null) {
            messenger.post('assinavelox:ready', { screen });
        }

        if (announcedRef.current !== screen) {
            if (DONE_SCREENS.includes(screen)) {
                messenger.post('assinavelox:completed', { status: screen });
            } else if (screen === 'refused') {
                messenger.post('assinavelox:refused', {});
            } else if (screen in CLOSED_COPY) {
                messenger.post('assinavelox:error', {
                    code: screen,
                    message: CLOSED_COPY[screen].body,
                    fatal: true,
                });
            }
        }

        announcedRef.current = screen;
    }, [state, messenger]);

    // O site pode pedir o estado de novo (`assinavelox:ping`), só pela janela-mãe e da origem certa.
    useEffect(() => {
        const onMessage = (event: MessageEvent) => {
            if (!isHostMessage(event, parentOrigin, sessionId)) {
                return;
            }

            if (failure !== null) {
                messenger.post('assinavelox:error', {
                    code: failure.code,
                    message: failure.message,
                    fatal: true,
                });

                return;
            }

            if (state !== null) {
                messenger.post('assinavelox:ready', { screen: state.screen });
            }
        };

        window.addEventListener('message', onMessage);

        return () => window.removeEventListener('message', onMessage);
    }, [parentOrigin, sessionId, messenger, state, failure]);

    // Altura do conteúdo para o site ajustar o iframe.
    useEffect(() => {
        if (!messenger.framed || typeof ResizeObserver === 'undefined') {
            return;
        }

        let frameId = 0;
        let last = 0;
        const observer = new ResizeObserver(() => {
            cancelAnimationFrame(frameId);
            frameId = requestAnimationFrame(() => {
                const height = document.documentElement.scrollHeight;

                if (Math.abs(height - last) > 4) {
                    last = height;
                    messenger.post('assinavelox:resize', { height });
                }
            });
        });

        observer.observe(document.body);

        return () => {
            cancelAnimationFrame(frameId);
            observer.disconnect();
        };
    }, [messenger]);

    useEffect(() => {
        if (tooSmall && !smallReportedRef.current && state !== null) {
            smallReportedRef.current = true;
            messenger.post('assinavelox:error', {
                code: 'frame_too_small',
                message: `O iframe precisa ter pelo menos ${frame.min_width}×${frame.min_height} px.`,
                fatal: false,
            });
        }

        if (!tooSmall) {
            smallReportedRef.current = false;
        }
    }, [tooSmall, state, messenger, frame.min_width, frame.min_height]);

    const blocked = !pageVisible || tooSmall;

    const apply = useCallback(
        async (
            url: string,
            body: Record<string, unknown> | undefined,
            fallback: string,
        ): Promise<boolean> => {
            setNotice(null);
            const result = await request(url, 'POST', body ?? {});

            if (!result.ok) {
                if (handleSessionError(result)) {
                    return false;
                }

                setNotice({
                    tone: 'error',
                    text: messageOf(result.data, fallback),
                });

                if (
                    result.status === 409 &&
                    result.data.code === 'not_signable'
                ) {
                    await refresh();
                }

                return false;
            }

            if (
                typeof result.data.state === 'object' &&
                result.data.state !== null
            ) {
                setState(result.data.state as EmbedState);
            }

            if (typeof result.data.message === 'string') {
                setNotice({ tone: 'success', text: result.data.message });
            }

            return true;
        },
        [request, handleSessionError, refresh],
    );

    let content: ReactNode;

    if (failure !== null) {
        content = (
            <TerminalCard
                icon={TERMINAL_ICONS.invalid}
                title="Não foi possível abrir o documento"
            >
                {failure.message}
            </TerminalCard>
        );
    } else if (state === null) {
        content = (
            <div className="border-border bg-card shadow-card flex items-center justify-center gap-2 rounded-[14px] border p-8 text-[13.5px]">
                <Spinner className="size-4" />
                Abrindo o documento com segurança…
            </div>
        );
    } else if (state.screen === 'identify') {
        content = (
            <IdentifyPanel
                state={state}
                endpoints={endpoints}
                apply={apply}
                disabled={blocked}
                legal={legal}
            />
        );
    } else if (state.screen === 'sign') {
        content = (
            <SignPanel
                key={state.authorization?.token ?? 'sign'}
                state={state}
                token={token}
                endpoints={endpoints}
                apply={apply}
                disabled={blocked}
                confirmDelayMs={confirmDelayMs}
                legal={legal}
            />
        );
    } else if (DONE_SCREENS.includes(state.screen)) {
        content = <DonePanel state={state} />;
    } else if (state.screen === 'refused') {
        content = (
            <TerminalCard
                icon={TERMINAL_ICONS.refused}
                tone="danger"
                title="Recusa registrada"
            >
                Sua recusa foi registrada e quem enviou o documento será
                avisado.
            </TerminalCard>
        );
    } else {
        const copy = CLOSED_COPY[state.screen] ?? CLOSED_COPY.unavailable;

        content = (
            <TerminalCard
                icon={
                    state.screen === 'expired'
                        ? TERMINAL_ICONS.expired
                        : TERMINAL_ICONS.canceled
                }
                title={copy.title}
            >
                {copy.body}
            </TerminalCard>
        );
    }

    return (
        <Shell>
            <Head
                title={
                    state?.envelope?.title
                        ? `Assinar: ${state.envelope.title}`
                        : 'Assinatura de documento'
                }
            />
            {state?.envelope && failure === null && (
                <header className="border-border bg-card shadow-card flex flex-wrap items-center justify-between gap-2 rounded-[14px] border px-4 py-3">
                    <div className="min-w-0">
                        <p className="truncate text-[14px] font-semibold">
                            {state.envelope.title}
                        </p>
                        <p className="text-muted-foreground tabular text-[12px]">
                            {state.envelope.display_code} · enviado por{' '}
                            {state.sender?.organization_name}
                        </p>
                    </div>
                    <Badge variant="outline">
                        <ShieldCheck className="size-3" />
                        AssinaVelox
                    </Badge>
                </header>
            )}
            {!pageVisible && (
                <Banner icon={<EyeOff className="size-4" />}>
                    A página está oculta. Volte para esta aba para continuar.
                </Banner>
            )}
            {tooSmall && (
                <Banner icon={<AlertTriangle className="size-4" />}>
                    A área do documento está pequena demais para assinar com
                    segurança. Aumente a janela ou peça ao site para exibir o
                    documento maior.
                </Banner>
            )}
            {notice !== null && (
                <p
                    role={notice.tone === 'error' ? 'alert' : 'status'}
                    className={
                        notice.tone === 'error'
                            ? 'text-danger text-[13px]'
                            : 'text-text-secondary text-[13px]'
                    }
                >
                    {notice.text}
                </p>
            )}
            {content}
            <p className="sr-only">Protocolo {PROTOCOL_VERSION}</p>
        </Shell>
    );
}

function Banner({ icon, children }: { icon: ReactNode; children: ReactNode }) {
    return (
        <div
            role="alert"
            className="border-warning/40 bg-warning/10 text-foreground flex items-start gap-2 rounded-[10px] border p-3 text-[13px] leading-[1.5]"
        >
            {icon}
            <span>{children}</span>
        </div>
    );
}

type Apply = (
    url: string,
    body: Record<string, unknown> | undefined,
    fallback: string,
) => Promise<boolean>;

function IdentifyPanel({
    state,
    endpoints,
    apply,
    disabled,
    legal,
}: {
    state: EmbedState;
    endpoints: EmbedEndpoints;
    apply: Apply;
    disabled: boolean;
    legal: EmbedSignProps['legal'];
}) {
    const auth = signerAuthOf(state.signer_auth);
    const otp = state.otp ?? null;
    const length = state.limits?.otp_length ?? 6;
    const [code, setCode] = useState('');
    const [pin, setPin] = useState('');
    const [busy, setBusy] = useState(false);
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        const timer = window.setInterval(() => setNow(Date.now()), 1000);

        return () => window.clearInterval(timer);
    }, []);

    const channel = auth?.channel_label?.toLowerCase() ?? 'e-mail';
    const destination =
        auth?.destination ?? state.recipient?.email_masked ?? '';
    const sent = otp?.sent_at !== null && otp?.sent_at !== undefined;
    const live =
        sent &&
        otp?.expires_at !== null &&
        otp?.expires_at !== undefined &&
        Date.parse(otp.expires_at) > now;
    const resendAt = otp?.resend_available_at
        ? Date.parse(otp.resend_available_at)
        : 0;
    const resendIn = Math.max(0, Math.ceil((resendAt - now) / 1000));
    const pinStep = auth?.step === 'pin';
    const unavailable = auth !== null && auth.available === false;

    const run = async (
        url: string,
        body: Record<string, unknown> | undefined,
        fallback: string,
    ) => {
        setBusy(true);
        const ok = await apply(url, body, fallback);
        setBusy(false);

        return ok;
    };

    const notice: PrivacyNoticeContent = state.privacy
        ? { summary: state.privacy.summary, body: state.privacy.notice }
        : defaultPrivacyNotice(state.sender?.organization_name ?? '');

    return (
        <div className="border-border bg-card shadow-card mx-auto flex w-full max-w-[460px] flex-col gap-4 rounded-[14px] border p-5">
            <div>
                <h1 className="text-[19px] leading-[1.25] font-bold tracking-[-.01em]">
                    {pinStep
                        ? 'Informe o PIN'
                        : 'Confirme o código para abrir o documento'}
                </h1>
                <p className="text-text-secondary mt-1.5 text-[13.5px] leading-[1.55]">
                    {pinStep
                        ? 'Quem enviou o documento combinou um PIN com você. Digite-o para continuar.'
                        : `Olá, ${state.recipient?.first_name ?? ''}. Para ver e responder ao documento, enviamos um código de ${length} dígitos por ${channel} para ${destination}.`}
                </p>
            </div>

            {unavailable && (
                <p role="alert" className="text-danger text-[13px]">
                    {auth?.unavailable_reason ??
                        'O envio do código está indisponível no momento.'}
                </p>
            )}

            {pinStep ? (
                <form
                    className="flex flex-col gap-3"
                    onSubmit={(event) => {
                        event.preventDefault();

                        if (pin.trim() === '' || busy || disabled) {
                            return;
                        }

                        void run(
                            endpoints.pin,
                            { pin: pin.trim() },
                            'PIN não conferido.',
                        ).then(() => setPin(''));
                    }}
                >
                    <label
                        className="text-[12.5px] font-semibold"
                        htmlFor="embed-pin"
                    >
                        PIN
                    </label>
                    <input
                        id="embed-pin"
                        type="password"
                        inputMode="numeric"
                        autoComplete="off"
                        maxLength={auth?.pin?.max_length ?? 12}
                        value={pin}
                        onChange={(event) => setPin(event.target.value)}
                        className="border-input h-11 rounded-lg border bg-white px-3 text-[15px]"
                        disabled={disabled || busy}
                    />
                    <Button
                        type="submit"
                        size="lg"
                        disabled={disabled || busy || pin.trim() === ''}
                    >
                        {busy && <Spinner className="size-4" />}
                        Confirmar PIN
                    </Button>
                </form>
            ) : !live ? (
                <Button
                    type="button"
                    size="lg"
                    disabled={disabled || busy || unavailable}
                    onClick={() =>
                        void run(
                            endpoints.otp_send,
                            {},
                            'Não foi possível enviar o código.',
                        )
                    }
                >
                    {busy && <Spinner className="size-4" />}
                    {`Receber código por ${channel}`}
                </Button>
            ) : (
                <div className="flex flex-col gap-3">
                    <InputOTP
                        maxLength={length}
                        value={code}
                        inputMode="numeric"
                        pattern={REGEXP_ONLY_DIGITS}
                        autoFocus
                        disabled={disabled || busy}
                        aria-label={`Código de ${length} dígitos enviado por ${channel}`}
                        onChange={(value) => {
                            setCode(value);

                            if (value.length === length) {
                                void run(
                                    endpoints.otp_verify,
                                    { code: value },
                                    'Código inválido.',
                                ).then(() => setCode(''));
                            }
                        }}
                        containerClassName="w-full"
                    >
                        <InputOTPGroup className="flex w-full gap-2">
                            {Array.from({ length }, (_, index) => (
                                <InputOTPSlot
                                    key={index}
                                    index={index}
                                    className="border-input tabular h-12 flex-1 rounded-lg border bg-white text-[20px] font-bold first:rounded-l-lg last:rounded-r-lg"
                                />
                            ))}
                        </InputOTPGroup>
                    </InputOTP>
                    <div className="text-muted-foreground flex flex-wrap items-center justify-between gap-2 text-[12px]">
                        <span>
                            {otp?.attempts_left !== undefined
                                ? `${otp.attempts_left} ${otp.attempts_left === 1 ? 'tentativa restante' : 'tentativas restantes'}`
                                : ''}
                        </span>
                        <button
                            type="button"
                            className="text-primary font-semibold disabled:opacity-60"
                            disabled={disabled || busy || resendIn > 0}
                            onClick={() =>
                                void run(
                                    endpoints.otp_send,
                                    {},
                                    'Não foi possível reenviar o código.',
                                )
                            }
                        >
                            {resendIn > 0
                                ? `Reenviar em ${resendIn}s`
                                : 'Reenviar código'}
                        </button>
                    </div>
                </div>
            )}

            <PrivacyNotice notice={notice} privacyUrl={legal.privacy_url} />
        </div>
    );
}

type PlacedField = SignerField & { documentId: string | null };
type PlacedOther = OtherField & { documentId: string | null };

function SignPanel({
    state,
    token,
    endpoints,
    apply,
    disabled,
    confirmDelayMs,
    legal,
}: {
    state: EmbedState;
    token: string | null;
    endpoints: EmbedEndpoints;
    apply: Apply;
    disabled: boolean;
    confirmDelayMs: number;
    legal: EmbedSignProps['legal'];
}) {
    const envelope = state.envelope ?? null;
    const recipient = state.recipient ?? null;
    const action = state.action ?? null;
    const docs = useMemo(() => state.documents ?? [], [state.documents]);
    const multi = docs.length > 1;
    const firstDocId = docs[0]?.id ?? null;
    const approving = action?.requires_signature === false;
    const verb = approving ? 'aprovar' : 'assinar';

    const [page, setPage] = useState(1);
    const [docId, setDocId] = useState<string | null>(firstDocId);
    const [signature, setSignature] = useState<SignatureValue | null>(null);
    const [initials, setInitials] = useState<SignatureValue | null>(null);
    const [values, setValues] = useState<Record<string, string | boolean>>({});
    const [accepted, setAccepted] = useState(false);
    const [activeFieldId, setActiveFieldId] = useState<string | null>(null);
    const [documentStatus, setDocumentStatus] =
        useState<PdfDocumentStatus>('idle');
    const [documentDelivered, setDocumentDelivered] = useState(false);
    const [delivered, setDelivered] = useState<Record<string, boolean>>(() =>
        Object.fromEntries(docs.map((item) => [item.id, item.presented])),
    );
    const [confirming, setConfirming] = useState(false);
    const [confirmAnchor, setConfirmAnchor] = useState<number | null>(null);
    const [refusing, setRefusing] = useState(false);
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);
    const [localError, setLocalError] = useState<string | null>(null);
    const fieldRefs = useRef<Record<string, HTMLElement | null>>({});

    const currentDoc =
        docs.find((item) => item.id === docId) ?? docs[0] ?? null;
    const currentDocId = currentDoc?.id ?? null;
    const pageCount = currentDoc?.pages ?? envelope?.pages ?? 1;
    const pdfUrl = currentDoc?.pdf_url ?? state.document?.pdf_url ?? null;
    const {
        blobUrl,
        failed,
        delivered: pdfDelivered,
        retry: retryPdf,
    } = useAuthorizedPdf(pdfUrl, token);

    // Vários documentos: a entrega de cada arquivo conta quando o servidor respondeu 200.
    useEffect(() => {
        if (pdfDelivered && multi && currentDocId) {
            setDelivered((current) =>
                current[currentDocId]
                    ? current
                    : { ...current, [currentDocId]: true },
            );
        }
    }, [pdfDelivered, multi, currentDocId]);

    const myFields: PlacedField[] = useMemo(() => {
        const pagesIn = (id: string | null): number =>
            docs.find((item) => item.id === id)?.pages ?? envelope?.pages ?? 1;

        return (state.my_fields ?? []).flatMap((field) => {
            const documentId = field.document_id ?? firstDocId;
            const pages =
                field.page === 'all'
                    ? Array.from(
                          { length: pagesIn(documentId) },
                          (_, i) => i + 1,
                      )
                    : [Number(field.page)];

            return pages.map((number) => ({
                id: field.id,
                type: field.type,
                page: number,
                x: field.x,
                y: field.y,
                w: field.w,
                h: field.h,
                required: field.required,
                label: field.label,
                placeholder: field.placeholder,
                prefill: field.prefill,
                documentId,
            }));
        });
    }, [state.my_fields, docs, envelope?.pages, firstDocId]);

    const otherFields: PlacedOther[] = useMemo(
        () =>
            (state.other_fields ?? []).map((field, index) => ({
                key: `${field.recipient_name}-${index}`,
                recipient_name: field.recipient_name,
                role: field.role,
                type: field.type,
                page: field.page,
                x: field.x,
                y: field.y,
                w: field.w,
                h: field.h,
                signed: field.signed,
                hint: null,
                documentId: field.document_id ?? firstDocId,
            })),
        [state.other_fields, firstDocId],
    );

    const uniqueFields = useMemo(() => {
        const seen = new Map<string, PlacedField>();

        myFields.forEach((field) => {
            if (!seen.has(field.id)) {
                seen.set(field.id, field);
            }
        });

        return [...seen.values()];
    }, [myFields]);

    const needsSignature =
        !approving && uniqueFields.some((f) => f.type === 'signature');
    const needsInitials =
        !approving && uniqueFields.some((f) => f.type === 'initials');
    const inputFields = uniqueFields.filter(
        (field) =>
            field.type !== 'signature' &&
            field.type !== 'initials' &&
            field.type !== 'stamp',
    );

    const isFilled = (field: SignerField): boolean => {
        if (field.type === 'stamp') {
            return true;
        }

        if (field.type === 'cpf') {
            const value = values[field.id];

            return typeof value === 'string' && isValidCpf(value);
        }

        if (field.type === 'signature') {
            return signature !== null;
        }

        if (field.type === 'initials') {
            return initials !== null;
        }

        if (field.type === 'checkbox') {
            return values[field.id] === true;
        }

        const value = values[field.id];

        if (typeof value === 'string' && value.trim() !== '') {
            return true;
        }

        return (field.prefill ?? '').trim() !== '';
    };

    const pending = uniqueFields.filter(
        (field) => field.required && !isFilled(field),
    );
    const cpfInvalid = uniqueFields.some((field) => {
        const value = values[field.id];

        return (
            field.type === 'cpf' &&
            typeof value === 'string' &&
            value.trim() !== '' &&
            !isValidCpf(value)
        );
    });
    const missingDocs = multi ? docs.filter((item) => !delivered[item.id]) : [];
    const documentPresented = multi
        ? missingDocs.length === 0
        : pdfDelivered || documentDelivered || documentStatus === 'ready';

    const canSubmit =
        !disabled &&
        accepted &&
        documentPresented &&
        pending.length === 0 &&
        !cpfInvalid &&
        (!needsSignature || signature !== null) &&
        (!needsInitials || initials !== null) &&
        !busy;

    const setValue = (fieldId: string, value: string | boolean) =>
        setValues((current) => ({ ...current, [fieldId]: value }));

    const activateField = (field: SignerField) => {
        if (field.type === 'checkbox') {
            setValue(field.id, values[field.id] !== true);
        }

        setActiveFieldId(field.id);
        fieldRefs.current[field.id]?.scrollIntoView({
            behavior: 'smooth',
            block: 'center',
        });
    };

    const onDocumentStatus = (status: PdfDocumentStatus, arrived: boolean) => {
        setDocumentStatus(status);
        setDocumentDelivered(arrived);

        if (multi && currentDocId && (arrived || status === 'ready')) {
            setDelivered((current) =>
                current[currentDocId]
                    ? current
                    : { ...current, [currentDocId]: true },
            );
        }
    };

    const openConfirmation = (event: ReactMouseEvent<HTMLButtonElement>) => {
        if (!canSubmit) {
            return;
        }

        // A confirmação aparece onde a pessoa acabou de clicar: num iframe alto, o centro do
        // iframe pode estar fora da tela do site — e o botão só habilita quando está visível.
        setConfirmAnchor(event.clientY);

        const oversize = [signature, initials].some(
            (image) =>
                image !== null &&
                dataUrlBytes(image.image_base64) > SIGNATURE_PAYLOAD_MAX_BYTES,
        );

        if (oversize && !approving) {
            setLocalError(
                'A imagem da assinatura é grande demais. Desenhe de novo ou use uma imagem menor.',
            );

            return;
        }

        setLocalError(null);
        setConfirming(true);
    };

    const submit = async (interaction: {
        method: string;
        delay_ms: number;
    }) => {
        setBusy(true);

        const encode = (image: SignatureValue) => ({
            method: METHOD_ALIAS[image.kind],
            kind: image.kind,
            image_base64: toBase64(image.image_base64),
            text: image.text,
            font: image.font,
        });

        const base: Record<string, unknown> = {
            authorization: state.authorization?.token ?? '',
            fields: values,
            consent: true,
            interaction: { confirmed: true, visible: true, ...interaction },
        };

        const ok = await apply(
            endpoints.complete,
            approving
                ? base
                : {
                      ...base,
                      signature: signature
                          ? encode(signature)
                          : { method: 'draw', kind: 'drawn' },
                      initials: initials ? encode(initials) : null,
                  },
            'Não foi possível registrar o aceite.',
        );

        setBusy(false);

        if (!ok) {
            setConfirming(false);
        }
    };

    const refuse = async () => {
        setBusy(true);
        await apply(
            endpoints.refuse,
            { reason: reason.trim() },
            'Não foi possível registrar a recusa.',
        );
        setBusy(false);
    };

    if (envelope === null || recipient === null) {
        return null;
    }

    const visibleFields = multi
        ? myFields.filter((field) => field.documentId === currentDocId)
        : myFields;
    const visibleOthers = multi
        ? otherFields.filter((field) => field.documentId === currentDocId)
        : otherFields;
    const minReason = state.limits?.refusal_reason?.min ?? 10;
    const maxReason = state.limits?.refusal_reason?.max ?? 500;
    const notice: PrivacyNoticeContent = state.privacy
        ? { summary: state.privacy.summary, body: state.privacy.notice }
        : defaultPrivacyNotice(state.sender?.organization_name ?? '');

    return (
        <div className="flex flex-col gap-3 lg:flex-row lg:items-start">
            <div className="min-w-0 lg:flex-[1.5_1_380px]">
                {multi && (
                    <DocumentSwitcher
                        className="mb-3"
                        label="Arquivos para assinar"
                        items={docs.map((item) => ({
                            id: item.id,
                            position: item.position,
                            name:
                                item.name?.trim() || `Arquivo ${item.position}`,
                            meta: delivered[item.id]
                                ? 'Aberto'
                                : 'Ainda não aberto',
                            tone: delivered[item.id] ? 'done' : 'attention',
                        }))}
                        current={currentDocId}
                        onSelect={(id) => {
                            setDocId(id);
                            setPage(1);
                            setDocumentStatus('idle');
                        }}
                    />
                )}
                {blobUrl !== null ? (
                    <SignerDocument
                        key={currentDocId ?? 'single'}
                        pdfUrl={blobUrl}
                        title={currentDoc?.name?.trim() || envelope.title}
                        pages={pageCount}
                        displayCode={envelope.display_code}
                        fields={visibleFields}
                        others={visibleOthers}
                        values={values}
                        signatureImage={
                            approving ? null : (signature?.image_base64 ?? null)
                        }
                        initialsImage={
                            approving ? null : (initials?.image_base64 ?? null)
                        }
                        activeFieldId={activeFieldId}
                        onActivateField={activateField}
                        page={page}
                        onPageChange={setPage}
                        onStatusChange={onDocumentStatus}
                    />
                ) : (
                    <div className="border-border bg-card shadow-card flex items-center justify-center gap-2 rounded-xl border p-8 text-[13px]">
                        {failed ? (
                            <span className="flex flex-col items-center gap-2 text-center">
                                Não foi possível exibir o documento aqui.
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={retryPdf}
                                >
                                    Tentar de novo
                                </Button>
                            </span>
                        ) : (
                            <>
                                <Spinner className="size-4" />
                                Carregando o documento…
                            </>
                        )}
                    </div>
                )}
            </div>

            <aside className="flex w-full min-w-0 flex-col gap-3 lg:max-w-[420px] lg:flex-[1_1_320px]">
                <div className="border-border bg-card shadow-card flex flex-col gap-4 rounded-[14px] border p-5">
                    <div>
                        <Badge variant="success">
                            <Check className="size-3 stroke-[3]" />
                            Código confirmado
                        </Badge>
                        <h1 className="mt-3 text-[19px] leading-[1.25] font-bold tracking-[-.01em]">
                            {approving ? 'Sua aprovação' : 'Sua assinatura'}
                        </h1>
                        <p className="text-text-secondary mt-1.5 text-[13.5px] leading-[1.55]">
                            Revise o documento, preencha o que for pedido e
                            confirme. O que vale é o seu aceite eletrônico,
                            registrado com as evidências.
                        </p>
                    </div>

                    {needsSignature && (
                        <SignatureCapture
                            value={signature}
                            onChange={setSignature}
                            defaultText={recipient.name}
                            options={
                                state.signature_options ?? {
                                    draw: true,
                                    type: true,
                                    upload: true,
                                    fonts: [],
                                }
                            }
                        />
                    )}

                    {needsInitials && (
                        <div className="border-border border-t pt-4">
                            <p className="text-text-secondary mb-2 text-[12.5px] font-semibold">
                                Rubrica
                            </p>
                            <InitialsCapture
                                value={initials}
                                onChange={setInitials}
                                name={recipient.name}
                                options={
                                    state.signature_options ?? {
                                        draw: true,
                                        type: true,
                                        upload: true,
                                        fonts: [],
                                    }
                                }
                            />
                        </div>
                    )}

                    {inputFields.length > 0 && (
                        <FieldChecklist
                            fields={inputFields}
                            values={values}
                            onChange={setValue}
                            activeId={activeFieldId}
                            registerRef={(id, element) => {
                                fieldRefs.current[id] = element;
                            }}
                            maxTextLength={state.limits?.max_text_length}
                            className="border-border border-t pt-4"
                        />
                    )}

                    <PrivacyNotice
                        notice={notice}
                        privacyUrl={legal.privacy_url}
                    />

                    {state.consent?.completion_notice && (
                        <p className="border-border bg-sidebar text-text-secondary rounded-[10px] border p-3 text-[12.5px] leading-[1.5]">
                            {state.consent.completion_notice}
                        </p>
                    )}

                    <ConsentBox
                        checked={accepted}
                        onCheckedChange={setAccepted}
                        label={
                            state.consent?.checkbox_label ??
                            defaultConsentLabel(envelope.title)
                        }
                        statement={
                            state.consent?.statement ?? state.consent_text ?? ''
                        }
                        version={state.consent?.version}
                        termsUrl={legal.terms_url}
                        privacyUrl={legal.privacy_url}
                        disabled={disabled}
                    />

                    {!documentPresented && (
                        <p className="text-warning text-[12.5px]">
                            {multi
                                ? `Abra todos os arquivos antes de ${verb}.`
                                : documentStatus === 'error' || failed
                                  ? 'O documento não chegou. Recarregue o site e tente de novo.'
                                  : 'Aguarde o documento carregar.'}
                        </p>
                    )}
                    {documentPresented && pending.length > 0 && (
                        <p className="text-warning text-[12.5px]">
                            {pending.length === 1
                                ? 'Falta 1 campo obrigatório.'
                                : `Faltam ${pending.length} campos obrigatórios.`}
                        </p>
                    )}
                    {cpfInvalid && (
                        <p className="text-warning text-[12.5px]">
                            Confira o CPF informado.
                        </p>
                    )}
                    {localError && (
                        <p role="alert" className="text-danger text-[12.5px]">
                            {localError}
                        </p>
                    )}

                    <Button
                        type="button"
                        size="xl"
                        disabled={!canSubmit}
                        onClick={openConfirmation}
                    >
                        {approving ? (
                            <CheckCircle2 className="size-4" />
                        ) : (
                            <PenLine className="size-4" />
                        )}
                        {action?.button_label ?? 'Assinar documento'}
                    </Button>

                    {!refusing ? (
                        <button
                            type="button"
                            onClick={() => setRefusing(true)}
                            disabled={disabled}
                            className="text-muted-foreground hover:text-danger text-center text-[12.5px] font-semibold"
                        >
                            {approving
                                ? 'Recusar aprovação'
                                : 'Recusar assinatura'}
                        </button>
                    ) : (
                        <div className="border-border flex flex-col gap-2 border-t pt-3">
                            <label
                                htmlFor="embed-refusal"
                                className="text-[12.5px] font-semibold"
                            >
                                Motivo da recusa
                            </label>
                            <textarea
                                id="embed-refusal"
                                value={reason}
                                maxLength={maxReason}
                                onChange={(event) =>
                                    setReason(event.target.value)
                                }
                                className="border-input min-h-[80px] rounded-lg border bg-white p-2 text-[13.5px]"
                            />
                            <div className="flex gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setRefusing(false)}
                                    disabled={busy}
                                >
                                    Voltar
                                </Button>
                                <Button
                                    type="button"
                                    variant="destructive"
                                    disabled={
                                        disabled ||
                                        busy ||
                                        reason.trim().length < minReason
                                    }
                                    onClick={() => void refuse()}
                                >
                                    Confirmar recusa
                                </Button>
                            </div>
                        </div>
                    )}
                </div>
            </aside>

            {confirming && (
                <ConfirmOverlay
                    title={envelope.title}
                    approving={approving}
                    delayMs={confirmDelayMs}
                    busy={busy}
                    blocked={disabled}
                    onCancel={() => setConfirming(false)}
                    onConfirm={(interaction) => void submit(interaction)}
                    anchorY={confirmAnchor}
                />
            )}
        </div>
    );
}

/**
 * Confirmação visual do aceite (§5). Sem animação, transformação nem opacidade no caminho do
 * botão: o IntersectionObserver v2 considera "não visível" qualquer um desses efeitos — que é
 * justamente o que um site hospedeiro mal-intencionado usaria para esconder o widget.
 *
 * O cartão aparece na altura do clique (`anchorY`): num iframe alto, o centro dele pode estar
 * fora da tela do site, e o botão só habilita quando está inteiro e visível.
 */
function ConfirmOverlay({
    title,
    approving,
    delayMs,
    busy,
    blocked,
    onCancel,
    onConfirm,
    anchorY,
}: {
    title: string;
    approving: boolean;
    delayMs: number;
    busy: boolean;
    blocked: boolean;
    onCancel: () => void;
    onConfirm: (interaction: { method: string; delay_ms: number }) => void;
    anchorY: number | null;
}) {
    const cardTop =
        anchorY === null
            ? null
            : Math.min(
                  Math.max(anchorY - 140, 12),
                  Math.max(12, window.innerHeight - 280),
              );
    // Observa o CARTÃO inteiro (título, texto e botões), não o botão: o botão desabilitado tem
    // opacidade reduzida, e o IntersectionObserver v2 trata opacidade < 1 como "não visível".
    const [card, setCard] = useState<HTMLDivElement | null>(null);
    const { visibility, recheck } = useVerifiedVisibility(card);

    // Sobe (ou desce) o cartão até a parte do iframe que está na tela do site. Só MOVE o cartão:
    // a exigência de visibilidade real continua a mesma.
    const [shift, setShift] = useState(0);
    const adjustments = useRef(0);

    useEffect(() => {
        if (visibility.visible || adjustments.current >= 8) {
            return;
        }

        if (visibility.clipBottom > 1) {
            adjustments.current += 1;
            setShift((current) => current - visibility.clipBottom - 8);
        } else if (visibility.clipTop > 1) {
            adjustments.current += 1;
            setShift((current) => current + visibility.clipTop + 8);
        }
    }, [visibility]);
    const [visibleSince, setVisibleSince] = useState<number | null>(null);
    const [now, setNow] = useState(() => Date.now());
    const [focused, setFocused] = useState(() => document.hasFocus());

    useEffect(() => {
        setVisibleSince(visibility.visible ? Date.now() : null);
    }, [visibility.visible]);

    useEffect(() => {
        const timer = window.setInterval(() => {
            setNow(Date.now());
            setFocused(document.hasFocus());
        }, 150);

        return () => window.clearInterval(timer);
    }, []);

    const elapsed = visibleSince === null ? 0 : now - visibleSince;
    const ready =
        visibility.visible &&
        focused &&
        document.visibilityState === 'visible' &&
        elapsed >= delayMs &&
        !blocked &&
        !busy;

    const confirm = (event: ReactMouseEvent<HTMLButtonElement>) => {
        // Clique sintético (script) não vale; e tudo é conferido de novo no instante do clique —
        // inclusive a visibilidade: `recheck()` puxa os registros do observador que o React ainda
        // não viu, e uma sobreposição posta pouco antes do clique zera o intervalo.
        const current = recheck();
        const heldFor = current.since === null ? 0 : Date.now() - current.since;

        if (
            !event.isTrusted ||
            !ready ||
            !current.visible ||
            heldFor < delayMs ||
            !document.hasFocus() ||
            document.visibilityState !== 'visible'
        ) {
            return;
        }

        onConfirm({ method: visibility.method, delay_ms: Math.round(heldFor) });
    };

    return (
        <div
            role="dialog"
            aria-modal="true"
            aria-labelledby="embed-confirm-title"
            className={
                cardTop === null
                    ? 'fixed inset-0 z-50 flex items-center justify-center p-4'
                    : 'fixed inset-0 z-50 p-4'
            }
        >
            <div
                className="absolute inset-0 bg-[rgba(11,31,66,0.45)]"
                aria-hidden="true"
            />
            <div
                ref={setCard}
                className="border-border bg-card relative mx-auto w-full max-w-[420px] rounded-[14px] border p-5 shadow-[0_12px_40px_rgba(11,31,66,.25)]"
                style={
                    cardTop === null
                        ? undefined
                        : { top: Math.max(0, cardTop + shift) }
                }
            >
                <h2 id="embed-confirm-title" className="text-[17px] font-bold">
                    {approving ? 'Confirmar aprovação' : 'Confirmar assinatura'}
                </h2>
                <p className="text-text-secondary mt-2 text-[13.5px] leading-[1.55]">
                    {approving
                        ? `Você está registrando a sua aprovação de “${title}”.`
                        : `Você está registrando o seu aceite eletrônico em “${title}”.`}{' '}
                    Confira se esta janela é da AssinaVelox e confirme.
                </p>
                <div className="mt-4 flex flex-col gap-2 sm:flex-row-reverse">
                    <Button
                        data-embed-confirm="true"
                        type="button"
                        size="lg"
                        disabled={!ready}
                        onClick={confirm}
                    >
                        {busy ? (
                            <Spinner className="size-4" />
                        ) : (
                            <PenLine className="size-4" />
                        )}
                        {approving
                            ? 'Confirmar e aprovar'
                            : 'Confirmar e assinar'}
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        size="lg"
                        onClick={onCancel}
                        disabled={busy}
                    >
                        Voltar
                    </Button>
                </div>
                {!ready && !busy && (
                    <p
                        className="text-muted-foreground mt-3 text-[12px]"
                        aria-live="polite"
                    >
                        {!visibility.visible ||
                        document.visibilityState !== 'visible'
                            ? 'O botão libera quando esta janela estiver inteira e visível na tela.'
                            : !focused
                              ? 'Clique nesta janela para continuar.'
                              : 'Aguarde um instante…'}
                    </p>
                )}
            </div>
        </div>
    );
}

function DonePanel({ state }: { state: EmbedState }) {
    const receipt = state.receipt ?? null;
    const finalizing = state.screen === 'finalizing';

    return (
        <div className="border-border bg-card shadow-card mx-auto flex w-full max-w-[520px] flex-col items-center gap-3 rounded-[14px] border p-6 text-center">
            <span className="bg-success/10 text-success flex size-12 items-center justify-center rounded-xl">
                <CheckCircle2 className="size-6" />
            </span>
            <h1 className="text-[19px] font-bold">
                {state.screen === 'completed'
                    ? 'Documento concluído'
                    : 'Aceite registrado'}
            </h1>
            <p className="text-text-secondary text-[13.5px] leading-[1.55]">
                {receipt?.completion_notice ??
                    (finalizing
                        ? 'Todos responderam. O arquivo final está sendo preparado e chega por e-mail.'
                        : 'Você receberá a cópia final por e-mail quando todos concluírem.')}
            </p>
            {receipt?.verification_code && (
                <p className="text-muted-foreground tabular text-[12.5px]">
                    Código de verificação: <b>{receipt.verification_code}</b>
                </p>
            )}
        </div>
    );
}
