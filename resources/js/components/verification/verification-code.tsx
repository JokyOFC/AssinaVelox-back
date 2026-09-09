import { ExternalLink } from 'lucide-react';
import {
    useId,
    useRef,
    type ChangeEvent,
    type ClipboardEvent,
    type KeyboardEvent,
} from 'react';
import { CopyButton } from '@/components/copy-button';
import { Button } from '@/components/ui/button';
import { formatVerificationCode } from '@/lib/format';
import { cn } from '@/lib/utils';
import { QrCode } from './qr-code';

/** Caracteres do alfabeto base32 sem ambíguos (RECONCILIACAO §1). */
const CODE_ALPHABET = /[^ABCDEFGHJKMNPQRSTUVWXYZ23456789]/g;
const BLOCKS = 3;
const BLOCK_SIZE = 4;

export function sanitizeVerificationCode(value: string): string {
    return value.toUpperCase().replace(CODE_ALPHABET, '').slice(0, 12);
}

/**
 * Campo do código de verificação em três blocos de quatro caracteres — a mesma
 * forma impressa no rodapé do PDF. Digitar avança de bloco; apagar recua; colar
 * o código inteiro (com ou sem hífens) preenche tudo.
 */
export function VerificationCodeInput({
    value,
    onChange,
    onComplete,
    invalid = false,
    describedBy,
    className,
    autoFocus = false,
}: {
    /** Somente os 12 caracteres, sem hífens. */
    value: string;
    onChange: (value: string) => void;
    onComplete?: (value: string) => void;
    invalid?: boolean;
    describedBy?: string;
    className?: string;
    autoFocus?: boolean;
}) {
    const id = useId();
    const refs = useRef<(HTMLInputElement | null)[]>([]);

    const commit = (next: string) => {
        onChange(next);

        if (next.length === 12) {
            onComplete?.(next);
        }
    };

    const blockValue = (index: number) =>
        value.slice(index * BLOCK_SIZE, (index + 1) * BLOCK_SIZE);

    const focusBlock = (index: number) => {
        const target = refs.current[Math.min(Math.max(index, 0), BLOCKS - 1)];

        target?.focus();
        target?.select();
    };

    const handleChange =
        (index: number) => (event: ChangeEvent<HTMLInputElement>) => {
            const typed = sanitizeVerificationCode(event.target.value);
            const before = value.slice(0, index * BLOCK_SIZE);
            const after = value.slice((index + 1) * BLOCK_SIZE);
            const next = sanitizeVerificationCode(
                `${before}${typed}${typed.length >= BLOCK_SIZE ? after : ''}`,
            );

            commit(next);

            if (typed.length >= BLOCK_SIZE && index < BLOCKS - 1) {
                focusBlock(index + 1);
            }
        };

    const handleKeyDown =
        (index: number) => (event: KeyboardEvent<HTMLInputElement>) => {
            if (
                event.key === 'Backspace' &&
                blockValue(index).length === 0 &&
                index > 0
            ) {
                event.preventDefault();
                focusBlock(index - 1);
            }

            if (event.key === 'ArrowLeft' && index > 0) {
                focusBlock(index - 1);
            }

            if (event.key === 'ArrowRight' && index < BLOCKS - 1) {
                focusBlock(index + 1);
            }
        };

    const handlePaste = (event: ClipboardEvent<HTMLInputElement>) => {
        const pasted = sanitizeVerificationCode(
            event.clipboardData.getData('text'),
        );

        if (!pasted) {
            return;
        }

        event.preventDefault();
        commit(pasted);
        focusBlock(
            Math.min(Math.floor(pasted.length / BLOCK_SIZE), BLOCKS - 1),
        );
    };

    return (
        <div className={cn('flex items-center gap-2', className)}>
            {Array.from({ length: BLOCKS }, (_, index) => (
                <div key={index} className="contents">
                    {index > 0 && (
                        <span
                            aria-hidden
                            className="text-muted-foreground text-[18px] font-bold"
                        >
                            –
                        </span>
                    )}
                    <input
                        ref={(element) => {
                            refs.current[index] = element;
                        }}
                        id={`${id}-${index}`}
                        aria-label={`Código de verificação, bloco ${index + 1} de ${BLOCKS}`}
                        aria-invalid={invalid}
                        aria-describedby={describedBy}
                        autoFocus={autoFocus && index === 0}
                        inputMode="text"
                        autoCapitalize="characters"
                        autoComplete="off"
                        spellCheck={false}
                        /*
                         * 12, não 4: preenchimento automático e alguns IMEs entregam o
                         * código inteiro de uma vez, e `maxLength=4` truncaria o resto
                         * silenciosamente. O valor exibido continua limitado a quatro
                         * caracteres porque o campo é controlado por `blockValue`.
                         */
                        maxLength={12}
                        value={blockValue(index)}
                        onChange={handleChange(index)}
                        onKeyDown={handleKeyDown(index)}
                        onPaste={handlePaste}
                        placeholder="XXXX"
                        className={cn(
                            'tabular h-12 w-full min-w-0 rounded-lg border bg-white text-center font-mono text-[19px] font-bold tracking-[.16em] uppercase',
                            'placeholder:text-muted-foreground/60 focus-visible:ring-ring/18 focus-visible:border-primary focus-visible:ring-[3px] focus-visible:outline-none',
                            invalid ? 'border-danger' : 'border-input',
                        )}
                    />
                </div>
            ))}
        </div>
    );
}

/**
 * Bloco de exibição do código com botão de copiar, QR opcional e link para a
 * página pública. Usado no dossiê de evidências e no detalhe do documento.
 */
export function VerificationCodeBlock({
    code,
    url,
    qr = true,
    className,
}: {
    code: string | null | undefined;
    /** URL absoluta da página de verificação (também é o conteúdo do QR). */
    url?: string | null;
    qr?: boolean;
    className?: string;
}) {
    const formatted = formatVerificationCode(code);

    return (
        <div className={cn('flex flex-col gap-3', className)}>
            <div className="border-border bg-sidebar flex items-center justify-between gap-2 rounded-lg border p-3">
                <span className="tabular font-mono text-[15px] font-bold">
                    {formatted}
                </span>
                {code && (
                    <CopyButton
                        value={formatted}
                        label="Copiar código de verificação"
                        className="size-6"
                    />
                )}
            </div>
            {url && qr && (
                <div className="flex items-center gap-3">
                    <QrCode value={url} size={104} />
                    <p className="text-muted-foreground text-[12px] leading-[1.5]">
                        Aponte a câmera para abrir a página pública de
                        verificação, ou informe o código em{' '}
                        <span className="text-text-secondary font-medium">
                            /verificar
                        </span>
                        .
                    </p>
                </div>
            )}
            {url && (
                <Button asChild variant="outline" size="sm">
                    <a href={url} target="_blank" rel="noopener noreferrer">
                        <ExternalLink className="size-[15px]" />
                        Abrir página de verificação
                    </a>
                </Button>
            )}
        </div>
    );
}
