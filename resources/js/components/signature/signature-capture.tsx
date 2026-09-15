import { Check, ImageUp, PenLine, Type, Upload } from 'lucide-react';
import { useCallback, useEffect, useId, useRef, useState } from 'react';
import { SignaturePadCanvas } from '@/components/signature/signature-pad-canvas';
import {
    INITIALS_MAX_HEIGHT,
    INITIALS_MAX_WIDTH,
    SIGNATURE_MAX_HEIGHT,
    SIGNATURE_MAX_WIDTH,
    SIGNATURE_STYLES,
    type SignatureStyle,
    SignatureImageError,
    UPLOAD_ACCEPTED_MIMES,
    UPLOAD_MAX_BYTES,
    normalizeSignatureCanvas,
    normalizeUploadedImage,
    renderTypedSignature,
    signatureStyle,
    validateSignatureFile,
} from '@/components/signature/signature-image';
import { SegmentedControl } from '@/components/segmented-control';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { type I18n, type MessageKey, useI18n } from '@/i18n';
import { cn } from '@/lib/utils';
import type { SignatureKind } from '@/types/enums';

/**
 * Representação visual capturada (arquitetura §2). **Não é assinatura**: é a
 * imagem que será desenhada no documento. O que vincula a pessoa ao documento
 * é o aceite eletrônico registrado no servidor.
 */
export interface SignatureValue {
    kind: SignatureKind;
    /** PNG com fundo transparente, em data URL. */
    image_base64: string;
    /** Texto digitado (só em `typed`), gravado como evidência. */
    text: string | null;
    /** Estilo usado no modo "Digitar" (só em `typed`). */
    font: string | null;
}

export interface SignatureCaptureOptions {
    draw: boolean;
    type: boolean;
    upload: boolean;
    /** Estilos oferecidos no modo "Digitar" (chaves de `SIGNATURE_STYLES`). */
    fonts?: string[];
}

export interface SignatureCaptureProps {
    variant?: 'signature' | 'initials';
    value: SignatureValue | null;
    onChange: (value: SignatureValue | null) => void;
    /** Nome sugerido no modo "Digitar". */
    defaultText: string;
    options?: SignatureCaptureOptions;
    maxUploadBytes?: number;
    className?: string;
}

const MODE_ICON = {
    drawn: PenLine,
    typed: Type,
    uploaded: ImageUp,
} as const;

const SIZE = {
    signature: {
        maxWidth: SIGNATURE_MAX_WIDTH,
        maxHeight: SIGNATURE_MAX_HEIGHT,
        padHeight: 170,
    },
    initials: {
        maxWidth: INITIALS_MAX_WIDTH,
        maxHeight: INITIALS_MAX_HEIGHT,
        padHeight: 130,
    },
} as const;

/** Textos do quadro por variante (F-I18N: chaves do dicionário). */
function copyFor(variant: 'signature' | 'initials', t: I18n['t']) {
    return {
        typeLabel: t(`signature.${variant}.type_label`),
        uploadLabel: t(`signature.${variant}.upload_label`),
        hint: t(`signature.${variant}.hint`),
        previewLabel: t(`signature.${variant}.preview`),
        aria: t(`signature.${variant}.aria`),
        ...SIZE[variant],
    };
}

/**
 * As mensagens de `signature-image.ts` são escritas em PT-BR. Fora do PT-BR, as
 * conhecidas são trocadas pela tradução; uma desconhecida sai como veio.
 */
const IMAGE_ERRORS: Record<string, MessageKey> = {
    'Este navegador não suporta a captura de assinatura.':
        'signature.error.unsupported_browser',
    'Não foi possível abrir esta imagem. Envie um PNG ou JPG.':
        'signature.error.open',
    'Formato não aceito. Envie uma imagem PNG ou JPG.':
        'signature.error.format',
    'A imagem enviada está vazia.': 'signature.error.empty',
    'A imagem ficou vazia depois de remover o fundo. Desligue "Remover fundo claro" e tente de novo.':
        'signature.error.empty_after_background',
};

function localizeImageError(message: string, i18n: I18n): string {
    if (i18n.isReference) {
        return message;
    }

    const known = IMAGE_ERRORS[message];

    if (known) {
        return i18n.t(known);
    }

    const tooLarge = /^A imagem tem (.+) e o limite é (.+)\.$/.exec(message);

    return tooLarge
        ? i18n.t('signature.error.too_large', {
              size: tooLarge[1],
              limit: tooLarge[2],
          })
        : message;
}

function styleLabel(style: SignatureStyle, t: I18n['t']): string {
    return style.key === 'caveat' || style.key === 'caveat_slanted'
        ? t(`signature.style.${style.key}`)
        : style.label;
}

