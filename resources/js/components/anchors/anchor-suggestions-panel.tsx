import {
    Check,
    Plus,
    ScanSearch,
    Sparkles,
    Trash2,
    TriangleAlert,
    X,
} from 'lucide-react';
import { useState } from 'react';
import type {
    AcceptedField,
    AnchorFieldType,
    AnchorPlacement,
    DetectLiteral,
    FieldSuggestion,
} from '@/components/anchors/types';
import type { AnchorSuggestions } from '@/components/anchors/use-anchor-suggestions';
import { fieldPlaceholder } from '@/components/envelopes/field-type-extras';
import {
    DATE_FORMATS,
    DEFAULT_FONT_SIZE,
} from '@/components/envelopes/field-types';
import { recipientColor } from '@/components/envelopes/recipient-colors';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { plural } from '@/lib/format';
import { cn } from '@/lib/utils';
import type {
    EnvelopeDocument,
    WizardField,
    WizardRecipient,
} from '@/types/models';

/** Campo confirmado → campo do editor (mesmas opções iniciais da paleta). */
export function suggestionToWizardField(
    field: AcceptedField,
    recipients: WizardRecipient[],
    multiDocument: boolean,
): WizardField | null {
    const recipient = recipients.find((item) => item.id === field.recipient_id);

    if (!recipient) {
        return null;
    }

    return {
        id: null,
        client_id: crypto.randomUUID(),
        recipient_client_id: recipient.client_id,
        type: field.type,
        page: field.page,
        x: field.x,
        y: field.y,
        w: field.w,
        h: field.h,
        required: field.required,
        label: field.label,
        placeholder: fieldPlaceholder(field.type),
        options:
            field.type === 'date'
                ? {
                      font_size: DEFAULT_FONT_SIZE,
                      date_format: DATE_FORMATS[0].value,
                  }
                : { font_size: DEFAULT_FONT_SIZE },
        ...(multiDocument ? { document_id: field.document_id } : {}),
    };
}

const VISUAL: string[] = ['signature', 'initials'];

function eligibleFor(
    suggestion: { type: string },
    recipients: WizardRecipient[],
): WizardRecipient[] {
    return recipients.filter((recipient) => {
        const role = recipient.participant_role ?? 'signer';

        if (role === 'viewer' || recipient.id === null) {
            return false;
        }

        return !(role === 'approver' && VISUAL.includes(suggestion.type));
    });
}

/**
 * Painel "Detectar campos" do passo 3 (Fase 3 §3.2). A busca procura
 * marcadores (`{{assinatura:papel}}`…) e textos literais no PDF; o resultado
 * são SUGESTÕES — nada vira campo sem a confirmação de quem prepara, e o
 * documento não fica pronto enquanto houver sugestão pendente.
 */
