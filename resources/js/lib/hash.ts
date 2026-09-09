/**
 * SHA-256 de um arquivo local, calculado **no navegador**.
 *
 * Usado pela conferência de integridade da verificação pública (arquitetura §6:
 * "comparação de arquivo local por SHA-256 calculado no navegador (WebCrypto);
 * sem upload"). Nada aqui faz requisição de rede: o arquivo é lido pelo próprio
 * `File` que o usuário escolheu e nunca sai da máquina dele.
 *
 * Por que ler em blocos: `crypto.subtle.digest` não é incremental — ele recebe
 * o buffer inteiro de uma vez. A leitura do arquivo é o que demora em arquivos
 * grandes, então lemos em fatias (`File.slice`), reportamos progresso a cada
 * fatia e só então chamamos o digest. O custo é manter o arquivo em memória;
 * por isso existe `MAX_FILE_BYTES`.
 */

/** Tamanho de cada fatia lida (4 MiB): compromisso entre progresso e overhead. */
export const CHUNK_BYTES = 4 * 1024 * 1024;

/**
 * Acima disso a conferência é recusada com mensagem honesta em vez de travar a
 * aba: o arquivo inteiro precisa caber na memória do navegador.
 */
export const MAX_FILE_BYTES = 512 * 1024 * 1024;

/** Por que o cálculo local pode não estar disponível. */
export type HashSupport =
    /** `crypto.subtle` presente e utilizável. */
    | 'ok'
    /**
     * A página não está em contexto seguro (HTTP sem TLS, por exemplo). Os
     * navegadores só expõem `crypto.subtle` em `https:`, `localhost` ou
     * `file:` — não é uma limitação da AssinaVelox.
     */
    | 'insecure_context'
    /** Navegador sem WebCrypto (versões muito antigas). */
    | 'unsupported';

export function webCryptoSupport(): HashSupport {
    if (typeof globalThis === 'undefined') {
        return 'unsupported';
    }

    const secure = (globalThis as { isSecureContext?: boolean })
        .isSecureContext;
    const subtle = globalThis.crypto?.subtle;

    if (typeof subtle?.digest === 'function') {
        return 'ok';
    }

    return secure === false ? 'insecure_context' : 'unsupported';
}

/** Mensagem PT-BR para cada motivo de indisponibilidade. */
export const HASH_SUPPORT_MESSAGES: Record<
    Exclude<HashSupport, 'ok'>,
    string
> = {
    insecure_context:
        'Esta página não está em conexão segura (HTTPS), e os navegadores só liberam o cálculo de hash em contexto seguro. Abra a página por HTTPS para conferir o arquivo aqui — ou calcule o SHA-256 por conta própria e compare com o valor publicado abaixo.',
    unsupported:
        'Este navegador não oferece a API de criptografia (WebCrypto) necessária para calcular o hash localmente. Atualize o navegador ou calcule o SHA-256 por conta própria e compare com o valor publicado abaixo.',
};

export type HashPhase = 'reading' | 'digesting';

export interface HashProgress {
    phase: HashPhase;
    /** Bytes já lidos. */
    loaded: number;
    /** Tamanho total do arquivo em bytes. */
    total: number;
    /** 0–100, já arredondado. A fase de digest ocupa os últimos pontos. */
    percent: number;
}

export class HashUnsupportedError extends Error {
    constructor(readonly reason: Exclude<HashSupport, 'ok'>) {
        super(HASH_SUPPORT_MESSAGES[reason]);
        this.name = 'HashUnsupportedError';
    }
}

export class HashFileTooLargeError extends Error {
    constructor(readonly size: number) {
        super(
            `O arquivo tem ${Math.round(size / (1024 * 1024))} MB e não cabe na memória do navegador para o cálculo local.`,
        );
        this.name = 'HashFileTooLargeError';
    }
}

export class HashAbortedError extends Error {
    constructor() {
        super('Cálculo cancelado.');
        this.name = 'HashAbortedError';
    }
}

export interface Sha256FileOptions {
    onProgress?: (progress: HashProgress) => void;
    signal?: AbortSignal;
    /** Só para testes: permite forçar fatias menores. */
    chunkBytes?: number;
}

function toHex(buffer: ArrayBuffer): string {
    const bytes = new Uint8Array(buffer);
    let out = '';

    for (let i = 0; i < bytes.length; i += 1) {
        out += bytes[i].toString(16).padStart(2, '0');
    }

    return out;
}

/**
 * Calcula o SHA-256 de um `File`/`Blob` e devolve o hexadecimal minúsculo de
 * 64 caracteres. Lança `HashUnsupportedError`, `HashFileTooLargeError` ou
 * `HashAbortedError` — todos com mensagem pronta para exibição.
 */
export async function sha256File(
    file: Blob,
    { onProgress, signal, chunkBytes = CHUNK_BYTES }: Sha256FileOptions = {},
): Promise<string> {
    const support = webCryptoSupport();

    if (support !== 'ok') {
        throw new HashUnsupportedError(support);
    }

    if (file.size > MAX_FILE_BYTES) {
        throw new HashFileTooLargeError(file.size);
    }

    const total = file.size;
    const bytes = new Uint8Array(total);
    let offset = 0;

    // 95% do progresso é leitura; o digest em si é rápido e fecha a barra.
    const report = (phase: HashPhase, loaded: number) =>
        onProgress?.({
            phase,
            loaded,
            total,
            percent:
                total === 0
                    ? phase === 'digesting'
                        ? 100
                        : 95
                    : Math.min(
                          100,
                          Math.round(
                              (loaded / total) * 95 +
                                  (phase === 'digesting' ? 5 : 0),
                          ),
                      ),
        });

    report('reading', 0);

    while (offset < total) {
        if (signal?.aborted) {
            throw new HashAbortedError();
        }

        const end = Math.min(offset + chunkBytes, total);
        const chunk = new Uint8Array(
            await file.slice(offset, end).arrayBuffer(),
        );

        bytes.set(chunk, offset);
        offset = end;
        report('reading', offset);
    }

    if (signal?.aborted) {
        throw new HashAbortedError();
    }

    report('digesting', total);

    const digest = await globalThis.crypto.subtle.digest('SHA-256', bytes);

    return toHex(digest);
}

/** Normaliza um hash para comparação: sem espaços, minúsculo. */
export function normalizeHash(value: string | null | undefined): string {
    return (value ?? '').replace(/\s+/g, '').toLowerCase();
}

/** Comparação de hashes tolerante a maiúsculas/minúsculas e espaços. */
export function hashesMatch(
    a: string | null | undefined,
    b: string | null | undefined,
): boolean {
    const left = normalizeHash(a);
    const right = normalizeHash(b);

    return left.length === 64 && left === right;
}

/** "4b0d…7c1e" — forma curta para linhas de detalhe. */
export function shortHash(
    value: string | null | undefined,
    head = 8,
    tail = 4,
): string {
    const clean = normalizeHash(value);

    if (clean.length <= head + tail) {
        return clean || '—';
    }

    return `${clean.slice(0, head)}…${clean.slice(-tail)}`;
}

/** Quebra o hash em grupos de 8 para leitura/conferência visual. */
export function hashGroups(value: string | null | undefined): string[] {
    return normalizeHash(value).match(/.{1,8}/g) ?? [];
}
