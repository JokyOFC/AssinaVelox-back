import { Camera, ImageUp, RotateCcw, ShieldCheck, Upload } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import type { IdentityCaptureItem } from '@/types/models';

/** Lado máximo da imagem gerada pela câmera (o servidor reduz de novo a 1600 px). */
const CAMERA_MAX_SIDE = 1600;

type Stage = 'intro' | 'starting' | 'camera' | 'preview' | 'uploading';

export interface CapturedImage {
    blob: Blob;
    source: 'camera' | 'upload';
}

function cameraErrorMessage(error: unknown): string {
    const name = error instanceof DOMException ? error.name : '';

    switch (name) {
        case 'NotAllowedError':
        case 'SecurityError':
            return 'A permissão da câmera foi negada. Você pode liberar o acesso nas configurações do navegador ou enviar uma foto do seu aparelho.';
        case 'NotFoundError':
        case 'OverconstrainedError':
            return 'Nenhuma câmera foi encontrada neste aparelho. Envie uma foto do seu aparelho.';
        case 'NotReadableError':
            return 'A câmera está em uso por outro aplicativo. Feche-o e tente de novo, ou envie uma foto.';
        default:
            return 'Não foi possível abrir a câmera. Envie uma foto do seu aparelho.';
    }
}

/**
 * Captura de UMA foto (Fase 2 §2.10): câmera com `getUserMedia` e, sempre, a alternativa de
 * escolher um arquivo. A permissão é explicada ANTES de o navegador perguntar, e nada é
 * enviado sem a pessoa conferir a pré-visualização e tocar em "Usar esta foto".
 *
 * A imagem é só uma foto enviada pela pessoa: não há comparação de rostos, análise da
 * imagem nem leitura do documento. A pré-visualização da câmera frontal é espelhada (como
 * um espelho), mas a foto enviada não é.
 */
