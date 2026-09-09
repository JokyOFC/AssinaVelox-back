import { router } from '@inertiajs/react';
import {
    CircleAlert,
    FileSearch,
    Lock,
    ShieldCheck,
    ShieldX,
    X,
} from 'lucide-react';
import { useRef, useState, type DragEvent, type FormEvent } from 'react';
import { CopyButton } from '@/components/copy-button';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Progress } from '@/components/ui/progress';
import { formatBytes } from '@/lib/format';
import {
    HASH_SUPPORT_MESSAGES,
    hashesMatch,
    normalizeHash,
    sha256File,
    webCryptoSupport,
    type HashProgress,
} from '@/lib/hash';
import { cn } from '@/lib/utils';

export interface FileCheckTarget {
    key: string;
    /** Nome do arquivo ao qual este resumo pertence ("PDF final"). */
    label: string;
    sha256: string;
    /**
     * O arquivo que a verificação considera "o arquivo registrado". Só ele
     * produz o resultado verde. Normalmente é o resumo final.
     */
    canonical?: boolean;
    /** Frase mostrada quando a correspondência é com este alvo secundário. */
    hint?: string;
}

/**
 * Conferência **por resumo digitado**, para quem não tem WebCrypto disponível
 * (contexto não seguro, navegador antigo) e calculou o SHA-256 por conta própria
 * — `certutil -hashfile`, `sha256sum`, `Get-FileHash`. Aqui o resumo (não o
 * arquivo) vai ao servidor; a diferença fica dita na tela.
 */
export interface FileCheckManual {
    /** URL de `verify.check_file`. */
    action: string;
    result: {
        matches: 'signed' | 'original' | 'none';
        checked_sha256: string;
    } | null;
}

type Outcome =
    | { kind: 'canonical'; target: FileCheckTarget }
    | { kind: 'other'; target: FileCheckTarget }
    | { kind: 'none' };

/**
 * Conferência de integridade de um arquivo local (arquitetura §6).
 *
 * O arquivo é lido pelo próprio navegador e o SHA-256 é calculado com WebCrypto
 * na máquina de quem confere. **Nada é enviado** — nem o arquivo, nem o hash,
 * nem o nome do arquivo. A comparação acontece inteiramente no cliente, contra
 * os resumos que já vieram nesta página.
 */