export function AnchorSuggestionsPanel({
    anchors,
    document,
    firstDocumentId,
    multiDocument,
    recipients,
    onPageChange,
    onFieldsAdd,
    disabled,
}: {
    anchors: AnchorSuggestions;
    document: EnvelopeDocument;
    firstDocumentId: string;
    multiDocument: boolean;
    recipients: WizardRecipient[];
    onPageChange: (page: number) => void;
    onFieldsAdd: (fields: WizardField[]) => void;
    disabled?: boolean;
}) {
    const [open, setOpen] = useState(false);
    const [choice, setChoice] = useState<Record<string, string>>({});

    if (!anchors.enabled) {
        return null;
    }

    const state = anchors.state;
    const current = state?.documents.find((item) => item.id === document.id);
    const suggestions = (state?.suggestions ?? []).filter(
        (item) => (item.document_id ?? firstDocumentId) === document.id,
    );
    const otherFiles = (state?.suggestions.length ?? 0) - suggestions.length;
    const bulk = (state?.suggestions ?? []).filter(
        (item) => item.via === 'text' && item.recipient_id !== null,
    );
    const lastScan = current?.last_scan ?? null;
    const lastOcr = current?.last_ocr_scan ?? null;
    const withoutText = lastScan?.pages_without_text ?? [];
    const busy = state?.busy === true || anchors.pending === 'detect';

    const recipientOf = (suggestion: FieldSuggestion): string | null =>
        choice[suggestion.id] ?? suggestion.recipient_id;

    const confirm = async (suggestion: FieldSuggestion): Promise<void> => {
        const field = await anchors.accept(suggestion, recipientOf(suggestion));
        const converted = field
            ? suggestionToWizardField(field, recipients, multiDocument)
            : null;

        if (converted) {
            onFieldsAdd([converted]);
        }
    };

    const confirmAll = async (): Promise<void> => {
        const fields = await anchors.acceptAll();
        const converted = fields
            .map((field) =>
                suggestionToWizardField(field, recipients, multiDocument),
            )
            .filter((field): field is WizardField => field !== null);

        if (converted.length > 0) {
            onFieldsAdd(converted);
        }
    };

    return (
        <div className="border-border bg-card shadow-card flex flex-col gap-3 rounded-xl border p-4">
            <div className="flex items-center justify-between gap-2">
                <span className="flex items-center gap-1.5 text-[13px] font-semibold">
                    <ScanSearch className="size-4" />
                    Detectar campos
                </span>
                {state && state.pending_count > 0 && (
                    <Badge variant="outline" className="tabular">
                        {plural(state.pending_count, 'sugestão', 'sugestões')}
                    </Badge>
                )}
            </div>

            <p className="text-muted-foreground text-[12px] leading-[1.5]">
                Procura marcadores como{' '}
                <code className="text-foreground">
                    {'{{assinatura:papel}}'}
                </code>{' '}
                e textos no PDF. O resultado são sugestões: nada vira campo sem
                a sua confirmação.
            </p>

            <Button
                variant="outline"
                size="sm"
                disabled={disabled || busy || state === null}
                onClick={() => setOpen(true)}
            >
                {busy ? <Spinner /> : <Sparkles />}
                {busy ? 'Procurando…' : 'Detectar campos'}
            </Button>

            {anchors.error && (
                <div
                    role="alert"
                    className="border-danger-border bg-danger-bg text-danger flex items-start gap-2 rounded-[10px] border p-2.5 text-[12px] leading-[1.5]"
                >
                    <span className="flex-1">{anchors.error}</span>
                    <button
                        type="button"
                        aria-label="Fechar aviso"
                        onClick={anchors.clearError}
                    >
                        <X className="size-3.5" />
                    </button>
                </div>
            )}

            {lastScan?.status === 'failed' && lastScan.failure_message && (
                <p className="border-warning-border bg-warning-bg text-warning rounded-[10px] border p-2.5 text-[12px] leading-[1.5]">
                    {lastScan.failure_message}
                </p>
            )}

            {lastScan?.status === 'done' && (
                <p className="text-muted-foreground text-[12px] leading-[1.5]">
                    Última busca:{' '}
                    {plural(
                        lastScan.suggestions_count,
                        'sugestão',
                        'sugestões',
                    )}{' '}
                    em {plural(lastScan.pages_scanned, 'página')}.
                    {lastScan.truncated &&
                        ' O limite de ocorrências foi atingido; confira o restante do documento.'}
                </p>
            )}

            {withoutText.length > 0 && (
                <OcrNotice
                    pages={withoutText}
                    ocrEnabled={state?.ocr.enabled === true}
                    ocrMessage={state?.ocr.message ?? null}
                    statusLabel={current?.ocr_status_label ?? null}
                    simulated={
                        state?.ocr.simulated === true ||
                        lastOcr?.simulated === true
                    }
                    failure={
                        lastOcr?.status === 'failed'
                            ? lastOcr.failure_message
                            : null
                    }
                />
            )}

            {suggestions.length > 0 && (
                <div className="flex flex-col gap-2">
                    {suggestions.map((suggestion) => {
                        const options = eligibleFor(suggestion, recipients);
                        const chosen = recipientOf(suggestion);
                        const index = recipients.findIndex(
                            (item) => item.id === chosen,
                        );
                        const working = anchors.pending === suggestion.id;

                        return (
                            <div
                                key={suggestion.id}
                                className={cn(
                                    'border-warning-border flex flex-col gap-2 rounded-[10px] border border-dashed p-2.5',
                                    anchors.selectedId === suggestion.id &&
                                        'bg-warning-bg',
                                )}
                            >
                                <button
                                    type="button"
                                    className="flex items-center gap-2 text-left text-[12.5px]"
                                    onClick={() => {
                                        onPageChange(suggestion.page);
                                        anchors.select(suggestion.id);
                                    }}
                                >
                                    <span
                                        aria-hidden
                                        className="size-2 shrink-0 rounded-full"
                                        style={{
                                            backgroundColor:
                                                index >= 0
                                                    ? recipientColor(index)
                                                          .solid
                                                    : 'var(--color-warning)',
                                        }}
                                    />
                                    <span className="min-w-0 flex-1 truncate font-semibold">
                                        {suggestion.type_label}
                                        {suggestion.label
                                            ? ` · ${suggestion.label}`
                                            : ''}
                                    </span>
                                    <span className="text-muted-foreground shrink-0">
                                        pág. {suggestion.page}
                                    </span>
                                </button>

                                <div className="text-muted-foreground flex flex-wrap items-center gap-1.5 text-[11.5px]">
                                    <span>{suggestion.source_label}</span>
                                    {suggestion.role_hint && (
                                        <span>
                                            · papel “{suggestion.role_hint}”
                                        </span>
                                    )}
                                    {suggestion.via === 'ocr' && (
                                        <Badge
                                            variant="outline"
                                            className="text-[10.5px]"
                                        >
                                            OCR
                                            {suggestion.confidence !== null
                                                ? ` · ${Math.round(suggestion.confidence)}%`
                                                : ''}
                                        </Badge>
                                    )}
                                </div>

                                <Select
                                    value={chosen ?? undefined}
                                    disabled={disabled || working}
                                    onValueChange={(value) =>
                                        setChoice((previous) => ({
                                            ...previous,
                                            [suggestion.id]: value,
                                        }))
                                    }
                                >
                                    <SelectTrigger
                                        size="sm"
                                        className="w-full"
                                        aria-label="Participante do campo sugerido"
                                    >
                                        <SelectValue placeholder="Escolha o participante" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {options.map((recipient) => (
                                            <SelectItem
                                                key={recipient.client_id}
                                                value={recipient.id as string}
                                            >
                                                {recipient.name ||
                                                    recipient.email}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>

                                <div className="flex gap-1.5">
                                    <Button
                                        size="sm"
                                        className="flex-1"
                                        disabled={
                                            disabled ||
                                            working ||
                                            anchors.pending !== null ||
                                            !chosen
                                        }
                                        onClick={() => void confirm(suggestion)}
                                    >
                                        {working ? <Spinner /> : <Check />}
                                        Confirmar
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        disabled={
                                            disabled ||
                                            working ||
                                            anchors.pending !== null
                                        }
                                        onClick={() =>
                                            void anchors.discard(suggestion)
                                        }
                                    >
                                        <Trash2 />
                                        Descartar
                                    </Button>
                                </div>
                            </div>
                        );
                    })}

                    {suggestions.some((item) => item.via === 'ocr') && (
                        <p className="text-muted-foreground text-[11.5px] leading-[1.5]">
                            Sugestões lidas por OCR precisam ser conferidas uma
                            a uma: a leitura de páginas escaneadas pode errar.
                        </p>
                    )}
                </div>
            )}

            {otherFiles > 0 && (
                <p className="text-muted-foreground text-[12px]">
                    + {plural(otherFiles, 'sugestão', 'sugestões')} em outros
                    arquivos.
                </p>
            )}

            {bulk.length > 1 && (
                <Button
                    size="sm"
                    variant="outline"
                    disabled={disabled || anchors.pending !== null}
                    onClick={() => void confirmAll()}
                >
                    {anchors.pending === 'accept_all' ? <Spinner /> : <Check />}
                    Confirmar as {bulk.length} sugestões do texto
                </Button>
            )}

            {state && state.pending_count > 0 && (
                <p className="text-muted-foreground text-[11.5px] leading-[1.5]">
                    O documento só pode ser enviado depois que todas as
                    sugestões forem confirmadas ou descartadas.
                </p>
            )}

            {state && (
                <DetectDialog
                    open={open}
                    onOpenChange={setOpen}
                    maxLiterals={state.limits.max_literals}
                    fieldTypes={state.field_types}
                    placements={state.placements}
                    recipients={recipients.filter(
                        (recipient) =>
                            recipient.id !== null &&
                            (recipient.participant_role ?? 'signer') !==
                                'viewer',
                    )}
                    busy={anchors.pending === 'detect'}
                    onSubmit={async (payload) => {
                        if (await anchors.detect(payload)) {
                            setOpen(false);
                        }
                    }}
                />
            )}
        </div>
    );
}

function OcrNotice({
    pages,
    ocrEnabled,
    ocrMessage,
    statusLabel,
    simulated,
    failure,
}: {
    pages: number[];
    ocrEnabled: boolean;
    ocrMessage: string | null;
    statusLabel: string | null;
    simulated: boolean;
    failure: string | null;
}) {
    const list =
        pages.length > 6
            ? `${pages.slice(0, 6).join(', ')}…`
            : pages.join(', ');

    return (
        <div className="border-border bg-sidebar text-text-secondary flex gap-2 rounded-[10px] border p-2.5 text-[12px] leading-[1.5]">
            <TriangleAlert className="text-warning mt-0.5 size-3.5 shrink-0" />
            <div className="flex flex-col gap-1">
                <span>
                    {pages.length === 1
                        ? `A página ${list} não tem texto selecionável (parece escaneada).`
                        : `As páginas ${list} não têm texto selecionável (parecem escaneadas).`}
                </span>
                {!ocrEnabled && (
                    <span>Posicione os campos manualmente nessas páginas.</span>
                )}
                {ocrEnabled && statusLabel && <span>{statusLabel}.</span>}
                {ocrEnabled && !statusLabel && ocrMessage && (
                    <span>{ocrMessage}.</span>
                )}
                {failure && <span>{failure}</span>}
                {simulated && (
                    <Badge variant="outline" className="w-fit text-[10.5px]">
                        OCR simulado (teste)
                    </Badge>
                )}
            </div>
        </div>
    );
}

function DetectDialog({
    open,
    onOpenChange,
    maxLiterals,
    fieldTypes,
    placements,
    recipients,
    busy,
    onSubmit,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    maxLiterals: number;
    fieldTypes: { value: string; label: string }[];
    placements: { value: string; label: string }[];
    recipients: WizardRecipient[];
    busy: boolean;
    onSubmit: (payload: {
        markers: boolean;
        literals: DetectLiteral[];
    }) => Promise<void>;
}) {
    const [markers, setMarkers] = useState(true);
    const [literals, setLiterals] = useState<DetectLiteral[]>([]);

    const update = (index: number, patch: Partial<DetectLiteral>): void =>
        setLiterals((rows) =>
            rows.map((row, i) => (i === index ? { ...row, ...patch } : row)),
        );

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-[560px]">
                <DialogHeader>
                    <DialogTitle>Detectar campos</DialogTitle>
                    <DialogDescription>
                        A busca é literal: maiúsculas, acentos e espaços extras
                        não importam, e símbolos contam como texto. As sugestões
                        aparecem tracejadas na página para você conferir.
                    </DialogDescription>
                </DialogHeader>

                <label className="flex cursor-pointer items-start gap-2 text-[13px]">
                    <Checkbox
                        checked={markers}
                        onCheckedChange={(value) => setMarkers(value === true)}
                        className="mt-0.5"
                    />
                    <span>
                        Procurar marcadores
                        <span className="text-muted-foreground block text-[12px]">
                            {
                                '{{assinatura:papel}}, {{rubrica:papel}}, {{data:papel}} e {{texto:nome}}. O papel é o rótulo do participante (ex.: locatario) ou a posição dele na lista (1, 2…).'
                            }
                        </span>
                    </span>
                </label>

                <div className="flex flex-col gap-2">
                    <span className="text-[13px] font-semibold">
                        Textos do documento{' '}
                        <span className="text-muted-foreground font-normal">
                            (opcional)
                        </span>
                    </span>

                    {literals.map((row, index) => (
                        <div
                            key={index}
                            className="border-border grid gap-2 rounded-[10px] border p-2.5 sm:grid-cols-2"
                        >
                            <div className="grid gap-1 sm:col-span-2">
                                <Label
                                    htmlFor={`anchor-literal-${index}`}
                                    className="text-[12px]"
                                >
                                    Texto procurado
                                </Label>
                                <div className="flex gap-1.5">
                                    <Input
                                        id={`anchor-literal-${index}`}
                                        value={row.text}
                                        maxLength={120}
                                        placeholder="Assinatura do locatário"
                                        onChange={(event) =>
                                            update(index, {
                                                text: event.target.value,
                                            })
                                        }
                                    />
                                    <Button
                                        variant="ghost"
                                        size="icon-sm"
                                        aria-label="Remover texto"
                                        onClick={() =>
                                            setLiterals((rows) =>
                                                rows.filter(
                                                    (_, i) => i !== index,
                                                ),
                                            )
                                        }
                                    >
                                        <Trash2 />
                                    </Button>
                                </div>
                            </div>
                            <SmallSelect
                                label="Campo"
                                value={row.field_type}
                                options={fieldTypes}
                                onChange={(value) =>
                                    update(index, {
                                        field_type: value as AnchorFieldType,
                                    })
                                }
                            />
                            <SmallSelect
                                label="Posição"
                                value={row.placement}
                                options={placements}
                                onChange={(value) =>
                                    update(index, {
                                        placement: value as AnchorPlacement,
                                    })
                                }
                            />
                            <div className="sm:col-span-2">
                                <SmallSelect
                                    label="Participante"
                                    value={row.recipient_id ?? ''}
                                    placeholder="Escolher depois"
                                    options={recipients.map((recipient) => ({
                                        value: recipient.id as string,
                                        label:
                                            recipient.name || recipient.email,
                                    }))}
                                    onChange={(value) =>
                                        update(index, {
                                            recipient_id: value || null,
                                        })
                                    }
                                />
                            </div>
                        </div>
                    ))}

                    {literals.length < maxLiterals && (
                        <Button
                            variant="outline"
                            size="sm"
                            className="w-fit"
                            onClick={() =>
                                setLiterals((rows) => [
                                    ...rows,
                                    {
                                        text: '',
                                        field_type: 'signature',
                                        recipient_id: null,
                                        placement: 'below',
                                    },
                                ])
                            }
                        >
                            <Plus />
                            Adicionar texto
                        </Button>
                    )}
                </div>

                <DialogFooter>
                    <Button variant="ghost" onClick={() => onOpenChange(false)}>
                        Cancelar
                    </Button>
                    <Button
                        disabled={busy || (!markers && literals.length === 0)}
                        onClick={() =>
                            void onSubmit({
                                markers,
                                literals: literals.filter(
                                    (row) => row.text.trim() !== '',
                                ),
                            })
                        }
                    >
                        {busy ? <Spinner /> : <ScanSearch />}
                        Procurar
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function SmallSelect({
    label,
    value,
    options,
    onChange,
    placeholder,
}: {
    label: string;
    value: string;
    options: { value: string; label: string }[];
    onChange: (value: string) => void;
    placeholder?: string;
}) {
    return (
        <div className="grid gap-1">
            <span className="text-[12px] font-medium">{label}</span>
            <Select value={value || undefined} onValueChange={onChange}>
                <SelectTrigger size="sm" className="w-full" aria-label={label}>
                    <SelectValue placeholder={placeholder} />
                </SelectTrigger>
                <SelectContent>
                    {options.map((option) => (
                        <SelectItem key={option.value} value={option.value}>
                            {option.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </div>
    );
}
