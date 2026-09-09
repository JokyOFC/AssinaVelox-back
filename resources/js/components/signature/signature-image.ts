/**
 * Normalização da **representação visual** da assinatura (arquitetura §2).
 *
 * O que sai daqui é sempre um PNG com **fundo transparente**, recortado no
 * traço e reduzido a dimensões razoáveis. Nada disso é assinatura: é a imagem
 * que será desenhada sobre o PDF. A manifestação de vontade é o aceite
 * eletrônico registrado no servidor, que faz a sua própria normalização (GD)
 * antes de guardar o arquivo — este passo no navegador serve para não enviar
 * 4 MB de canvas em branco e para o signatário ver exatamente o que vai valer.
 */

/** Limites do PNG gerado (mesmos do servidor, arquitetura §4.5). */
export const SIGNATURE_MAX_WIDTH = 1200;
export const SIGNATURE_MAX_HEIGHT = 400;
export const INITIALS_MAX_WIDTH = 400;
export const INITIALS_MAX_HEIGHT = 200;

/** Tamanho máximo aceito no modo "Enviar imagem" (ROUTES §3.2, passo 4). */
export const UPLOAD_MAX_BYTES = 2 * 1024 * 1024;

/** Tipos aceitos no modo "Enviar imagem". */
export const UPLOAD_ACCEPTED_MIMES = ['image/png', 'image/jpeg'] as const;

/** Tamanho máximo do PNG enviado ao servidor (ROUTES §2.18). */
export const SIGNATURE_PAYLOAD_MAX_BYTES = 300 * 1024;

export interface NormalizeOptions {
    maxWidth?: number;
    maxHeight?: number;
    /** Margem transparente ao redor do traço, em pixels da imagem recortada. */
    padding?: number;
}

/** Pixel considerado "vazio" abaixo deste alfa (ruído de antialiasing). */
const ALPHA_THRESHOLD = 8;

/**
 * Luminância acima da qual um pixel opaco é tratado como fundo de papel.
 * Usado apenas quando o signatário pede para remover o fundo de uma foto.
 */
const WHITE_THRESHOLD = 232;

function createCanvas(width: number, height: number): HTMLCanvasElement {
    const canvas = document.createElement('canvas');
    canvas.width = Math.max(1, Math.round(width));
    canvas.height = Math.max(1, Math.round(height));

    return canvas;
}

function context2d(canvas: HTMLCanvasElement): CanvasRenderingContext2D {
    const ctx = canvas.getContext('2d', { willReadFrequently: true });

    if (!ctx) {
        throw new Error('Este navegador não suporta a captura de assinatura.');
    }

    return ctx;
}

/**
 * Retângulo dos pixels não transparentes. `null` quando a imagem está vazia
 * (o signatário abriu o quadro e não desenhou nada).
 */
function opaqueBounds(
    data: Uint8ClampedArray,
    width: number,
    height: number,
): { left: number; top: number; right: number; bottom: number } | null {
    let left = width;
    let top = height;
    let right = -1;
    let bottom = -1;

    for (let y = 0; y < height; y += 1) {
        for (let x = 0; x < width; x += 1) {
            if (data[(y * width + x) * 4 + 3] > ALPHA_THRESHOLD) {
                if (x < left) left = x;
                if (x > right) right = x;
                if (y < top) top = y;
                if (y > bottom) bottom = y;
            }
        }
    }

    return right < left || bottom < top ? null : { left, top, right, bottom };
}

/**
 * Recorta o traço, aplica uma margem e reduz para caber em `maxWidth` ×
 * `maxHeight` mantendo a proporção. Devolve `null` para imagem vazia.
 */
