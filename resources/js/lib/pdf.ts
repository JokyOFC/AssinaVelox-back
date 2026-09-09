/**
 * Carregamento e renderização de PDF no navegador (PDF.js / `pdfjs-dist`).
 *
 * - O módulo é importado dinamicamente: o `pdfjs-dist` só entra no bundle das
 *   telas que realmente mostram um documento (wizard, detalhe, assinatura).
 * - O worker é resolvido pelo bundler a partir de
 *   `pdfjs-dist/build/pdf.worker.min.mjs` (Vite emite o arquivo no build e a
 *   CSP já permite `worker-src 'self' blob:`).
 * - O PDF nunca é buscado pelo próprio PDF.js: fazemos `fetch` da rota
 *   autorizada com as credenciais da sessão, tratamos o status HTTP em PT-BR e
 *   entregamos os bytes já em memória. Assim um 403/404/409 vira mensagem, e
 *   não um erro genérico de "arquivo inválido".
 */
import type { PDFDocumentProxy, PDFPageProxy } from 'pdfjs-dist';

type PdfjsModule = typeof import('pdfjs-dist');

let modulePromise: Promise<PdfjsModule> | null = null;

/**
 * URL do worker resolvida pelo bundler. O Vite reconhece
 * `new URL(<especificador>, import.meta.url)`, resolve o pacote e emite o
 * arquivo em `public/build/assets` — não há caminho montado à mão nem CDN.
 */
function workerUrl(): string {
    return new URL(
        'pdfjs-dist/build/pdf.worker.min.mjs',
        import.meta.url,
    ).toString();
}

/** Importa o PDF.js uma única vez e configura o worker. */
export function loadPdfjs(): Promise<PdfjsModule> {
    modulePromise ??= import('pdfjs-dist').then((pdfjs) => {
        pdfjs.GlobalWorkerOptions.workerSrc = workerUrl();

        return pdfjs;
    });

    return modulePromise;
}

export type PdfErrorKind =
    | 'forbidden'
    | 'not_found'
    | 'conflict'
    | 'network'
    | 'password'
    | 'invalid'
    | 'unknown';

/** Erro de leitura do PDF já com mensagem pronta para a interface (PT-BR). */
export class PdfLoadError extends Error {
    readonly kind: PdfErrorKind;

    constructor(kind: PdfErrorKind, message: string) {
        super(message);
        this.name = 'PdfLoadError';
        this.kind = kind;
    }
}

function errorForStatus(status: number): PdfLoadError {
    if (status === 401 || status === 403) {
        return new PdfLoadError(
            'forbidden',
            'Você não tem permissão para visualizar este documento.',
        );
    }

    if (status === 404) {
        return new PdfLoadError(
            'not_found',
            'O arquivo não está mais disponível.',
        );
    }

    if (status === 409 || status === 425) {
        return new PdfLoadError(
            'conflict',
            'O documento ainda está sendo processado.',
        );
    }

    return new PdfLoadError(
        'network',
        'Não foi possível carregar o documento. Tente novamente.',
    );
}

/**
 * Baixa os bytes do PDF pela rota autorizada (cookie de sessão).
 *
 * `onDelivered` é chamado no instante em que o servidor RESPONDE com sucesso — não quando o
 * corpo termina de chegar, e muito menos quando o PDF.js consegue desenhá-lo. É o mesmo
 * instante em que o servidor marca `signing_sessions.document_presented_at`, e é o que
 * mantém cliente e servidor de acordo sobre "o documento foi apresentado": um 403/404 nunca
 * é entrega; uma falha do visualizador depois da entrega não desfaz o que já saiu, e a
 * pessoa ainda tem o botão "Baixar PDF" para ler o que vai assinar.
 */
export async function fetchPdfBytes(
    url: string,
    signal?: AbortSignal,
    onDelivered?: () => void,
): Promise<Uint8Array> {
    let response: globalThis.Response;

    try {
        response = await fetch(url, {
            credentials: 'same-origin',
            headers: { Accept: 'application/pdf' },
            signal,
        });
    } catch (error) {
        if (error instanceof DOMException && error.name === 'AbortError') {
            throw error;
        }

        throw new PdfLoadError(
            'network',
            'Não foi possível carregar o documento. Verifique sua conexão.',
        );
    }

    if (!response.ok) {
        throw errorForStatus(response.status);
    }

    onDelivered?.();

    return new Uint8Array(await response.arrayBuffer());
}

/** Documento aberto + a forma de liberar worker e cache de páginas. */
export interface OpenedPdf {
    document: PDFDocumentProxy;
    /** `PDFDocumentLoadingTask.destroy()`: encerra o worker e libera memória. */
    destroy: () => Promise<void>;
}

/**
 * Abre o documento. `bytes` é copiado antes de ir para o PDF.js porque a
 * biblioteca assume a posse do buffer (e o esvazia) ao transferi-lo ao worker.
 */
