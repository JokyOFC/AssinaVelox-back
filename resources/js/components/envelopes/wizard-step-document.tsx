import { Check, FileText, Loader2, Trash2, TriangleAlert } from 'lucide-react';
import {
    DocumentDropzone,
    type DropzoneLimits,
} from '@/components/envelopes/document-dropzone';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Phase2EmptyState } from '@/components/phase2-empty-state';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { formatBytes, formatDateMedium, plural } from '@/lib/format';
import { documentProcessingLabels } from '@/lib/labels';
import { cn } from '@/lib/utils';
import type { EnvelopeDocument, FolderRef } from '@/types/models';

const NO_FOLDER = 'none';

const EXPIRATION_OPTIONS = [7, 15, 30, 45, 60, 90];

function expirationLabel(days: number): string {
    const target = new Date();
    target.setDate(target.getDate() + days);

    return `${days} dias (até ${formatDateMedium(target.toISOString())})`;
}

function fileBadge(document: EnvelopeDocument): string {
    if (document.mime === 'application/pdf') {
        return 'PDF';
    }

    if (document.mime.includes('word')) {
        return 'DOCX';
    }

    if (document.mime.startsWith('image/')) {
        return 'IMG';
    }

    return 'ARQ';
}

export interface WizardMetadata {
    title: string;
    folder_id: string | null;
    expires_in_days: number;
    message: string;
    send_copy_to_all: boolean;
}

/**
 * Passo 1 — Documento (DESIGN §6.5). Dropzone com validação no cliente,
 * cartão do arquivo com o estado do processamento e metadados do envelope.
 */
