import { Check, FileText, Loader2, Trash2, TriangleAlert } from 'lucide-react';
import {
    DocumentDropzone,
    type DropzoneLimits,
} from '@/components/envelopes/document-dropzone';
import { RemindersControl } from '@/components/envelopes/reminders-control';
import {
    fileBadge,
    WizardDocumentList,
} from '@/components/envelopes/wizard-document-list';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Phase2EmptyState } from '@/components/phase2-empty-state';
import { TemplatePicker } from '@/components/templates/template-picker';
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
import type {
    EnvelopeDocument,
    EnvelopeReminders,
    FolderRef,
    ReminderSettings,
} from '@/types/models';

const NO_FOLDER = 'none';

const EXPIRATION_OPTIONS = [7, 15, 30, 45, 60, 90];

function expirationLabel(days: number): string {
    const target = new Date();
    target.setDate(target.getDate() + days);

    return `${days} dias (até ${formatDateMedium(target.toISOString())})`;
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
 *
 * Fase 2 (cada bloco só aparece com a sua flag; sem elas o passo é o da Fase 1):
 * - `multiDocument` (§2.3): lista ordenável de arquivos, remoção por arquivo e dropzone
 *   que aceita vários de uma vez, até `maxDocuments`;
 * - `reminders.available` (§2.5): lembretes automáticos reais no lugar do switch "Fase 2";
 * - `templatesEnabled` (§2.1): o cartão "Ou comece por um modelo" vira o seletor de modelos.
 */
export function WizardStepDocument({
    document,
    documents = [],
    multiDocument = false,
    maxDocuments = 1,
    folders,
    limits,
    metadata,
    onMetadataChange,
    onUpload,
    onUploadMany,
    onUploadReject,
    onRemove,
    onRemoveDocument,
    onMoveDocument,
    uploadProgress,
    uploadLabel,
    uploadError,
    errors,
    disabled,
    reminders,
    reminderSettings,
    onRemindersChange,
    templatesEnabled = false,
}: {
    document: EnvelopeDocument | null;
    /** Todos os arquivos (Fase 2); usado só com `multiDocument`. */
    documents?: EnvelopeDocument[];
    multiDocument?: boolean;
    maxDocuments?: number;
    folders: FolderRef[];
    limits: DropzoneLimits;
    metadata: WizardMetadata;
    onMetadataChange: (patch: Partial<WizardMetadata>) => void;
    onUpload: (file: File) => void;
    onUploadMany?: (files: File[]) => void;
    onUploadReject: (message: string) => void;
    onRemove: () => void;
    onRemoveDocument?: (document: EnvelopeDocument) => void;
    onMoveDocument?: (from: number, to: number) => void;
    uploadProgress: number | null;
    /** "Enviando arquivo 2 de 3…" durante uma fila de uploads. */
    uploadLabel?: string | null;
    uploadError: string | null;
    errors: Record<string, string>;
    disabled?: boolean;
    reminders?: EnvelopeReminders | null;
    reminderSettings?: ReminderSettings | null;
    onRemindersChange?: (next: ReminderSettings) => void;
    templatesEnabled?: boolean;
}) {
    const processing = document?.processing;
    const busy = processing
        ? processing.status === 'uploaded' || processing.status === 'converting'
        : false;
    const failed =
        processing?.status === 'failed' || processing?.status === 'blocked';

    const remaining = Math.max(0, maxDocuments - documents.length);
    const showList = multiDocument && documents.length > 0;

    return (
        <div className="flex flex-wrap items-start gap-5">
            <div className="flex min-w-0 flex-[1.3_1_380px] flex-col gap-4">
                <div className="border-border bg-card shadow-card flex flex-col gap-4 rounded-xl border p-5">
                    {showList && (
                        <>
                            <Heading
                                variant="small"
                                title={`Arquivos (${documents.length} de ${maxDocuments})`}
                                description="Os participantes veem os arquivos nesta ordem e conferem todos antes de assinar."
                            />
                            <WizardDocumentList
                                documents={documents}
                                onMove={(from, to) =>
                                    onMoveDocument?.(from, to)
                                }
                                onRemove={(item) => onRemoveDocument?.(item)}
                                disabled={disabled || uploadProgress !== null}
                            />
                            <InputError message={errors.document_order} />
                        </>
                    )}

                    {multiDocument
                        ? remaining > 0 && (
                              <DocumentDropzone
                                  limits={limits}
                                  multiple
                                  maxFiles={remaining}
                                  compact={documents.length > 0}
                                  onFile={onUpload}
                                  onFiles={onUploadMany}
                                  onReject={onUploadReject}
                                  disabled={disabled || uploadProgress !== null}
                              />
                          )
                        : !document && (
                              <DocumentDropzone
                                  limits={limits}
                                  onFile={onUpload}
                                  onReject={onUploadReject}
                                  disabled={disabled || uploadProgress !== null}
                              />
                          )}

                    {multiDocument && remaining === 0 && (
                        <p className="text-muted-foreground text-[12.5px]">
                            Limite de {plural(maxDocuments, 'arquivo')} por
                            documento atingido. Remova um arquivo para enviar
                            outro.
                        </p>
                    )}

                    {uploadProgress !== null && (
                        <div className="flex flex-col gap-1.5">
                            <div className="text-text-secondary flex justify-between text-[12.5px]">
                                <span>
                                    {uploadLabel ?? 'Enviando arquivo…'}
                                </span>
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

                    {!multiDocument && document && (
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

                    {!multiDocument && failed && processing && (
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

                    {reminders?.available &&
                    reminderSettings &&
                    onRemindersChange ? (
                        <RemindersControl
                            reminders={reminders}
                            value={reminderSettings}
                            onChange={onRemindersChange}
                            errors={errors}
                            disabled={disabled}
                        />
                    ) : (
                        <label className="border-border flex items-center justify-between gap-3 rounded-[10px] border p-3.5">
                            <span>
                                <span className="flex items-center gap-2 text-[13.5px] font-semibold">
                                    Lembretes automáticos
                                    <Badge variant="phase">Não ativado</Badge>
                                </span>
                                <span className="text-muted-foreground block text-[12.5px]">
                                    Reenvie convites manualmente pelo detalhe do
                                    documento.
                                </span>
                            </span>
                            <Switch
                                checked={false}
                                disabled
                                aria-label="Lembretes automáticos (não ativado)"
                            />
                        </label>
                    )}
                </div>
            </div>

            <div className="min-w-0 flex-[1_1_280px]">
                {templatesEnabled ? (
                    <div className="border-border bg-card shadow-card flex flex-col gap-3 rounded-xl border p-5">
                        <Heading
                            variant="small"
                            title="Ou comece por um modelo"
                            description="Campos e participantes já configurados."
                        />
                        <TemplatePicker />
                    </div>
                ) : (
                    <Phase2EmptyState
                        title="Ou comece por um modelo"
                        description="Modelos guardam campos e signatários já configurados para enviar em segundos."
                    />
                )}
                <p className="text-muted-foreground mt-3 flex items-center gap-2 text-[12.5px]">
                    <FileText className="size-3.5 shrink-0" />
                    {multiDocument
                        ? `Até ${plural(maxDocuments, 'arquivo')} por solicitação, com um único aceite de cada participante sobre o conjunto.`
                        : 'Um documento por solicitação nesta fase.'}
                </p>
            </div>
        </div>
    );
}
