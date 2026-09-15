/**
 * Protocolo `assinavelox:*` do lado do WIDGET (a página dentro do iframe) — Fase 3 §3.9,
 * docs/fase-3/widget-embutido.md §6. O lado do site hospedeiro é o `embed.ts`, que não importa
 * nada (arquivo único); os dois precisam concordar em tipo, versão e forma.
 *
 * Regras:
 * - envio SEMPRE com `targetOrigin` exato (a origem da sessão) — nunca "*";
 * - recebimento só de `window.parent` E da origem da sessão, com tipo, versão E id da sessão
 *   conferidos (mensagem sem `session` é ignorada);
 * - nenhuma mensagem leva dado pessoal. A ÚNICA que leva um segredo é `assinavelox:token`
 *   (site → widget): o token de uso único, entregue uma vez, só em resposta ao
 *   `assinavelox:boot` do próprio iframe e só para a origem exata do widget — assim ele nunca
 *   fica no atributo `src` do iframe, no DOM do site.
 */

export const PROTOCOL_VERSION = 1;

export type WidgetEventType =
    | 'assinavelox:boot'
    | 'assinavelox:ready'
    | 'assinavelox:completed'
    | 'assinavelox:refused'
    | 'assinavelox:error'
    | 'assinavelox:resize';

export type HostEventType = 'assinavelox:ping' | 'assinavelox:token';

export interface ProtocolMessage<T extends string = string> {
    type: T;
    v: number;
    session: string;
    payload: Record<string, string | number | boolean | null>;
}

export function createMessenger(parentOrigin: string, session: string) {
    const framed = typeof window !== 'undefined' && window.parent !== window;

    return {
        framed,
        post(
            type: WidgetEventType,
            payload: ProtocolMessage['payload'] = {},
        ): void {
            if (!framed) {
                return;
            }

            const message: ProtocolMessage<WidgetEventType> = {
                type,
                v: PROTOCOL_VERSION,
                session,
                payload,
            };

            window.parent.postMessage(message, parentOrigin);
        },
    };
}

/**
 * A mensagem veio do site hospedeiro da sessão, pela janela-mãe, no formato do protocolo?
 */
export function isHostMessage(
    event: MessageEvent,
    parentOrigin: string,
    session: string,
    type: HostEventType = 'assinavelox:ping',
): event is MessageEvent<ProtocolMessage<HostEventType>> {
    if (event.origin !== parentOrigin || event.source !== window.parent) {
        return false;
    }

    const data = event.data as Partial<ProtocolMessage> | null;

    // O id da sessão é OBRIGATÓRIO: com dois widgets da mesma origem no site, uma mensagem sem
    // `session` não pode ser respondida por todos (docs/fase-3/widget-embutido.md §6).
    return (
        typeof data === 'object' &&
        data !== null &&
        data.type === type &&
        data.v === PROTOCOL_VERSION &&
        typeof data.session === 'string' &&
        data.session === session
    );
}

/**
 * Pede ao site o token de uso único (`assinavelox:boot`) e espera a resposta
 * (`assinavelox:token`), só da janela-mãe, da origem da sessão e com o id desta sessão.
 * Resolve com '' se nada válido chegar no prazo.
 */
export function requestHostToken(
    messenger: ReturnType<typeof createMessenger>,
    parentOrigin: string,
    session: string,
    timeoutMs = 10000,
): Promise<string> {
    return new Promise((resolve) => {
        if (!messenger.framed) {
            resolve('');

            return;
        }

        let settled = false;
        let timer = 0;

        const onMessage = (event: MessageEvent) => {
            if (
                !isHostMessage(
                    event,
                    parentOrigin,
                    session,
                    'assinavelox:token',
                )
            ) {
                return;
            }

            const token = (event.data as ProtocolMessage).payload?.token;

            finish(
                typeof token === 'string' &&
                    token.length > 0 &&
                    token.length <= 128
                    ? token
                    : '',
            );
        };

        const finish = (value: string) => {
            if (settled) {
                return;
            }

            settled = true;
            window.clearTimeout(timer);
            window.removeEventListener('message', onMessage);
            resolve(value);
        };

        window.addEventListener('message', onMessage);
        timer = window.setTimeout(() => finish(''), timeoutMs);
        messenger.post('assinavelox:boot', {});
    });
}

/** O widget está enquadrado por um ancestral da origem esperada (quando o navegador diz)? */
export function ancestorMatches(parentOrigin: string): boolean {
    const ancestors = (
        window.location as Location & { ancestorOrigins?: DOMStringList }
    ).ancestorOrigins;

    if (ancestors === undefined || ancestors.length === 0) {
        // Firefox não expõe `ancestorOrigins`; a CSP `frame-ancestors` já garante a origem.
        return true;
    }

    return ancestors[0] === parentOrigin;
}
