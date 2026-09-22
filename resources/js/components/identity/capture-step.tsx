import { Camera, CheckCircle2, CircleDashed, Info } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import {
    CameraCaptureDialog,
    type CapturedImage,
} from '@/components/identity/camera-capture-dialog';
import { postJson } from '@/components/identity/http';
import type { IdentityVerificationStep } from '@/components/identity/verification-types';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/i18n';
import { cn } from '@/lib/utils';
import type { IdentityCaptureItem, IdentityCaptureStep } from '@/types/models';

interface UploadResponse {
    identity_capture?: IdentityCaptureStep | null;
    /**
     * Fase 4 §4.1: com a verificação facial com documento exigida, cada foto nova também
     * devolve o bloco da etapa (é assim que `captures_complete` e `captures_changed` mudam
     * sem recarregar a página). Ausente quando não se aplica.
     */
    identity_verification?: IdentityVerificationStep | null;
    message?: string;
    errors?: { image?: string[] };
    code?: string;
}

/**
 * Texto próprio da tela, somado ao `notice` do servidor (PT-BR, referência). A tela usa a
 * chave `capture.evidence_note` do dicionário, no idioma da página (F-I18N).
 */
export const CAPTURE_EVIDENCE_NOTE =
    'A imagem é guardada como evidência do seu aceite e não é verificação de identidade.';

/**
 * Etapa "Fotos para o registro do aceite" (Fase 2 §2.10, prop `identity_capture`).
 *
 * Cada foto exigida pelo remetente é tirada com a câmera ou escolhida do aparelho e enviada
 * na hora (`POST sign.capture.store`, multipart). A resposta traz o bloco atualizado, que
 * substitui o local. O aceite só habilita com `complete` — o servidor exige o mesmo.
 *
 * `onVerificationChange` (Fase 4 §4.1): quando a mesma resposta traz o bloco da verificação
 * facial com documento, ele é repassado para a etapa seguinte se atualizar na hora.
 */
export function CaptureStepCard({
    step,
    onChange,
    onVerificationChange,
    className,
}: {
    step: IdentityCaptureStep;
    onChange: (next: IdentityCaptureStep) => void;
    onVerificationChange?: (next: IdentityVerificationStep) => void;
    className?: string;
}) {
    const i18n = useI18n();
    const { t, tp } = i18n;
    const [active, setActive] = useState<IdentityCaptureItem | null>(null);
    const [uploading, setUploading] = useState(false);
    const [uploadError, setUploadError] = useState<string | null>(null);

    const done = step.items.filter((item) => item.captured).length;

    const upload = async (item: IdentityCaptureItem, image: CapturedImage) => {
        if (!item.upload_url) {
            setUploadError(t('capture.confirm_code_first'));

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

            if (response.body.identity_verification) {
                onVerificationChange?.(response.body.identity_verification);
            }

            setActive(null);
            toast.success(t('capture.saved', { label: item.label }));

            return;
        }

        if (response.status === 0) {
            // Tempo esgotado ou rede: não sabemos se chegou (T5). Reenviar substitui a anterior.
            setUploadError(t('capture.error.unknown_delivery'));

            return;
        }

        if (response.status === 404) {
            setUploadError(t('capture.error.not_required'));

            return;
        }

        if (response.status === 419 || response.status === 401) {
            setUploadError(t('common.session_expired'));

            return;
        }

        setUploadError(
            response.body?.errors?.image?.[0] ??
                response.body?.message ??
                t('capture.error.generic'),
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
                    {t('common.progress', {
                        done,
                        total: step.items.length,
                    })}
                </span>
            </div>

            <p className="border-border bg-sidebar text-text-secondary flex items-start gap-2 rounded-[10px] border p-3 text-[12.5px] leading-[1.5]">
                <Info className="text-primary mt-0.5 size-3.5 shrink-0" />
                <span>
                    {t('capture.evidence_note')} {step.notice}
                    {step.retention_days > 0
                        ? tp('capture.retention', step.retention_days)
                        : t('capture.retention_kept')}
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
                                    ? `${t('capture.sent', {
                                          date: i18n.dateTime(item.captured_at),
                                      })}${
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
                            {item.captured
                                ? t('capture.redo')
                                : t('capture.take')}
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
    const { t, rich } = useI18n();

    return (
        <div className="border-border bg-sidebar flex items-start gap-2.5 rounded-[10px] border p-3 text-[12.5px] leading-[1.5]">
            <Camera className="text-primary mt-0.5 size-4 shrink-0" />
            <span className="text-text-secondary">
                {rich('capture.preview', {
                    items: (
                        <b className="text-foreground">
                            {step.items.map((item) => item.label).join(', ')}
                        </b>
                    ),
                    note: t('capture.evidence_note'),
                })}
            </span>
        </div>
    );
}
