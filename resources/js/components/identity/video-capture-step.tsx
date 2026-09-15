import {
    CheckCircle2,
    CircleDashed,
    Info,
    RotateCcw,
    Square,
    Upload,
    Video,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { postJson } from '@/components/identity/http';
import type { IdentityVideoStep } from '@/components/identity/video-types';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { type I18n, useI18n } from '@/i18n';
import { cn } from '@/lib/utils';

/** Tipos tentados no MediaRecorder, na ordem. O servidor aceita WebM/Matroska e MP4. */
const MIME_CANDIDATES = [
    'video/webm;codecs=vp9',
    'video/webm;codecs=vp8',
    'video/webm',
    'video/mp4;codecs=avc1',
    'video/mp4',
];

type Stage =
    | 'intro'
    | 'starting'
    | 'ready'
    | 'recording'
    | 'review'
    | 'uploading';

export interface RecordedClip {
    blob: Blob;
    source: 'camera' | 'upload';
    /** Medida pelo navegador durante a gravação; `null` para arquivo escolhido. */
    durationMs: number | null;
}

interface UploadResponse {
    identity_video?: IdentityVideoStep | null;
    message?: string;
    errors?: { video?: string[]; consent?: string[] };
    code?: string;
}

function recorderSupported(): boolean {
    return (
        typeof window !== 'undefined' &&
        typeof window.MediaRecorder !== 'undefined' &&
        typeof navigator !== 'undefined' &&
        typeof navigator.mediaDevices?.getUserMedia === 'function'
    );
}

function pickMimeType(): string | undefined {
    if (typeof MediaRecorder.isTypeSupported !== 'function') {
        return undefined;
    }

    return MIME_CANDIDATES.find((type) => MediaRecorder.isTypeSupported(type));
}

function cameraErrorMessage(error: unknown, t: I18n['t']): string {
    const name = error instanceof DOMException ? error.name : '';

    switch (name) {
        case 'NotAllowedError':
        case 'SecurityError':
            return t('video.error.denied');
        case 'NotFoundError':
        case 'OverconstrainedError':
            return t('video.error.not_found');
        case 'NotReadableError':
            return t('video.error.busy');
        default:
            return t('video.error.generic');
    }
}

/** "3,5 s" em PT-BR — usado também pelo painel do remetente (`identity-video-panel`). */
export function formatSeconds(ms: number | null | undefined): string {
    if (ms === null || ms === undefined) {
        return '';
    }

    return `${(ms / 1000).toLocaleString('pt-BR', {
        minimumFractionDigits: 1,
        maximumFractionDigits: 1,
    })} s`;
}

/**
 * Estado da etapa de vídeo na página pública: o bloco do servidor, substituído pela resposta
 * de cada envio. `ready` libera o aceite (o servidor exige o mesmo).
 */
export function useVideoStep(initial: IdentityVideoStep | null | undefined) {
    const [step, setStep] = useState<IdentityVideoStep | null>(initial ?? null);

    useEffect(() => {
        setStep(initial ?? null);
    }, [initial]);

    return { step, setStep, ready: step === null || step.complete };
}

/**
 * Etapa "Vídeo curto para o registro do aceite" (Fase 3 §3.3, prop `identity_video`).
 *
 * Diz para que serve, quem vê e por quanto tempo fica guardado. A gravação (sem som) só
 * começa depois do consentimento marcado; quando o navegador não grava ou a câmera é negada, a
 * alternativa é enviar um vídeo gravado pelo aparelho ou abrir o link em outro aparelho.
 *
 * F-I18N: os textos do servidor chegam no idioma da página; a autorização exibida em outro
 * idioma é tradução de cortesia (a versão registrada é o hash do texto de referência).
 */
export function VideoCaptureStepCard({
    step,
    onChange,
    className,
}: {
    step: IdentityVideoStep;
    onChange: (next: IdentityVideoStep) => void;
    className?: string;
}) {
    const i18n = useI18n();
    const { t, tp } = i18n;
    const [open, setOpen] = useState(false);
    const [uploading, setUploading] = useState(false);
    const [uploadError, setUploadError] = useState<string | null>(null);

    const upload = async (clip: RecordedClip) => {
        if (!step.upload_url) {
            setUploadError(t('video.confirm_code_first'));

            return;
        }

        const form = new FormData();
        const extension = clip.blob.type.includes('mp4') ? 'mp4' : 'webm';
        form.append('video', clip.blob, `video.${extension}`);
        form.append('consent', '1');
        form.append('source', clip.source);

        if (clip.durationMs !== null) {
            form.append('duration_ms', String(Math.round(clip.durationMs)));
        }

        setUploading(true);
        setUploadError(null);

        const response = await postJson<UploadResponse>(step.upload_url, form, {
            timeoutMs: 120000,
        });

        setUploading(false);

        if (response.ok && response.body?.identity_video) {
            onChange(response.body.identity_video);
            setOpen(false);
            toast.success(t('video.saved'));

            return;
        }

        if (response.status === 0) {
            // Tempo esgotado ou rede: não sabemos se chegou (T5). Reenviar substitui o anterior.
            setUploadError(t('video.error.unknown_delivery'));

            return;
        }

        if (response.status === 404) {
            setUploadError(t('video.error.not_required'));

            return;
        }

        if (response.status === 419 || response.status === 401) {
            setUploadError(t('common.session_expired'));

            return;
        }

        setUploadError(
            response.body?.errors?.video?.[0] ??
                response.body?.errors?.consent?.[0] ??
                response.body?.message ??
                t('video.error.upload'),
        );
    };

    return (
        <section
            className={cn('flex flex-col gap-3', className)}
            aria-labelledby="video-step-title"
        >
            <h2
                id="video-step-title"
                className="flex items-center gap-1.5 text-[13.5px] font-semibold"
            >
                <Video className="text-primary size-3.5" />
                {step.title}
            </h2>

            <div className="border-border bg-sidebar text-text-secondary flex items-start gap-2 rounded-[10px] border p-3 text-[12.5px] leading-[1.5]">
                <Info className="text-primary mt-0.5 size-3.5 shrink-0" />
                <div className="flex flex-col gap-1.5">
                    <p>{step.purpose}</p>
                    <p>{step.audience}</p>
                    <p>
                        {step.notice}
                        {step.retention_days > 0
                            ? tp('video.retention', step.retention_days)
                            : t('video.retention_kept')}
                    </p>
                </div>
            </div>

            <div
                className={cn(
                    'flex flex-wrap items-center gap-3 rounded-[10px] border p-3',
                    step.complete
                        ? 'border-success-border bg-success-bg'
                        : 'border-border',
                )}
            >
                {step.complete ? (
                    <CheckCircle2 className="text-success size-4 shrink-0" />
                ) : (
                    <CircleDashed className="text-muted-foreground size-4 shrink-0" />
                )}
                <span className="min-w-0 flex-1">
                    <span className="block text-[13px] font-semibold">
                        {step.label}
                    </span>
                    <span className="text-muted-foreground block text-[12px] leading-[1.45]">
                        {step.complete
                            ? `${t('video.sent', {
                                  date: i18n.dateTime(step.captured_at),
                              })}${
                                  step.duration_ms
                                      ? ` · ${i18n.seconds(step.duration_ms)}`
                                      : ''
                              }${step.size_bytes ? ` · ${i18n.bytes(step.size_bytes)}` : ''}`
                            : `${step.instructions} ${tp('video.up_to', step.max_seconds)}`}
                    </span>
                </span>
                <Button
                    type="button"
                    size="sm"
                    variant={step.complete ? 'outline' : 'default'}
                    disabled={!step.upload_url}
                    onClick={() => {
                        setUploadError(null);
                        setOpen(true);
                    }}
                >
                    {step.complete
                        ? t('video.record_again')
                        : t('video.record')}
                </Button>
            </div>

            {open && (
                <VideoRecorderDialog
                    step={step}
                    open
                    onOpenChange={(next) => {
                        if (!next) {
                            setOpen(false);
                            setUploadError(null);
                        }
                    }}
                    uploading={uploading}
                    uploadError={uploadError}
                    onConfirm={(clip) => void upload(clip)}
                />
            )}
        </section>
    );
}

/**
 * Gravação de UM vídeo curto com o MediaRecorder nativo (WebM ou MP4, conforme o navegador),
 * sem som, sem transcodificar. O consentimento vem antes de ligar a câmera; a câmera nunca fica
 * ligada fora do diálogo; a gravação para sozinha no limite de duração.
 */
function VideoRecorderDialog({
    step,
    open,
    onOpenChange,
    uploading,
    uploadError,
    onConfirm,
}: {
    step: IdentityVideoStep;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    uploading: boolean;
    uploadError: string | null;
    onConfirm: (clip: RecordedClip) => void;
}) {
    const i18n = useI18n();
    const { t, tp } = i18n;
    const videoRef = useRef<HTMLVideoElement | null>(null);
    const streamRef = useRef<MediaStream | null>(null);
    const recorderRef = useRef<MediaRecorder | null>(null);
    const chunksRef = useRef<Blob[]>([]);
    const startedAtRef = useRef<number>(0);
    const timersRef = useRef<number[]>([]);
    const discardRef = useRef(false);
    const fileRef = useRef<HTMLInputElement | null>(null);

    const [stage, setStage] = useState<Stage>('intro');
    const [consent, setConsent] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [elapsed, setElapsed] = useState(0);
    const [clip, setClip] = useState<RecordedClip | null>(null);

    const supported = recorderSupported();
    const maxMs = step.max_seconds * 1000;
    const maxBytes = step.max_upload_kb * 1024;
    // F-I18N: `consent_reviewed` só vem com a flag ligada e idioma diferente do PT-BR.
    const consentCourtesy =
        !i18n.isReference &&
        (step as IdentityVideoStep & { consent_reviewed?: boolean })
            .consent_reviewed === false;

    const clearTimers = useCallback(() => {
        timersRef.current.forEach((id) => window.clearTimeout(id));
        timersRef.current = [];
    }, []);

    const stopCamera = useCallback(() => {
        streamRef.current?.getTracks().forEach((track) => track.stop());
        streamRef.current = null;

        if (videoRef.current) {
            videoRef.current.srcObject = null;
        }
    }, []);

    const stopEverything = useCallback(() => {
        clearTimers();
        discardRef.current = true;

        if (recorderRef.current && recorderRef.current.state !== 'inactive') {
            recorderRef.current.stop();
        }

        recorderRef.current = null;
        stopCamera();
    }, [clearTimers, stopCamera]);

    // A câmera nunca fica ligada fora do diálogo.
    useEffect(() => {
        if (!open) {
            stopEverything();
        }
    }, [open, stopEverything]);

    useEffect(() => stopEverything, [stopEverything]);

    useEffect(() => {
        setStage((current) =>
            uploading
                ? 'uploading'
                : current === 'uploading'
                  ? 'review'
                  : current,
        );
    }, [uploading]);

    const startCamera = async () => {
        setError(null);
        setStage('starting');

        try {
            const stream = await navigator.mediaDevices.getUserMedia({
                video: {
                    facingMode: { ideal: step.facing_mode },
                    width: { ideal: 640 },
                    height: { ideal: 480 },
                },
                // Sem som: a página não pede o microfone.
                audio: false,
            });

            streamRef.current = stream;

            if (videoRef.current) {
                videoRef.current.srcObject = stream;
                await videoRef.current.play().catch(() => undefined);
            }

            setStage('ready');
        } catch (reason) {
            stopCamera();
            setError(cameraErrorMessage(reason, t));
            setStage('intro');
        }
    };

    const stopRecording = () => {
        clearTimers();

        if (recorderRef.current && recorderRef.current.state !== 'inactive') {
            recorderRef.current.stop();
        }
    };

    const startRecording = () => {
        const stream = streamRef.current;

        if (!stream) {
            return;
        }

        const mimeType = pickMimeType();
        let recorder: MediaRecorder;

        try {
            recorder = new MediaRecorder(stream, {
                ...(mimeType ? { mimeType } : {}),
                videoBitsPerSecond: step.video_bits_per_second,
            });
        } catch {
            setError(t('video.no_recorder'));

            return;
        }

        chunksRef.current = [];
        discardRef.current = false;
        recorderRef.current = recorder;

        recorder.ondataavailable = (event) => {
            if (event.data && event.data.size > 0) {
                chunksRef.current.push(event.data);
            }
        };

        recorder.onstop = () => {
            const durationMs = performance.now() - startedAtRef.current;
            recorderRef.current = null;
            stopCamera();

            if (discardRef.current) {
                return;
            }

            const blob = new Blob(chunksRef.current, {
                type: recorder.mimeType || mimeType || 'video/webm',
            });

            if (blob.size === 0) {
                setError(t('video.empty'));
                setStage('intro');

                return;
            }

            setClip({
                blob,
                source: 'camera',
                durationMs: Math.min(durationMs, maxMs),
            });
            setStage('review');
        };

        startedAtRef.current = performance.now();
        setElapsed(0);
        recorder.start(250);
        setStage('recording');

        const tick = () => {
            setElapsed(performance.now() - startedAtRef.current);
            timersRef.current.push(window.setTimeout(tick, 200));
        };

        timersRef.current.push(window.setTimeout(tick, 200));
        // Para sozinho no limite (o servidor recusa acima dele).
        timersRef.current.push(window.setTimeout(stopRecording, maxMs));
    };

    const chooseFile = (file: File | undefined) => {
        if (!file) {
            return;
        }

        if (file.size > maxBytes) {
            setError(t('video.too_large', { size: i18n.bytes(maxBytes) }));

            return;
        }

        setError(null);
        setClip({ blob: file, source: 'upload', durationMs: null });
        setStage('review');
    };

    const again = () => {
        setClip(null);
        setError(null);
        setStage('intro');
    };

    const busy = stage === 'uploading';

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => !busy && onOpenChange(next)}
        >
            <DialogContent className="sm:max-w-[520px]">
                <DialogHeader>
                    <DialogTitle>{step.label}</DialogTitle>
                    <DialogDescription>
                        {step.instructions}{' '}
                        {tp('video.up_to', step.max_seconds)}
                    </DialogDescription>
                </DialogHeader>

                {stage === 'intro' && (
                    <div className="flex flex-col gap-3 text-[13px] leading-[1.5]">
                        <p className="text-text-secondary">{step.purpose}</p>
                        <p className="text-text-secondary">{step.audience}</p>
                        {/* O consentimento cita "o prazo informado": o prazo e o aviso de que o
                            vídeo não verifica identidade ficam aqui, junto da caixa. */}
                        <p className="text-text-secondary">
                            {step.notice}
                            {step.retention_days > 0
                                ? tp('video.retention', step.retention_days)
                                : t('video.retention_kept')}
                        </p>

                        <label
                            htmlFor="video-consent"
                            className="border-border flex items-start gap-2.5 rounded-[10px] border p-3"
                        >
                            <Checkbox
                                id="video-consent"
                                checked={consent}
                                onCheckedChange={(value) =>
                                    setConsent(value === true)
                                }
                                className="mt-0.5"
                            />
                            <span>
                                {step.consent_label}
                                {consentCourtesy && (
                                    <span className="text-muted-foreground mt-1 block text-[11.5px]">
                                        {t('video.consent_courtesy')}
                                    </span>
                                )}
                            </span>
                        </label>

                        {!supported && (
                            <p className="border-warning-border bg-warning-bg rounded-[10px] border p-3 text-[12.5px]">
                                {t('video.unsupported')} {step.fallback}
                            </p>
                        )}

                        {error && (
                            <p
                                role="alert"
                                className="text-danger text-[12.5px]"
                            >
                                {error}
                            </p>
                        )}

                        <div className="flex flex-wrap gap-2">
                            {supported && (
                                <Button
                                    type="button"
                                    disabled={!consent}
                                    onClick={() => void startCamera()}
                                >
                                    <Video className="size-4" />
                                    {t('video.camera_on')}
                                </Button>
                            )}
                            <Button
                                type="button"
                                variant="outline"
                                disabled={!consent}
                                onClick={() => fileRef.current?.click()}
                            >
                                <Upload className="size-4" />
                                {t('video.upload')}
                            </Button>
                            <input
                                ref={fileRef}
                                type="file"
                                accept={step.accept.join(',')}
                                capture="user"
                                className="sr-only"
                                tabIndex={-1}
                                onChange={(event) => {
                                    chooseFile(event.target.files?.[0]);
                                    event.target.value = '';
                                }}
                            />
                        </div>

                        {supported && (
                            <p className="text-muted-foreground text-[12px]">
                                {step.fallback}
                            </p>
                        )}
                    </div>
                )}

                {(stage === 'starting' ||
                    stage === 'ready' ||
                    stage === 'recording') && (
                    <div className="flex flex-col gap-3">
                        <div className="bg-muted relative overflow-hidden rounded-[10px]">
                            <video
                                ref={videoRef}
                                muted
                                playsInline
                                className="aspect-[4/3] w-full -scale-x-100 object-cover"
                            />
                            {stage === 'starting' && (
                                <div className="absolute inset-0 flex items-center justify-center">
                                    <Spinner />
                                </div>
                            )}
                            {stage === 'recording' && (
                                <span className="bg-danger tabular absolute top-2 left-2 rounded-full px-2 py-0.5 text-[12px] font-semibold text-white">
                                    {t('video.recording', {
                                        elapsed: i18n.seconds(elapsed),
                                        max: step.max_seconds,
                                    })}
                                </span>
                            )}
                        </div>

                        <div className="flex flex-wrap gap-2">
                            {stage === 'ready' && (
                                <Button type="button" onClick={startRecording}>
                                    <Video className="size-4" />
                                    {t('video.start')}
                                </Button>
                            )}
                            {stage === 'recording' && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={stopRecording}
                                >
                                    <Square className="size-4" />
                                    {t('video.stop')}
                                </Button>
                            )}
                        </div>
                        {error && (
                            <p
                                role="alert"
                                className="text-danger text-[12.5px]"
                            >
                                {error}
                            </p>
                        )}
                    </div>
                )}

                {(stage === 'review' || stage === 'uploading') && clip && (
                    <div className="flex flex-col gap-3 text-[13px] leading-[1.5]">
                        <p className="border-border rounded-[10px] border p-3">
                            <span className="block font-semibold">
                                {clip.source === 'camera'
                                    ? t('video.ready')
                                    : t('video.chosen')}
                            </span>
                            <span className="text-muted-foreground block text-[12.5px]">
                                {[
                                    clip.durationMs !== null
                                        ? i18n.seconds(clip.durationMs)
                                        : null,
                                    i18n.bytes(clip.blob.size),
                                ]
                                    .filter(Boolean)
                                    .join(' · ')}
                            </span>
                        </p>

                        {uploadError && (
                            <p
                                role="alert"
                                className="text-danger text-[12.5px]"
                            >
                                {uploadError}
                            </p>
                        )}

                        <div className="flex flex-wrap gap-2">
                            <Button
                                type="button"
                                disabled={busy}
                                onClick={() => onConfirm(clip)}
                            >
                                {busy ? (
                                    <Spinner />
                                ) : (
                                    <Upload className="size-4" />
                                )}
                                {t('video.send')}
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                disabled={busy}
                                onClick={again}
                            >
                                <RotateCcw className="size-4" />
                                {t('video.record_again')}
                            </Button>
                        </div>
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}
