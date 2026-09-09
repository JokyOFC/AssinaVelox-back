import { Upload } from 'lucide-react';
import { useId, useRef, useState } from 'react';
import { cn } from '@/lib/utils';
import { formatBytes } from '@/lib/format';

/** Extensão exibida a partir do MIME aceito (mensagens e atributo `accept`). */
const MIME_EXTENSIONS: Record<string, string> = {
    'application/pdf': '.pdf',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document':
        '.docx',
    'image/png': '.png',
    'image/jpeg': '.jpg',
    'image/webp': '.webp',
};

const MIME_SHORT_LABEL: Record<string, string> = {
    'application/pdf': 'PDF',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document':
        'DOCX',
    'image/png': 'PNG',
    'image/jpeg': 'JPG',
    'image/webp': 'WEBP',
};

export interface DropzoneLimits {
    max_upload_bytes: number;
    accepted_mimes: string[];
}

function extensionsFor(mimes: string[]): string[] {
    return mimes.map((mime) => MIME_EXTENSIONS[mime] ?? '').filter(Boolean);
}

function shortLabels(mimes: string[]): string[] {
    return [...new Set(mimes.map((mime) => MIME_SHORT_LABEL[mime] ?? mime))];
}

function matchesAccepted(file: File, mimes: string[]): boolean {
    if (mimes.includes(file.type)) {
        return true;
    }

    // Alguns navegadores entregam `type` vazio; cai para a extensão.
    const name = file.name.toLowerCase();

    return extensionsFor(mimes).some((extension) => name.endsWith(extension));
}

/**
 * Valida um arquivo **antes** de enviar. Devolve a mensagem PT-BR do problema
 * ou `null` quando está tudo certo. O backend revalida o MIME real com `finfo`
 * (arquitetura §5) — esta checagem só evita upload inútil.
 */
export function validateUploadFile(
    file: File,
    limits: DropzoneLimits,
): string | null {
    if (!matchesAccepted(file, limits.accepted_mimes)) {
        return `Formato não aceito. Envie um arquivo ${shortLabels(limits.accepted_mimes).join(', ')}.`;
    }

    if (file.size > limits.max_upload_bytes) {
        return `Arquivo muito grande (${formatBytes(file.size)}). O limite é ${formatBytes(limits.max_upload_bytes)}.`;
    }

    if (file.size === 0) {
        return 'O arquivo está vazio.';
    }

    return null;
}

/**
 * Dropzone de upload (DESIGN §4.13): arraste e solte ou clique para escolher.
 * A validação de tipo e tamanho acontece no cliente antes de qualquer envio.
 */
export function DocumentDropzone({
    limits,
    onFile,
    onReject,
    disabled,
    className,
}: {
    limits: DropzoneLimits;
    onFile: (file: File) => void;
    onReject?: (message: string) => void;
    disabled?: boolean;
    className?: string;
}) {
    const inputRef = useRef<HTMLInputElement | null>(null);
    const [dragging, setDragging] = useState(false);
    const inputId = useId();

    const accept = [
        ...limits.accepted_mimes,
        ...extensionsFor(limits.accepted_mimes),
    ].join(',');

    const handleFiles = (files: FileList | null): void => {
        const file = files?.[0];

        if (!file) {
            return;
        }

        if (files && files.length > 1) {
            onReject?.(
                'Envie um arquivo por documento. Vários arquivos em um só envelope chegam na Fase 2.',
            );

            return;
        }

        const problem = validateUploadFile(file, limits);

        if (problem) {
            onReject?.(problem);

            return;
        }

        onFile(file);
    };

    const open = (): void => {
        if (!disabled) {
            inputRef.current?.click();
        }
    };

    return (
        // A zona inteira é o alvo de clique (e de arraste). O `<input>` fica
        // fora do fluxo, acionado por `open()`, para não abrir dois seletores.
        <div
            role="button"
            tabIndex={disabled ? -1 : 0}
            aria-disabled={disabled}
            aria-label="Enviar documento: arraste o arquivo ou selecione no computador"
            className={cn(
                'focus-ring flex flex-col items-center gap-2.5 rounded-xl border-[1.5px] border-dashed px-5 py-9 text-center transition-colors',
                disabled
                    ? 'border-border bg-muted cursor-not-allowed opacity-70'
                    : 'border-border-dashed bg-background hover:border-primary hover:bg-accent-subtle cursor-pointer',
                dragging && !disabled && 'border-primary bg-accent-subtle',
                className,
            )}
            onKeyDown={(event) => {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    open();
                }
            }}
            onDragOver={(event) => {
                if (disabled) {
                    return;
                }

                event.preventDefault();
                event.dataTransfer.dropEffect = 'copy';
                setDragging(true);
            }}
            onDragLeave={(event) => {
                if (event.target === event.currentTarget) {
                    setDragging(false);
                }
            }}
            onDrop={(event) => {
                if (disabled) {
                    return;
                }

                event.preventDefault();
                setDragging(false);
                handleFiles(event.dataTransfer.files);
            }}
            onClick={open}
        >
            <span className="bg-primary-soft text-primary flex size-11 items-center justify-center rounded-xl">
                <Upload className="size-5" />
            </span>
            <span className="text-[13.5px] font-semibold">
                Arraste o arquivo aqui ou{' '}
                <span className="text-primary">selecione no computador</span>
            </span>
            <p className="text-muted-foreground text-[12.5px]">
                {shortLabels(limits.accepted_mimes).join(', ')} · até{' '}
                {formatBytes(limits.max_upload_bytes)} · um arquivo por
                documento
            </p>
            <input
                ref={inputRef}
                id={inputId}
                type="file"
                tabIndex={-1}
                aria-hidden
                className="sr-only"
                accept={accept}
                disabled={disabled}
                onChange={(event) => {
                    handleFiles(event.target.files);
                    event.target.value = '';
                }}
            />
        </div>
    );
}