export function WizardStepDocument({
    document,
    folders,
    limits,
    metadata,
    onMetadataChange,
    onUpload,
    onUploadReject,
    onRemove,
    uploadProgress,
    uploadError,
    errors,
    disabled,
}: {
    document: EnvelopeDocument | null;
    folders: FolderRef[];
    limits: DropzoneLimits;
    metadata: WizardMetadata;
    onMetadataChange: (patch: Partial<WizardMetadata>) => void;
    onUpload: (file: File) => void;
    onUploadReject: (message: string) => void;
    onRemove: () => void;
    uploadProgress: number | null;
    uploadError: string | null;
    errors: Record<string, string>;
    disabled?: boolean;
}) {
    const processing = document?.processing;
    const busy = processing
        ? processing.status === 'uploaded' || processing.status === 'converting'
        : false;
    const failed =
        processing?.status === 'failed' || processing?.status === 'blocked';

    return (
        <div className="flex flex-wrap items-start gap-5">
            <div className="flex min-w-0 flex-[1.3_1_380px] flex-col gap-4">
                <div className="border-border bg-card shadow-card flex flex-col gap-4 rounded-xl border p-5">
                    {!document && (
                        <DocumentDropzone
                            limits={limits}
                            onFile={onUpload}
                            onReject={onUploadReject}
                            disabled={disabled || uploadProgress !== null}
                        />
                    )}

                    {uploadProgress !== null && (
                        <div className="flex flex-col gap-1.5">
                            <div className="text-text-secondary flex justify-between text-[12.5px]">
                                <span>Enviando arquivo…</span>
                                <span className="tabular">
                                    {uploadProgress}%
                                </span>
                            </div>
                            <div
                                role="progressbar"
                                aria-valuemin={0}
                                aria-valuemax={100}
                                aria-valuenow={uploadProgress}
                                className="bg-accent h-2 overflow-hidden rounded-full"
                            >
                                <div
                                    className="bg-primary h-full rounded-full transition-[width]"
                                    style={{ width: `${uploadProgress}%` }}
                                />
                            </div>
                        </div>
                    )}

                    <InputError message={uploadError ?? errors.file} />

                    {document && (
                        <div
                            className={cn(
                                'flex items-center gap-3 rounded-[10px] border p-3',
                                failed
                                    ? 'border-danger-border bg-danger-bg'
                                    : 'border-border',
                            )}
                        >
                            <span className="bg-danger-bg text-danger flex size-9 shrink-0 items-center justify-center rounded-lg text-[10px] font-extrabold">
                                {fileBadge(document)}
                            </span>
                            <span className="min-w-0 flex-1">
                                <span className="block truncate text-[13.5px] font-semibold">
                                    {document.original_name}
                                </span>
                                <span className="text-muted-foreground tabular block text-[12px]">
                                    {formatBytes(document.size_bytes)}
                                    {processing?.pages != null &&
                                        ` · ${plural(processing.pages, 'página')}`}
                                </span>
                            </span>
                            <span
                                className={cn(
                                    'inline-flex shrink-0 items-center gap-1.5 text-[12px] font-semibold',
                                    processing?.status === 'ready' &&
                                        'text-success',
                                    busy && 'text-primary',
                                    failed && 'text-danger',
                                )}
                            >
                                {processing?.status === 'ready' && (
                                    <Check className="size-3.5 stroke-[2.5]" />
                                )}
                                {busy && (
                                    <Loader2 className="size-3.5 animate-spin" />
                                )}
                                {failed && (
                                    <TriangleAlert className="size-3.5" />
                                )}
                                {processing?.label ??
                                    (processing
                                        ? documentProcessingLabels[
                                              processing.status
                                          ]
                                        : '')}
                            </span>
                            <Button
                                variant="ghost"
                                size="icon-xs"
                                aria-label="Remover arquivo"
                                title="Remover"
                                disabled={disabled}
                                onClick={onRemove}
                                className="hover:bg-danger-bg hover:text-danger"
                            >
                                <Trash2 className="size-[15px]" />
                            </Button>
                        </div>
                    )}

                    {failed && processing && (
                        <p className="text-danger text-[12.5px] leading-[1.5]">
                            {processing.error ??
                                (processing.status === 'blocked'
                                    ? 'Este arquivo está protegido por senha ou já contém assinatura digital e não pode receber campos. Remova-o e envie uma versão sem proteção.'
                                    : 'Falha ao processar o arquivo. Remova-o e envie um PDF válido.')}
                        </p>
                    )}
                </div>

                <div className="border-border bg-card shadow-card flex flex-col gap-3.5 rounded-xl border p-5">
                    <Heading variant="small" title="Informações do documento" />

                    <div className="grid gap-1.5">
                        <Label htmlFor="envelope-title">
                            Nome do documento
                        </Label>
                        <Input
                            id="envelope-title"
                            value={metadata.title}
                            maxLength={160}
                            disabled={disabled}
                            aria-invalid={Boolean(errors.title)}
                            onChange={(event) =>
                                onMetadataChange({ title: event.target.value })
                            }
                        />
                        <InputError message={errors.title} />
                    </div>

                    <div
                        className="grid gap-3"
                        style={{
                            gridTemplateColumns:
                                'repeat(auto-fit, minmax(180px, 1fr))',
                        }}
                    >
                        <div className="grid gap-1.5">
                            <Label htmlFor="envelope-folder">Pasta</Label>
                            <Select
                                value={metadata.folder_id ?? NO_FOLDER}
                                disabled={disabled}
                                onValueChange={(value) =>
                                    onMetadataChange({
                                        folder_id:
                                            value === NO_FOLDER ? null : value,
                                    })
                                }
                            >
                                <SelectTrigger
                                    id="envelope-folder"
                                    className="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NO_FOLDER}>
                                        Sem pasta
                                    </SelectItem>
                                    {folders.map((folder) => (
                                        <SelectItem
                                            key={folder.id}
                                            value={folder.id}
                                        >
                                            {folder.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.folder_id} />
                        </div>

                        <div className="grid gap-1.5">
                            <Label htmlFor="envelope-expires">
                                Prazo para assinatura
                            </Label>
                            <Select
                                value={String(metadata.expires_in_days)}
                                disabled={disabled}
                                onValueChange={(value) =>
                                    onMetadataChange({
                                        expires_in_days: Number(value),
                                    })
                                }
                            >
                                <SelectTrigger
                                    id="envelope-expires"
                                    className="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {[
                                        ...new Set([
                                            ...EXPIRATION_OPTIONS,
                                            metadata.expires_in_days,
                                        ]),
                                    ]
                                        .sort((a, b) => a - b)
                                        .map((days) => (
                                            <SelectItem
                                                key={days}
                                                value={String(days)}
                                            >
                                                {expirationLabel(days)}
                                            </SelectItem>
                                        ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.expires_in_days} />
                        </div>
                    </div>

                    <label className="border-border flex items-center justify-between gap-3 rounded-[10px] border p-3.5">
                        <span>
                            <span className="flex items-center gap-2 text-[13.5px] font-semibold">
                                Lembretes automáticos
                                <Badge variant="phase">Fase 2</Badge>
                            </span>
                            <span className="text-muted-foreground block text-[12.5px]">
                                Reenvie convites manualmente pelo detalhe do
                                documento.
                            </span>
                        </span>
                        <Switch
                            checked={false}
                            disabled
                            aria-label="Lembretes automáticos (Fase 2)"
                        />
                    </label>
                </div>
            </div>

            <div className="min-w-0 flex-[1_1_280px]">
                <Phase2EmptyState
                    title="Ou comece por um modelo"
                    description="Modelos guardam campos e signatários já configurados para enviar em segundos."
                />
                <p className="text-muted-foreground mt-3 flex items-center gap-2 text-[12.5px]">
                    <FileText className="size-3.5" />
                    Um documento por solicitação nesta fase.
                </p>
            </div>
        </div>
    );
}