export async function openPdfDocument(
    url: string,
    signal?: AbortSignal,
    /**
     * Chamado assim que os BYTES chegaram, antes de o PDF.js tentar abri-los.
     *
     * Entregar e renderizar são coisas diferentes: um 403/404 significa que o documento
     * não saiu do servidor; um `InvalidPDFException` significa que ele saiu e o
     * visualizador não deu conta. Quem depende de "o documento foi apresentado" — a página
     * de assinatura — precisa distinguir os dois, porque no segundo caso a pessoa ainda tem
     * o botão "Baixar PDF" para ler o que vai assinar.
     */
    onDelivered?: () => void,
): Promise<OpenedPdf> {
    // A entrega é avisada pela própria resposta do servidor, sem depender do PDF.js: o
    // módulo é importado dinamicamente e pode falhar por conta própria (asset do worker
    // indisponível, rede), e isso não desfaz o fato de o documento ter sido entregue.
    const [pdfjs, bytes] = await Promise.all([
        loadPdfjs(),
        fetchPdfBytes(url, signal, onDelivered),
    ]);

    const task = pdfjs.getDocument({ data: bytes.slice() });

    try {
        const document = await task.promise;

        return { document, destroy: () => task.destroy() };
    } catch (error) {
        void task.destroy().catch(() => undefined);

        throw toPdfLoadError(error);
    }
}

function toPdfLoadError(error: unknown): Error {
    if (error instanceof PdfLoadError) {
        return error;
    }

    if (error instanceof DOMException && error.name === 'AbortError') {
        return error;
    }

    const name = error instanceof Error ? error.name : '';

    if (name === 'PasswordException') {
        return new PdfLoadError(
            'password',
            'Este PDF está protegido por senha e não pode ser preparado para assinatura.',
        );
    }

    if (name === 'InvalidPDFException') {
        return new PdfLoadError(
            'invalid',
            'Não foi possível ler este PDF. O arquivo pode estar corrompido.',
        );
    }

    return new PdfLoadError(
        'unknown',
        'Não foi possível exibir o documento. Baixe o arquivo para conferir.',
    );
}

/** Dimensões renderizadas de uma página, em pixels CSS. */
export interface RenderedPageSize {
    width: number;
    height: number;
}

/**
 * Escala do PDF.js para caber numa largura em pixels CSS. `1` no PDF.js
 * equivale a 72 dpi; a escala é o quociente entre a largura desejada e a
 * largura do CropBox já rotacionado.
 */
export function scaleForWidth(page: PDFPageProxy, cssWidth: number): number {
    const base = page.getViewport({ scale: 1 });

    return base.width > 0 ? cssWidth / base.width : 1;
}

/** Limite de nitidez: 2× já cobre telas retina sem estourar a memória. */
const MAX_PIXEL_RATIO = 2;

export function devicePixelRatioCapped(): number {
    const ratio =
        typeof window === 'undefined' ? 1 : (window.devicePixelRatio ?? 1);

    return Math.min(MAX_PIXEL_RATIO, Math.max(1, ratio));
}

/**
 * Renderiza a página no canvas com a largura pedida (pixels CSS). O buffer do
 * canvas usa a densidade da tela; o tamanho CSS é o que a camada de campos
 * enxerga — e é ele que define a conversão de coordenadas (`lib/geometry.ts`).
 *
 * Devolve uma função de cancelamento e a promessa da renderização.
 */
export function renderPageToCanvas(
    page: PDFPageProxy,
    canvas: HTMLCanvasElement,
    cssWidth: number,
): { size: RenderedPageSize; promise: Promise<void>; cancel: () => void } {
    const scale = scaleForWidth(page, cssWidth);
    const viewport = page.getViewport({ scale });
    const ratio = devicePixelRatioCapped();

    const size: RenderedPageSize = {
        width: Math.round(viewport.width),
        height: Math.round(viewport.height),
    };

    canvas.width = Math.round(viewport.width * ratio);
    canvas.height = Math.round(viewport.height * ratio);
    canvas.style.width = `${size.width}px`;
    canvas.style.height = `${size.height}px`;

    const context = canvas.getContext('2d');

    if (!context) {
        return {
            size,
            cancel: () => undefined,
            promise: Promise.reject(
                new PdfLoadError(
                    'unknown',
                    'Seu navegador não conseguiu desenhar o documento.',
                ),
            ),
        };
    }

    const task = page.render({
        canvas,
        canvasContext: context,
        viewport,
        transform: ratio === 1 ? undefined : [ratio, 0, 0, ratio, 0, 0],
    });

    return {
        size,
        cancel: () => task.cancel(),
        promise: task.promise,
    };
}

/** `true` quando o erro veio de um `RenderTask.cancel()` (não é falha real). */
export function isRenderCancelled(error: unknown): boolean {
    const name = error instanceof Error ? error.name : '';

    return name === 'RenderingCancelledException' || name === 'AbortError';
}

/** Proporção largura/altura da página já rotacionada (para reservar espaço). */
export function pageAspectRatio(page: PDFPageProxy): number {
    const viewport = page.getViewport({ scale: 1 });

    return viewport.height > 0 ? viewport.width / viewport.height : 1 / 1.3;
}