export function CameraCaptureDialog({
    item,
    open,
    onOpenChange,
    accept,
    maxUploadKb,
    uploading,
    uploadError,
    onConfirm,
}: {
    item: IdentityCaptureItem;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    accept: string[];
    maxUploadKb: number;
    uploading: boolean;
    uploadError: string | null;
    onConfirm: (image: CapturedImage) => void;
}) {
    const videoRef = useRef<HTMLVideoElement | null>(null);
    const streamRef = useRef<MediaStream | null>(null);
    const fileRef = useRef<HTMLInputElement | null>(null);
    const [stage, setStage] = useState<Stage>('intro');
    const [error, setError] = useState<string | null>(null);
    const [image, setImage] = useState<CapturedImage | null>(null);
    const [previewUrl, setPreviewUrl] = useState<string | null>(null);

    const cameraSupported =
        typeof navigator !== 'undefined' &&
        typeof navigator.mediaDevices?.getUserMedia === 'function';

    const stopCamera = useCallback(() => {
        streamRef.current?.getTracks().forEach((track) => track.stop());
        streamRef.current = null;

        if (videoRef.current) {
            videoRef.current.srcObject = null;
        }
    }, []);

    const reset = useCallback(() => {
        stopCamera();
        setImage(null);
        setError(null);
        setStage('intro');
    }, [stopCamera]);

    // A câmera nunca fica ligada fora do diálogo.
    useEffect(() => {
        if (!open) {
            reset();
        }
    }, [open, reset]);

    useEffect(() => stopCamera, [stopCamera]);

    useEffect(() => {
        if (!image) {
            setPreviewUrl(null);

            return;
        }

        const url = URL.createObjectURL(image.blob);
        setPreviewUrl(url);

        return () => URL.revokeObjectURL(url);
    }, [image]);

    useEffect(() => {
        setStage((current) =>
            uploading
                ? 'uploading'
                : current === 'uploading'
                  ? 'preview'
                  : current,
        );
    }, [uploading]);

    const startCamera = async () => {
        setError(null);
        setStage('starting');

        try {
            const stream = await navigator.mediaDevices.getUserMedia({
                video: {
                    facingMode: { ideal: item.facing_mode },
                    width: { ideal: 1920 },
                    height: { ideal: 1080 },
                },
                audio: false,
            });

            streamRef.current = stream;
            setStage('camera');

            // O elemento de vídeo só existe depois do render da etapa `camera`.
            window.requestAnimationFrame(() => {
                if (videoRef.current) {
                    videoRef.current.srcObject = stream;
                    void videoRef.current.play().catch(() => undefined);
                }
            });
        } catch (exception) {
            stopCamera();
            setError(cameraErrorMessage(exception));
            setStage('intro');
        }
    };

    const takePhoto = () => {
        const video = videoRef.current;

        if (!video || video.videoWidth === 0) {
            setError('A câmera ainda está abrindo. Aguarde um instante.');

            return;
        }

        const scale = Math.min(
            1,
            CAMERA_MAX_SIDE / Math.max(video.videoWidth, video.videoHeight),
        );
        const canvas = document.createElement('canvas');
        canvas.width = Math.round(video.videoWidth * scale);
        canvas.height = Math.round(video.videoHeight * scale);
        canvas
            .getContext('2d')
            ?.drawImage(video, 0, 0, canvas.width, canvas.height);

        canvas.toBlob(
            (blob) => {
                if (!blob) {
                    setError('Não foi possível gerar a foto. Tente de novo.');

                    return;
                }

                stopCamera();
                setImage({ blob, source: 'camera' });
                setStage('preview');
            },
            'image/jpeg',
            0.9,
        );
    };

    const pickFile = (file: File | undefined) => {
        if (!file) {
            return;
        }

        if (!accept.includes(file.type)) {
            setError('Envie uma foto em JPEG ou PNG.');

            return;
        }

        if (file.size > maxUploadKb * 1024) {
            setError(
                `A foto é maior que ${Math.max(1, Math.floor(maxUploadKb / 1024))} MB.`,
            );

            return;
        }

        stopCamera();
        setError(null);
        setImage({ blob: file, source: 'upload' });
        setStage('preview');
    };

    const shownError = uploadError ?? error;

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => !uploading && onOpenChange(next)}
        >
            <DialogContent className="max-h-[92svh] overflow-y-auto sm:max-w-[520px]">
                <DialogHeader>
                    <DialogTitle>{item.label}</DialogTitle>
                    <DialogDescription>{item.instructions}</DialogDescription>
                </DialogHeader>

                {stage === 'intro' && (
                    <div className="flex flex-col gap-3">
                        <p className="border-border bg-sidebar text-text-secondary flex items-start gap-2 rounded-[10px] border p-3 text-[12.5px] leading-[1.5]">
                            <ShieldCheck className="text-primary mt-0.5 size-4 shrink-0" />
                            <span>
                                Ao tocar em <b>Abrir câmera</b>, o navegador
                                pede permissão para usar a câmera deste
                                aparelho. A câmera só fica ligada nesta janela e
                                a foto só é enviada depois que você conferir e
                                confirmar.
                            </span>
                        </p>
                        <div className="flex flex-col gap-2 sm:flex-row">
                            {cameraSupported && (
                                <Button
                                    type="button"
                                    size="lg"
                                    className="flex-1"
                                    onClick={() => void startCamera()}
                                >
                                    <Camera className="size-4" />
                                    Abrir câmera
                                </Button>
                            )}
                            <Button
                                type="button"
                                size="lg"
                                variant="outline"
                                className="flex-1"
                                onClick={() => fileRef.current?.click()}
                            >
                                <ImageUp className="size-4" />
                                Escolher foto do aparelho
                            </Button>
                        </div>
                        {!cameraSupported && (
                            <p className="text-muted-foreground text-[12px]">
                                Este navegador não permite usar a câmera nesta
                                página. Escolha uma foto do seu aparelho.
                            </p>
                        )}
                    </div>
                )}

                {stage === 'starting' && (
                    <div className="text-text-secondary flex items-center justify-center gap-2 py-10 text-[13px]">
                        <Spinner className="size-4" />
                        Aguardando a permissão da câmera…
                    </div>
                )}

                {stage === 'camera' && (
                    <div className="flex flex-col gap-3">
                        <div className="overflow-hidden rounded-xl bg-black">
                            <video
                                ref={videoRef}
                                playsInline
                                muted
                                autoPlay
                                aria-label="Imagem da câmera"
                                className="aspect-[4/3] w-full object-contain"
                                style={
                                    item.facing_mode === 'user'
                                        ? { transform: 'scaleX(-1)' }
                                        : undefined
                                }
                            />
                        </div>
                        <div className="flex flex-col gap-2 sm:flex-row">
                            <Button
                                type="button"
                                size="lg"
                                className="flex-1"
                                onClick={takePhoto}
                            >
                                <Camera className="size-4" />
                                Tirar foto
                            </Button>
                            <Button
                                type="button"
                                size="lg"
                                variant="outline"
                                onClick={reset}
                            >
                                Cancelar
                            </Button>
                        </div>
                    </div>
                )}

                {(stage === 'preview' || stage === 'uploading') &&
                    previewUrl && (
                        <div className="flex flex-col gap-3">
                            <img
                                src={previewUrl}
                                alt={`Pré-visualização: ${item.label}`}
                                className="border-border max-h-[50svh] w-full rounded-xl border bg-black object-contain"
                            />
                            <p className="text-muted-foreground text-[12px] leading-[1.5]">
                                Confira se a foto está nítida e sem cortes antes
                                de enviar.
                            </p>
                            <div className="flex flex-col gap-2 sm:flex-row">
                                <Button
                                    type="button"
                                    size="lg"
                                    className="flex-1"
                                    disabled={stage === 'uploading'}
                                    onClick={() => image && onConfirm(image)}
                                >
                                    {stage === 'uploading' ? (
                                        <Spinner className="size-4" />
                                    ) : (
                                        <Upload className="size-4" />
                                    )}
                                    Usar esta foto
                                </Button>
                                <Button
                                    type="button"
                                    size="lg"
                                    variant="outline"
                                    disabled={stage === 'uploading'}
                                    onClick={reset}
                                >
                                    <RotateCcw className="size-4" />
                                    Refazer
                                </Button>
                            </div>
                        </div>
                    )}

                {shownError && (
                    <p
                        role="alert"
                        className="text-danger text-[12.5px] leading-[1.5]"
                    >
                        {shownError}
                    </p>
                )}

                <input
                    ref={fileRef}
                    type="file"
                    accept={accept.join(',')}
                    className="hidden"
                    onChange={(event) => {
                        pickFile(event.target.files?.[0]);
                        event.target.value = '';
                    }}
                />
            </DialogContent>
        </Dialog>
    );
}