function availableStyles(fonts?: string[]): readonly SignatureStyle[] {
    if (!fonts || fonts.length === 0) {
        return SIGNATURE_STYLES;
    }

    const matched = SIGNATURE_STYLES.filter((style) =>
        fonts.includes(style.font),
    );

    // O servidor lista famílias que o build não empacota (só a Caveat é
    // carregada, ver `vite.config.ts`). Oferecer uma família ausente daria uma
    // assinatura renderizada em fonte genérica; nesse caso ficamos com o que
    // existe de fato.
    return matched.length > 0 ? matched : SIGNATURE_STYLES;
}

/**
 * Captura da representação visual em três modos (DESIGN §4.20; ROUTES §3.2):
 * desenhar (`signature_pad`), digitar (fonte manuscrita) e enviar imagem.
 *
 * Saída sempre normalizada em `signature-image.ts`: PNG com fundo
 * transparente, recortado no traço. O componente nunca fala em "assinatura
 * digital" — o rótulo é sempre "representação visual" / "sua assinatura".
 */
export function SignatureCapture({
    variant = 'signature',
    value,
    onChange,
    defaultText,
    options = { draw: true, type: true, upload: true },
    maxUploadBytes = UPLOAD_MAX_BYTES,
    className,
}: SignatureCaptureProps) {
    const i18n = useI18n();
    const { t } = i18n;
    const copy = copyFor(variant, t);
    const styles = availableStyles(options.fonts);
    const fieldId = useId();

    const modes = [
        options.draw
            ? { value: 'drawn' as const, label: t('signature.mode.drawn') }
            : null,
        options.type
            ? { value: 'typed' as const, label: t('signature.mode.typed') }
            : null,
        options.upload
            ? {
                  value: 'uploaded' as const,
                  label: t('signature.mode.uploaded'),
              }
            : null,
    ].filter((mode) => mode !== null);

    const [mode, setMode] = useState<SignatureKind>(
        value?.kind ?? modes[0]?.value ?? 'drawn',
    );
    const [text, setText] = useState(value?.text ?? defaultText);
    const [style, setStyle] = useState<SignatureStyle>(
        styles[0] ?? signatureStyle(value?.font),
    );
    const [transparent, setTransparent] = useState(true);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [fileName, setFileName] = useState<string | null>(null);
    const fileRef = useRef<HTMLInputElement | null>(null);

    const emit = useCallback(
        (next: SignatureValue | null) => {
            onChange(next);
        },
        [onChange],
    );

    // Modo "Digitar": redesenha a cada mudança de texto ou estilo.
    useEffect(() => {
        if (mode !== 'typed') {
            return;
        }

        let cancelled = false;

        void renderTypedSignature(text, style, {
            maxWidth: copy.maxWidth,
            maxHeight: copy.maxHeight,
        }).then((image) => {
            if (cancelled) {
                return;
            }

            emit(
                image
                    ? {
                          kind: 'typed',
                          image_base64: image,
                          text: text.trim(),
                          font: style.font,
                      }
                    : null,
            );
        });

        return () => {
            cancelled = true;
        };
    }, [mode, text, style, copy.maxWidth, copy.maxHeight, emit]);

    const changeMode = (next: SignatureKind) => {
        setMode(next);
        setError(null);

        // Trocar de modo descarta a captura anterior: o signatário deve ver
        // exatamente o que será aplicado no documento.
        if (next !== 'typed') {
            emit(null);
        }
    };

    const handleDraw = useCallback(
        (canvas: HTMLCanvasElement | null) => {
            setError(null);

            if (!canvas) {
                emit(null);

                return;
            }

            const image = normalizeSignatureCanvas(canvas, {
                maxWidth: copy.maxWidth,
                maxHeight: copy.maxHeight,
            });

            emit(
                image
                    ? {
                          kind: 'drawn',
                          image_base64: image,
                          text: null,
                          font: null,
                      }
                    : null,
            );
        },
        [copy.maxWidth, copy.maxHeight, emit],
    );

    const handleFile = (
        file: File | null | undefined,
        keepBackground: boolean,
    ) => {
        if (!file) {
            return;
        }

        const invalid = validateSignatureFile(file, maxUploadBytes);

        if (invalid) {
            setError(localizeImageError(invalid, i18n));
            setFileName(null);
            emit(null);

            return;
        }

        setBusy(true);
        setError(null);
        setFileName(file.name);

        void normalizeUploadedImage(file, {
            maxWidth: copy.maxWidth,
            maxHeight: copy.maxHeight,
            transparentBackground: !keepBackground,
        })
            .then((image) => {
                emit({
                    kind: 'uploaded',
                    image_base64: image,
                    text: null,
                    font: null,
                });
            })
            .catch((cause: unknown) => {
                setError(
                    cause instanceof SignatureImageError
                        ? localizeImageError(cause.message, i18n)
                        : t('signature.prepare_failed'),
                );
                emit(null);
            })
            .finally(() => setBusy(false));
    };

    return (
        <div className={cn('flex flex-col gap-3', className)}>
            {modes.length > 1 && (
                <SegmentedControl
                    value={mode}
                    onChange={changeMode}
                    ariaLabel={t('signature.mode_aria')}
                    className="w-full"
                    options={modes.map((item) => {
                        const Icon = MODE_ICON[item.value];

                        return {
                            value: item.value,
                            label: (
                                <span className="flex flex-1 items-center justify-center gap-1.5">
                                    <Icon className="size-3.5" />
                                    {item.label}
                                </span>
                            ),
                        };
                    })}
                />
            )}

            {mode === 'drawn' && (
                <SignaturePadCanvas
                    height={copy.padHeight}
                    hint={copy.hint}
                    ariaLabel={copy.aria}
                    onChange={handleDraw}
                />
            )}

            {mode === 'typed' && (
                <div className="flex flex-col gap-2.5">
                    <Label htmlFor={`${fieldId}-text`} className="sr-only">
                        {copy.typeLabel}
                    </Label>
                    <Input
                        id={`${fieldId}-text`}
                        value={text}
                        onChange={(event) => setText(event.target.value)}
                        maxLength={variant === 'initials' ? 8 : 80}
                        autoComplete="off"
                        placeholder={copy.typeLabel}
                    />
                    {styles.length > 1 && (
                        <div className="flex flex-wrap gap-1.5">
                            {styles.map((item) => (
                                <button
                                    key={item.key}
                                    type="button"
                                    onClick={() => setStyle(item)}
                                    aria-pressed={item.key === style.key}
                                    className={cn(
                                        'rounded-lg border px-2.5 py-1 text-[12px] transition-colors',
                                        item.key === style.key
                                            ? 'border-primary bg-primary-soft text-primary font-semibold'
                                            : 'border-input text-text-secondary hover:border-primary',
                                    )}
                                >
                                    {styleLabel(item, t)}
                                </button>
                            ))}
                        </div>
                    )}
                    <div
                        className="border-border bg-background flex items-center justify-center overflow-hidden rounded-[10px] border px-4"
                        style={{ height: copy.padHeight - 40 }}
                    >
                        <span
                            className="truncate text-[40px] leading-none"
                            style={{
                                fontFamily: style.fontFamily,
                                fontWeight: style.weight,
                                transform:
                                    style.slant === 0
                                        ? undefined
                                        : `skewX(-${style.slant}deg)`,
                                color: '#0b1f42',
                            }}
                        >
                            {text.trim() === '' ? copy.typeLabel : text}
                        </span>
                    </div>
                </div>
            )}

            {mode === 'uploaded' && (
                <div className="flex flex-col gap-2.5">
                    <input
                        ref={fileRef}
                        type="file"
                        accept={UPLOAD_ACCEPTED_MIMES.join(',')}
                        className="sr-only"
                        onChange={(event) =>
                            handleFile(event.target.files?.[0], !transparent)
                        }
                    />
                    <Button
                        type="button"
                        variant="dashed"
                        size="lg"
                        className="h-auto flex-col gap-1 py-5"
                        onClick={() => fileRef.current?.click()}
                    >
                        <Upload className="size-4" />
                        <span className="text-[13px] font-semibold">
                            {fileName ?? copy.uploadLabel}
                        </span>
                        <span className="text-muted-foreground text-[11.5px] font-normal">
                            {t('signature.upload_hint', {
                                mb: Math.round(maxUploadBytes / (1024 * 1024)),
                            })}
                        </span>
                    </Button>
                    <label className="text-text-secondary flex items-center gap-2 text-[12.5px]">
                        <input
                            type="checkbox"
                            checked={transparent}
                            onChange={(event) => {
                                setTransparent(event.target.checked);
                                handleFile(
                                    fileRef.current?.files?.[0],
                                    !event.target.checked,
                                );
                            }}
                            className="accent-primary size-4"
                        />
                        {t('signature.remove_background')}
                    </label>
                </div>
            )}

            {busy && (
                <p className="text-muted-foreground flex items-center gap-2 text-[12.5px]">
                    <Spinner className="size-3.5" />
                    {t('signature.preparing')}
                </p>
            )}

            {error && (
                <p role="alert" className="text-danger text-[12.5px]">
                    {error}
                </p>
            )}

            {value && mode !== 'typed' && (
                <div className="border-success-border bg-success-bg flex items-center gap-3 rounded-[10px] border p-2.5">
                    <Check className="text-success size-4 shrink-0" />
                    <img
                        src={value.image_base64}
                        alt={copy.previewLabel}
                        className="max-h-12 max-w-[60%] object-contain"
                    />
                    <span className="text-success ml-auto text-[12px] font-semibold">
                        {t('signature.ready')}
                    </span>
                </div>
            )}
        </div>
    );
}
