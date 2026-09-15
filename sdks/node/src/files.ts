import { readFile } from 'node:fs/promises';
import { basename, extname } from 'node:path';

/**
 * Arquivo para `uploadDocument` (multipart, campo `file`). A API não aceita
 * upload por URL.
 */
export interface FileUpload {
    content: Uint8Array | ArrayBuffer | string;
    filename: string;
    contentType?: string;
}

const TYPES: Record<string, string> = {
    '.pdf': 'application/pdf',
    '.docx':
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    '.doc': 'application/msword',
    '.png': 'image/png',
    '.jpg': 'image/jpeg',
    '.jpeg': 'image/jpeg',
};

/** Lê um arquivo do disco para enviar com `uploadDocument`. */
export async function fileFromPath(
    path: string,
    contentType?: string,
): Promise<FileUpload> {
    const content = new Uint8Array(await readFile(path));
    return {
        content,
        filename: basename(path),
        contentType:
            contentType ??
            TYPES[extname(path).toLowerCase()] ??
            'application/octet-stream',
    };
}

export function toBytes(
    content: Uint8Array | ArrayBuffer | string,
): Uint8Array {
    if (typeof content === 'string') {
        return new TextEncoder().encode(content);
    }
    return content instanceof Uint8Array ? content : new Uint8Array(content);
}

/** Arquivo baixado de `downloadFile` (original, assinado ou evidências). */
export class DownloadedFile {
    constructor(
        readonly content: Uint8Array,
        readonly contentType: string | null,
        readonly filename: string | null,
    ) {}

    static fromResponse(
        headers: Record<string, string>,
        body: Uint8Array,
    ): DownloadedFile {
        const disposition = headers['content-disposition'] ?? '';
        const extended = /filename\*\s*=\s*UTF-8''([^;]+)/i.exec(disposition);
        const quoted = /filename\s*=\s*"([^"]*)"/.exec(disposition);
        const plain = /filename\s*=\s*([^;]+)/.exec(disposition);
        let filename: string | null = null;
        if (extended?.[1] !== undefined) {
            filename = decodeURIComponent(extended[1].trim());
        } else if (quoted?.[1] !== undefined) {
            filename = quoted[1].trim();
        } else if (plain?.[1] !== undefined) {
            filename = plain[1].trim();
        }
        const type = headers['content-type'];
        return new DownloadedFile(
            body,
            type === undefined ? null : (type.split(';')[0] ?? '').trim(),
            filename,
        );
    }
}