export function normalizeSignatureCanvas(
    source: HTMLCanvasElement,
    options: NormalizeOptions = {},
): string | null {
    const {
        maxWidth = SIGNATURE_MAX_WIDTH,
        maxHeight = SIGNATURE_MAX_HEIGHT,
        padding = 8,
    } = options;

    const ctx = context2d(source);
    const image = ctx.getImageData(0, 0, source.width, source.height);
    const bounds = opaqueBounds(image.data, source.width, source.height);

    if (!bounds) {
        return null;
    }

    const left = Math.max(0, bounds.left - padding);
    const top = Math.max(0, bounds.top - padding);
    const right = Math.min(source.width - 1, bounds.right + padding);
    const bottom = Math.min(source.height - 1, bounds.bottom + padding);

    const cropWidth = right - left + 1;
    const cropHeight = bottom - top + 1;
    const scale = Math.min(1, maxWidth / cropWidth, maxHeight / cropHeight);

    const target = createCanvas(cropWidth * scale, cropHeight * scale);
    const targetCtx = context2d(target);
    targetCtx.imageSmoothingEnabled = true;
    targetCtx.imageSmoothingQuality = 'high';
    targetCtx.drawImage(
        source,
        left,
        top,
        cropWidth,
        cropHeight,
        0,
        0,
        target.width,
        target.height,
    );

    return target.toDataURL('image/png');
}

/**
 * Transforma o papel branco de uma foto em transparência. Conservador de
 * propósito: só zera o alfa de pixels claros e mantém o restante intacto, de
 * modo que uma assinatura escura sobrevive e uma foto colorida não vira um
 * borrão. O signatário pode desligar quando o resultado não ficar bom.
 */
function removeWhiteBackground(canvas: HTMLCanvasElement): void {
    const ctx = context2d(canvas);
    const image = ctx.getImageData(0, 0, canvas.width, canvas.height);
    const data = image.data;

    for (let i = 0; i < data.length; i += 4) {
        const luminance =
            0.299 * data[i] + 0.587 * data[i + 1] + 0.114 * data[i + 2];

        if (luminance >= WHITE_THRESHOLD) {
            data[i + 3] = 0;

            continue;
        }

        // Meio-tom: reduz o alfa proporcionalmente para não deixar uma borda
        // cinza dura em volta do traço.
        if (luminance > WHITE_THRESHOLD - 40) {
            const ratio = (WHITE_THRESHOLD - luminance) / 40;
            data[i + 3] = Math.round(data[i + 3] * ratio);
        }
    }

    ctx.putImageData(image, 0, 0);
}

export class SignatureImageError extends Error {}

function readImage(file: File): Promise<HTMLImageElement> {
    return new Promise((resolve, reject) => {
        const url = URL.createObjectURL(file);
        const image = new Image();

        image.onload = () => {
            URL.revokeObjectURL(url);
            resolve(image);
        };
        image.onerror = () => {
            URL.revokeObjectURL(url);
            reject(
                new SignatureImageError(
                    'Não foi possível abrir esta imagem. Envie um PNG ou JPG.',
                ),
            );
        };
        image.src = url;
    });
}

/** Validação de tipo e tamanho **antes** de qualquer leitura do arquivo. */
export function validateSignatureFile(
    file: File,
    maxBytes = UPLOAD_MAX_BYTES,
): string | null {
    if (!(UPLOAD_ACCEPTED_MIMES as readonly string[]).includes(file.type)) {
        return 'Formato não aceito. Envie uma imagem PNG ou JPG.';
    }

    if (file.size > maxBytes) {
        return `A imagem tem ${formatBytes(file.size)} e o limite é ${formatBytes(maxBytes)}.`;
    }

    return null;
}

function formatBytes(bytes: number): string {
    return bytes >= 1024 * 1024
        ? `${(bytes / (1024 * 1024)).toFixed(1).replace('.', ',')} MB`
        : `${Math.round(bytes / 1024)} KB`;
}

/**
 * Lê a imagem enviada pelo signatário e devolve o PNG normalizado.
 * Lança `SignatureImageError` com mensagem em PT-BR quando o arquivo não serve.
 */
export async function normalizeUploadedImage(
    file: File,
    options: NormalizeOptions & { transparentBackground?: boolean } = {},
): Promise<string> {
    const image = await readImage(file);

    if (image.naturalWidth === 0 || image.naturalHeight === 0) {
        throw new SignatureImageError('A imagem enviada está vazia.');
    }

    const canvas = createCanvas(image.naturalWidth, image.naturalHeight);
    context2d(canvas).drawImage(image, 0, 0);

    if (options.transparentBackground ?? true) {
        removeWhiteBackground(canvas);
    }

    const normalized = normalizeSignatureCanvas(canvas, options);

    if (!normalized) {
        throw new SignatureImageError(
            'A imagem ficou vazia depois de remover o fundo. Desligue "Remover fundo claro" e tente de novo.',
        );
    }

    return normalized;
}