export function FileCheck({
    targets,
    manual,
    className,
    title = 'Conferir o arquivo que você tem em mãos',
}: {
    targets: FileCheckTarget[];
    manual?: FileCheckManual;
    className?: string;
    title?: string;
}) {
    const usable = targets.filter((target) => target.sha256);
    const [support] = useState(webCryptoSupport);
    const [file, setFile] = useState<File | null>(null);
    const [hash, setHash] = useState<string | null>(null);
    const [progress, setProgress] = useState<HashProgress | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [dragging, setDragging] = useState(false);
    const inputRef = useRef<HTMLInputElement>(null);
    const abortRef = useRef<AbortController | null>(null);
    const [manualHash, setManualHash] = useState('');
    const [manualError, setManualError] = useState<string | null>(null);
    const [manualBusy, setManualBusy] = useState(false);

    if (usable.length === 0) {
        return null;
    }

    const reset = () => {
        abortRef.current?.abort();
        abortRef.current = null;
        setFile(null);
        setHash(null);
        setProgress(null);
        setError(null);

        if (inputRef.current) {
            inputRef.current.value = '';
        }
    };

    const run = async (chosen: File | undefined | null) => {
        if (!chosen) {
            return;
        }

        abortRef.current?.abort();

        const controller = new AbortController();

        abortRef.current = controller;
        setFile(chosen);
        setHash(null);
        setError(null);
        setProgress({
            phase: 'reading',
            loaded: 0,
            total: chosen.size,
            percent: 0,
        });

        try {
            const digest = await sha256File(chosen, {
                signal: controller.signal,
                onProgress: setProgress,
            });

            if (!controller.signal.aborted) {
                setHash(digest);
            }
        } catch (exception) {
            if (!controller.signal.aborted) {
                setError(
                    exception instanceof Error
                        ? exception.message
                        : 'Não foi possível ler o arquivo neste navegador.',
                );
            }
        } finally {
            if (abortRef.current === controller) {
                abortRef.current = null;
                setProgress(null);
            }
        }
    };

    const onDrop = (event: DragEvent<HTMLDivElement>) => {
        event.preventDefault();
        setDragging(false);
        void run(event.dataTransfer.files?.[0]);
    };

    const outcome: Outcome | null = hash
        ? (() => {
              const hit = usable.find((target) =>
                  hashesMatch(hash, target.sha256),
              );

              if (!hit) {
                  return { kind: 'none' } as const;
              }

              return hit.canonical
                  ? ({ kind: 'canonical', target: hit } as const)
                  : ({ kind: 'other', target: hit } as const);
          })()
        : null;

    return (
        <section
            className={cn(
                'border-border-dashed rounded-xl border border-dashed p-4 md:p-5',
                className,
            )}
        >
            <div className="flex items-start gap-3">
                <span className="bg-primary-soft text-primary flex size-9 shrink-0 items-center justify-center rounded-[10px]">
                    <FileSearch className="size-[18px]" />
                </span>
                <div className="min-w-0 flex-1">
                    <h3 className="text-[14px] leading-[1.3] font-semibold">
                        {title}
                    </h3>
                    <p className="text-text-secondary mt-1 text-[12.5px] leading-[1.55]">
                        Selecione o PDF e o navegador calcula o resumo SHA-256
                        dele, comparando com o valor registrado aqui. Serve para
                        responder a uma única pergunta: este arquivo é
                        exatamente o que a AssinaVelox registrou?
                    </p>
                    <p className="text-muted-foreground mt-1.5 flex items-start gap-1.5 text-[12px] leading-[1.5]">
                        <Lock className="mt-[2px] size-3.5 shrink-0" />
                        <span>
                            <b className="text-text-secondary">
                                O arquivo não sai do seu navegador.
                            </b>{' '}
                            Ele não é enviado para a AssinaVelox nem para nenhum
                            outro servidor: a leitura e o cálculo acontecem
                            nesta aba, e o resultado não é registrado em lugar
                            nenhum.
                        </span>
                    </p>
                </div>
            </div>

            {support !== 'ok' ? (
                <p className="border-warning-border bg-warning-bg text-warning mt-4 flex items-start gap-2 rounded-[10px] border p-3 text-[12.5px] leading-[1.5]">
                    <CircleAlert className="mt-0.5 size-4 shrink-0" />
                    <span>{HASH_SUPPORT_MESSAGES[support]}</span>
                </p>
            ) : (
                <>
                    <div
                        onDragOver={(event) => {
                            event.preventDefault();
                            setDragging(true);
                        }}
                        onDragLeave={() => setDragging(false)}
                        onDrop={onDrop}
                        className={cn(
                            'mt-4 rounded-[10px] border border-dashed p-4 text-center transition-colors',
                            dragging
                                ? 'border-primary bg-accent-subtle'
                                : 'border-border-dashed bg-background',
                        )}
                    >
                        <input
                            ref={inputRef}
                            id="file-check-input"
                            type="file"
                            accept="application/pdf,.pdf"
                            className="sr-only"
                            onChange={(event) =>
                                void run(event.target.files?.[0])
                            }
                        />
                        <label
                            htmlFor="file-check-input"
                            className="text-text-secondary cursor-pointer text-[13px]"
                        >
                            Arraste o arquivo aqui ou{' '}
                            <span className="text-primary font-semibold underline-offset-2 hover:underline">
                                selecione no computador
                            </span>
                        </label>
                        {file && (
                            <p className="text-muted-foreground mt-2 flex flex-wrap items-center justify-center gap-2 text-[12px]">
                                <span className="text-text-secondary font-medium">
                                    {file.name}
                                </span>
                                <span>· {formatBytes(file.size)}</span>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon-xs"
                                    aria-label="Limpar arquivo"
                                    onClick={reset}
                                >
                                    <X className="size-3.5" />
                                </Button>
                            </p>
                        )}
                    </div>

                    {progress && (
                        <div className="mt-3">
                            <div className="text-text-secondary flex items-center justify-between text-[12px]">
                                <span>
                                    {progress.phase === 'reading'
                                        ? 'Lendo o arquivo…'
                                        : 'Calculando o SHA-256…'}
                                </span>
                                <span className="tabular">
                                    {progress.percent}%
                                </span>
                            </div>
                            <Progress
                                value={progress.percent}
                                className="bg-accent mt-1.5 h-2"
                            />
                        </div>
                    )}

                    {error && (
                        <p className="border-danger-border bg-danger-bg text-danger mt-3 rounded-[10px] border p-3 text-[12.5px] leading-[1.5]">
                            {error}
                        </p>
                    )}

                    {hash && outcome && (
                        <div className="mt-3 flex flex-col gap-2">
                            <div
                                className={cn(
                                    'flex items-start gap-2.5 rounded-[10px] border p-3.5 text-[13px] leading-[1.5]',
                                    outcome.kind === 'canonical'
                                        ? 'border-success-border bg-success-bg text-success'
                                        : outcome.kind === 'other'
                                          ? 'border-warning-border bg-warning-bg text-warning'
                                          : 'border-danger-border bg-danger-bg text-danger',
                                )}
                            >
                                {outcome.kind === 'canonical' ? (
                                    <ShieldCheck className="mt-0.5 size-4 shrink-0" />
                                ) : outcome.kind === 'other' ? (
                                    <CircleAlert className="mt-0.5 size-4 shrink-0" />
                                ) : (
                                    <ShieldX className="mt-0.5 size-4 shrink-0" />
                                )}
                                <span>
                                    {outcome.kind === 'canonical' && (
                                        <>
                                            <b>Confere.</b> Este é exatamente o
                                            arquivo registrado como{' '}
                                            {outcome.target.label.toLowerCase()}
                                            : cada byte é idêntico ao que a
                                            AssinaVelox registrou.
                                        </>
                                    )}
                                    {outcome.kind === 'other' && (
                                        <>
                                            <b>
                                                Confere com o{' '}
                                                {outcome.target.label}.
                                            </b>{' '}
                                            {outcome.target.hint ??
                                                'Não é o arquivo final publicado nesta página — é outra etapa do mesmo documento.'}
                                        </>
                                    )}
                                    {outcome.kind === 'none' && (
                                        <>
                                            <b>Não confere.</b> O resumo deste
                                            arquivo não corresponde a nenhum dos
                                            resumos registrados para este
                                            documento. Qualquer alteração — um
                                            byte, um salvamento em outro
                                            programa, uma reimpressão — muda o
                                            resumo por inteiro. Confira se você
                                            selecionou o arquivo certo antes de
                                            concluir que ele foi adulterado.
                                        </>
                                    )}
                                </span>
                            </div>
                            <div className="border-border bg-sidebar rounded-lg border p-3 text-[12px]">
                                <div className="flex items-center justify-between gap-2">
                                    <span className="text-text-secondary font-semibold">
                                        SHA-256 do arquivo escolhido
                                    </span>
                                    <CopyButton
                                        value={hash}
                                        className="size-6"
                                    />
                                </div>
                                <code className="mt-1 block font-mono break-all">
                                    {hash}
                                </code>
                            </div>
                        </div>
                    )}
                </>
            )}

            {manual && (
                <ManualHashCheck
                    manual={manual}
                    targets={usable}
                    value={manualHash}
                    onChange={(next) => {
                        setManualHash(next);
                        setManualError(null);
                    }}
                    error={manualError}
                    busy={manualBusy}
                    onSubmit={(event) => {
                        event.preventDefault();

                        const clean = normalizeHash(manualHash);

                        if (!/^[a-f0-9]{64}$/.test(clean)) {
                            setManualError(
                                'Informe os 64 caracteres hexadecimais do resumo SHA-256.',
                            );

                            return;
                        }

                        setManualBusy(true);
                        router.post(
                            manual.action,
                            { sha256: clean },
                            {
                                preserveScroll: true,
                                onFinish: () => setManualBusy(false),
                            },
                        );
                    }}
                    startOpen={support !== 'ok'}
                />
            )}
        </section>
    );
}

