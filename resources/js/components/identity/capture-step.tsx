import { Camera, CheckCircle2, CircleDashed, Info } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import {
    CameraCaptureDialog,
    type CapturedImage,
} from '@/components/identity/camera-capture-dialog';
import { postJson } from '@/components/identity/http';
import { Button } from '@/components/ui/button';
import { formatDateTime, plural } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { IdentityCaptureItem, IdentityCaptureStep } from '@/types/models';

interface UploadResponse {
    identity_capture?: IdentityCaptureStep | null;
    message?: string;
    errors?: { image?: string[] };
    code?: string;
}

/** Texto próprio da tela, somado ao `notice` do servidor. */
export const CAPTURE_EVIDENCE_NOTE =
    'A imagem é guardada como evidência do seu aceite e não é verificação de identidade.';

/**
 * Etapa "Fotos para o registro do aceite" (Fase 2 §2.10, prop `identity_capture`).
 *
 * Cada foto exigida pelo remetente é tirada com a câmera ou escolhida do aparelho e enviada
 * na hora (`POST sign.capture.store`, multipart). A resposta traz o bloco atualizado, que
 * substitui o local. O aceite só habilita com `complete` — o servidor exige o mesmo.
 */
export function CaptureStepCard({
    step,
    onChange,
    className,
}: {
    step: IdentityCaptureStep;
    onChange: (next: IdentityCaptureStep) => void;
    className?: string;
}) {
    const [active, setActive] = useState<IdentityCaptureItem | null>(null);
    const [uploading, setUploading] = useState(false);
    const [uploadError, setUploadError] = useState<string | null>(null);

    const done = step.items.filter((item) => item.captured).length;

    const upload = async (item: IdentityCaptureItem, image: CapturedImage) => {
        if (!item.upload_url) {
            setUploadError('Confirme o código antes de enviar fotos.');

            return;
        }

        const form = new FormData();
        const extension = image.blob.type === 'image/png' ? 'png' : 'jpg';
        form.append('image', image.blob, `${item.kind}.${extension}`);
        form.append('source', image.source);

        setUploading(true);
        setUploadError(null);

        const response = await postJson<UploadResponse>(item.upload_url, form, {
            timeoutMs: 90000,
        });

        setUploading(false);

        if (response.ok && response.body?.identity_capture) {
            onChange(response.body.identity_capture);
            setActive(null);
            toast.success(`${item.label} registrada.`);

            return;
        }

        if (response.status === 0) {
            // Tempo esgotado ou rede: não sabemos se chegou (T5). Reenviar substitui a anterior.
            setUploadError(
                'Não recebemos a confirmação do envio. Verifique a conexão e toque em “Usar esta foto” de novo — se a foto anterior tiver chegado, ela é substituída.',
            );

            return;
        }

        if (response.status === 404) {
            setUploadError(
                'Esta foto não é mais pedida para você. Recarregue a página.',
            );

            return;
        }

        if (response.status === 419 || response.status === 401) {
            setUploadError(
                'Sua sessão expirou. Recarregue a página e confirme o código de novo.',
            );

            return;
        }

        setUploadError(
            response.body?.errors?.image?.[0] ??
                response.body?.message ??
                'Não foi possível enviar a foto. Tente de novo.',
        );
    };

    return (
        <section
            className={cn('flex flex-col gap-3', className)}
            aria-labelledby="capture-step-title"
        >
            <div className="flex items-center justify-between gap-2">
                <h2
                    id="capture-step-title"
                    className="flex items-center gap-1.5 text-[13.5px] font-semibold"
                >
                    <Camera className="text-primary size-3.5" />
                    {step.title}
                </h2>
                <span className="text-muted-foreground tabular text-[12px]">
                    {done} de {step.items.length}
                </span>
            </div>

            <p className="border-border bg-sidebar text-text-secondary flex items-start gap-2 rounded-[10px] border p-3 text-[12.5px] leading-[1.5]">
                <Info className="text-primary mt-0.5 size-3.5 shrink-0" />
                <span>
                    {CAPTURE_EVIDENCE_NOTE} {step.notice}
                    {step.retention_days > 0
                        ? ` As fotos ficam guardadas por até ${plural(step.retention_days, 'dia')}.`
                        : ' As fotos ficam guardadas enquanto o documento existir na conta de quem enviou.'}
                </span>
            </p>

            <ul className="flex flex-col gap-2">
                {step.items.map((item) => (
                    <li
                        key={item.kind}
                        className={cn(
                            'flex flex-wrap items-center gap-3 rounded-[10px] border p-3',
                            item.captured
                                ? 'border-success-border bg-success-bg'
                                : 'border-border',
                        )}
                    >
                        {item.captured ? (
                            <CheckCircle2 className="text-success size-4 shrink-0" />
                        ) : (
                            <CircleDashed className="text-muted-foreground size-4 shrink-0" />
                        )}
                        <span className="min-w-0 flex-1">
                            <span className="block text-[13px] font-semibold">
                                {item.label}
                            </span>
                            <span className="text-muted-foreground block text-[12px] leading-[1.45]">
                                {item.captured
                                    ? `Enviada ${formatDateTime(item.captured_at)}${
                                          item.width && item.height
                                              ? ` · ${item.width}×${item.height}`
                                              : ''
                                      }`
                                    : item.instructions}
                            </span>
                        </span>
                        <Button
                            type="button"
                            size="sm"
                            variant={item.captured ? 'outline' : 'default'}
                            disabled={!item.upload_url}
                            onClick={() => {
                                setUploadError(null);
                                setActive(item);
                            }}
                        >
                            {item.captured ? 'Refazer' : 'Tirar ou enviar foto'}
                        </Button>
                    </li>
                ))}
            </ul>

            {active && (
                <CameraCaptureDialog
                    item={active}
                    open
                    onOpenChange={(open) => {
                        if (!open) {
                            setActive(null);
                            setUploadError(null);
                        }
                    }}
                    accept={step.accept}
                    maxUploadKb={step.max_upload_kb}
                    uploading={uploading}
                    uploadError={uploadError}
                    onConfirm={(image) => void upload(active, image)}
                />
            )}
        </section>
    );
}

/**
 * Aviso na tela de identificação: o que será pedido depois do código. Sem sessão ainda não
 * há como enviar (`upload_url` nulo), então só a lista e o mesmo aviso.
 */
export function CaptureStepPreview({ step }: { step: IdentityCaptureStep }) {
    return (
        <div className="border-border bg-sidebar flex items-start gap-2.5 rounded-[10px] border p-3 text-[12.5px] leading-[1.5]">
            <Camera className="text-primary mt-0.5 size-4 shrink-0" />
            <span className="text-text-secondary">
                Depois do código, você vai enviar:{' '}
                <b className="text-foreground">
                    {step.items.map((item) => item.label).join(', ')}
                </b>
                . {CAPTURE_EVIDENCE_NOTE}
            </span>
        </div>
    );
}