/**
 * Estilos de escrita do modo "Digitar".
 *
 * `font` é o valor gravado como evidência (`signature_acceptances.typed_font`)
 * e precisa estar na lista que o servidor aceita (`RecordAcceptance::FONTS`).
 * Por isso os dois estilos abaixo declaram `caveat`: ambos **são** a Caveat —
 * o que muda é a inclinação do traçado, não a família. Dizer outra coisa seria
 * gravar uma evidência falsa.
 */
export interface SignatureStyle {
    key: string;
    label: string;
    /** Valor enviado ao servidor em `signature.font`. */
    font: string;
    /** Pilha CSS usada tanto no preview quanto no `fillText` do canvas. */
    fontFamily: string;
    weight: number;
    /** Inclinação em graus aplicada ao traçado (0 = reto). */
    slant: number;
}

export const SIGNATURE_STYLES: readonly SignatureStyle[] = [
    {
        key: 'caveat',
        label: 'Manuscrita',
        font: 'caveat',
        fontFamily: "'Caveat', cursive",
        weight: 600,
        slant: 0,
    },
    {
        key: 'caveat_slanted',
        label: 'Manuscrita inclinada',
        font: 'caveat',
        fontFamily: "'Caveat', cursive",
        weight: 600,
        slant: 8,
    },
] as const;

export function signatureStyle(key: string | null | undefined): SignatureStyle {
    return (
        SIGNATURE_STYLES.find((style) => style.key === key) ??
        SIGNATURE_STYLES[0]
    );
}

/** Cor do traço — a mesma do mock (DESIGN §4.20). */
const INK = '#0b1f42';

/**
 * Desenha o nome digitado e devolve o PNG transparente. A fonte é carregada
 * antes (`document.fonts.load`) para que o canvas não caia numa família
 * genérica quando a Caveat ainda não foi baixada.
 */
export async function renderTypedSignature(
    text: string,
    style: SignatureStyle,
    options: NormalizeOptions = {},
): Promise<string | null> {
    const value = text.trim();

    if (value === '') {
        return null;
    }

    const fontSize = 128;
    const font = `${style.weight} ${fontSize}px ${style.fontFamily}`;

    try {
        await document.fonts.load(font, value);
    } catch {
        // Fonte indisponível: o canvas cai para a família seguinte da pilha.
        // Melhor uma assinatura em serifada do que nenhuma.
    }

    const measure = context2d(createCanvas(8, 8));
    measure.font = font;
    const metrics = measure.measureText(value);
    const width = Math.ceil(metrics.width) + fontSize;
    const height = Math.ceil(fontSize * 2);

    const canvas = createCanvas(width, height);
    const ctx = context2d(canvas);
    ctx.font = font;
    ctx.fillStyle = INK;
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';

    if (style.slant !== 0) {
        ctx.translate(width / 2, height / 2);
        ctx.transform(1, 0, -Math.tan((style.slant * Math.PI) / 180), 1, 0, 0);
        ctx.fillText(value, 0, 0);
    } else {
        ctx.fillText(value, width / 2, height / 2);
    }

    return normalizeSignatureCanvas(canvas, options);
}

/** Iniciais sugeridas para a rubrica ("Maria A. Souza" → "MAS"). */
export function initialsOf(name: string): string {
    return name
        .split(/\s+/)
        .filter((part) => part.length > 1 || /[A-Za-zÀ-ÿ]/.test(part))
        .map((part) => part[0]?.toUpperCase() ?? '')
        .join('')
        .slice(0, 3);
}

/** Tamanho aproximado, em bytes, de um data URL base64. */
export function dataUrlBytes(dataUrl: string): number {
    const base64 = dataUrl.slice(dataUrl.indexOf(',') + 1);

    return Math.floor((base64.length * 3) / 4);
}