/**
 * Caminho alternativo: o usuário calcula o resumo com uma ferramenta própria e o
 * digita aqui. Só o resumo trafega — e a tela diz isso, porque a promessa de que
 * "o arquivo não sai do navegador" continua valendo para o caminho principal e
 * não pode ser confundida com este.
 */
function ManualHashCheck({
    manual,
    targets,
    value,
    onChange,
    onSubmit,
    error,
    busy,
    startOpen,
}: {
    manual: FileCheckManual;
    targets: FileCheckTarget[];
    value: string;
    onChange: (value: string) => void;
    onSubmit: (event: FormEvent) => void;
    error: string | null;
    busy: boolean;
    startOpen: boolean;
}) {
    const result = manual.result;
    const matched =
        result && result.matches !== 'none'
            ? (targets.find((target) =>
                  result.matches === 'signed'
                      ? target.canonical
                      : !target.canonical,
              ) ?? null)
            : null;

    return (
        <details className="mt-4" open={startOpen || result !== null}>
            <summary className="text-primary cursor-pointer text-[12.5px] font-semibold">
                Já calculei o resumo com outra ferramenta
            </summary>
            <p className="text-muted-foreground mt-2 text-[12px] leading-[1.5]">
                Use{' '}
                <code className="bg-muted rounded px-1 font-mono">
                    certutil -hashfile arquivo.pdf SHA256
                </code>{' '}
                (Windows),{' '}
                <code className="bg-muted rounded px-1 font-mono">
                    sha256sum arquivo.pdf
                </code>{' '}
                (Linux) ou{' '}
                <code className="bg-muted rounded px-1 font-mono">
                    shasum -a 256 arquivo.pdf
                </code>{' '}
                (macOS) e cole o resultado. Neste caminho o{' '}
                <b>resumo é enviado ao servidor</b> para comparação — o arquivo,
                não.
            </p>
            <form onSubmit={onSubmit} className="mt-2 flex flex-wrap gap-2">
                <Input
                    value={value}
                    onChange={(event) => onChange(event.target.value)}
                    placeholder="64 caracteres hexadecimais"
                    autoComplete="off"
                    spellCheck={false}
                    aria-label="Resumo SHA-256 do arquivo"
                    aria-invalid={Boolean(error)}
                    className="h-9 min-w-0 flex-1 font-mono text-[12.5px]"
                />
                <Button
                    type="submit"
                    variant="outline"
                    size="sm"
                    disabled={busy}
                >
                    Comparar
                </Button>
            </form>
            {error && <p className="text-danger mt-1.5 text-[12px]">{error}</p>}
            {result && (
                <p
                    className={cn(
                        'mt-2 rounded-[10px] border p-3 text-[12.5px] leading-[1.5]',
                        result.matches === 'signed'
                            ? 'border-success-border bg-success-bg text-success'
                            : result.matches === 'original'
                              ? 'border-warning-border bg-warning-bg text-warning'
                              : 'border-danger-border bg-danger-bg text-danger',
                    )}
                >
                    {result.matches === 'signed' && (
                        <>
                            <b>Confere.</b> O resumo informado é o do arquivo
                            final registrado.
                        </>
                    )}
                    {result.matches === 'original' && (
                        <>
                            <b>
                                Confere com o{' '}
                                {matched?.label ?? 'documento enviado'}.
                            </b>{' '}
                            Não é o arquivo final publicado nesta página.
                        </>
                    )}
                    {result.matches === 'none' && (
                        <>
                            <b>Não confere.</b> O resumo informado não
                            corresponde a nenhum dos resumos registrados para
                            este documento.
                        </>
                    )}
                </p>
            )}
        </details>
    );
}
