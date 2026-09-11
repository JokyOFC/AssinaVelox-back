import { usePage } from '@inertiajs/react';
import { Copy, Trash2, TriangleAlert } from 'lucide-react';
import { useState } from 'react';
import {
    FIELD_DRAG_MIME,
    FieldLayer,
} from '@/components/envelopes/field-layer';
import {
    CPF_PLACEHOLDER,
    defaultFieldSize,
    editorPaletteTypes,
    fieldMinSize,
    fieldPlaceholder,
    fieldTypeHint,
    fieldTypeIcon,
    isServerFilled,
    type EditorFieldType,
} from '@/components/envelopes/field-type-extras';
import {
    DATE_FORMATS,
    DEFAULT_FONT_SIZE,
    FONT_SIZES,
    INITIALS_ON_ALL_PAGES_RECT,
} from '@/components/envelopes/field-types';
import { recipientColor } from '@/components/envelopes/recipient-colors';
import { documentName } from '@/components/envelopes/wizard-document-list';
import { roleOf } from '@/components/envelopes/wizard-step-recipients';
import { DocumentSwitcher } from '@/components/pdf/document-switcher';
import { PdfPageRail } from '@/components/pdf/pdf-page-rail';
import { PdfViewer } from '@/components/pdf/pdf-viewer';
import type { PdfDocumentState } from '@/components/pdf/use-pdf-document';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    clampRect,
    type MinSize,
    type NormalizedRect,
    type PageSize,
} from '@/lib/geometry';
import { plural } from '@/lib/format';
import { fieldTypeLabels, participantRoleLabels } from '@/lib/labels';
import { cn } from '@/lib/utils';
import type { SigningFieldType } from '@/types/enums';
import type {
    EnvelopeDocument,
    WizardField,
    WizardRecipient,
} from '@/types/models';

/** Tipos que desenham a representação visual — proibidos para aprovador (§2.4). */
const VISUAL_TYPES: SigningFieldType[] = ['signature', 'initials'];

/**
 * Opções iniciais por tipo. Carimbo (§2.8) não tem fonte: é uma imagem desenhada pelo
 * servidor. CPF (§2.11) leva o placeholder sugerido em `options.placeholder`.
 */
function initialOptions(type: SigningFieldType): WizardField['options'] {
    if (type === 'stamp') {
        return {};
    }

    if (type === 'date') {
        return {
            font_size: DEFAULT_FONT_SIZE,
            date_format: DATE_FORMATS[0].value,
        };
    }

    if (type === 'cpf') {
        return { font_size: DEFAULT_FONT_SIZE, placeholder: CPF_PLACEHOLDER };
    }

    return { font_size: DEFAULT_FONT_SIZE };
}

function firstName(recipient: WizardRecipient, index: number): string {
    const name = recipient.name.trim();

    return name ? (name.split(/\s+/u)[0] ?? name) : `Signatário ${index + 1}`;
}

function newField(
    type: SigningFieldType,
    recipient: WizardRecipient,
    page: number,
    rect: NormalizedRect,
    min: MinSize,
    documentId: string | undefined,
): WizardField {
    return {
        id: null,
        client_id: crypto.randomUUID(),
        recipient_client_id: recipient.client_id,
        type,
        page,
        ...clampRect(rect, min),
        // O carimbo não é preenchido por ninguém: não nasce obrigatório (branding.md §8).
        required: type !== 'checkbox' && type !== 'stamp',
        label: null,
        placeholder: fieldPlaceholder(type),
        options: initialOptions(type),
        // Fase 2 §2.3: só com vários arquivos o campo carrega o arquivo; sem a flag o
        // payload é o da Fase 1.
        ...(documentId ? { document_id: documentId } : {}),
    };
}

/**
 * Passo 3 — Campos (DESIGN §6.5): rail de páginas, página do PDF com a camada
 * de campos e painel lateral com paleta, propriedades e lista de campos.
 *
 * Fase 2:
 * - §2.3 (`multiDocument`): seletor de arquivo acima do documento; cada campo pertence a
 *   um arquivo (`document_id`) e a página/rail/lista mostram só os do arquivo aberto.
 * - §2.4: visualizador não recebe campos (fica fora da lista "Adicionar campo para");
 *   aprovador não recebe assinatura nem rubrica (os dois tipos ficam desabilitados).
 *   As mesmas regras são revalidadas pelo servidor (`FieldSync`).
 */
export function WizardStepFields({
    document,
    documents = [],
    multiDocument = false,
    onDocumentChange,
    pdf,
    page,
    onPageChange,
    zoom,
    onZoomChange,
    recipients,
    fields,
    onFieldsChange,
    initialsOnAllPages,
    onInitialsOnAllPagesChange,
    errors,
    disabled,
}: {
    /** Arquivo aberto no editor (com um só arquivo, o documento do envelope). */
    document: EnvelopeDocument;
    documents?: EnvelopeDocument[];
    multiDocument?: boolean;
    onDocumentChange?: (documentId: string) => void;
    pdf: PdfDocumentState;
    page: number;
    onPageChange: (page: number) => void;
    zoom: number;
    onZoomChange: (zoom: number) => void;
    recipients: WizardRecipient[];
    fields: WizardField[];
    onFieldsChange: (fields: WizardField[]) => void;
    initialsOnAllPages: boolean;
    onInitialsOnAllPagesChange: (value: boolean) => void;
    errors: Record<string, string>;
    disabled?: boolean;
}) {
    const { features } = usePage().props;
    // Paleta pelas flags (T8): `stamp` com `branding` (C-BRAND), `cpf` com `cpf_field` (C-ID).
    // Com as duas desligadas é exatamente a paleta da Fase 1.
    const palette = editorPaletteTypes({
        branding: features?.branding === true,
        cpf_field: features?.cpf_field === true,
    });
    const multi = multiDocument && documents.length > 1;
    const firstDocumentId = documents[0]?.id ?? document.id;
    const documentOf = (field: WizardField): string =>
        field.document_id ?? firstDocumentId;
    const inCurrent = (field: WizardField): boolean =>
        !multi || documentOf(field) === document.id;

    // Visualizador não recebe campo nenhum (§2.4). Sem papéis, todos são signatários.
    const eligible = recipients.filter(
        (recipient) => roleOf(recipient) !== 'viewer',
    );
    const rolesInUse = recipients.some(
        (recipient) => roleOf(recipient) !== 'signer',
    );

    const [activeRecipientId, setActiveRecipientId] = useState<string | null>(
        eligible[0]?.client_id ?? null,
    );
    const [selectedId, setSelectedId] = useState<string | null>(null);
    const [grid, setGrid] = useState(false);

    const activeRecipient =
        eligible.find(
            (recipient) => recipient.client_id === activeRecipientId,
        ) ??
        eligible[0] ??
        null;
    const activeIsApprover =
        activeRecipient !== null && roleOf(activeRecipient) === 'approver';

    const indexOfRecipient = (clientId: string): number =>
        Math.max(
            0,
            recipients.findIndex(
                (recipient) => recipient.client_id === clientId,
            ),
        );

    const colorOf = (field: WizardField) =>
        recipientColor(indexOfRecipient(field.recipient_client_id));

    const tagOf = (field: WizardField): string => {
        const index = indexOfRecipient(field.recipient_client_id);
        const recipient = recipients[index];

        return `${recipient ? firstName(recipient, index) : 'Signatário'} · ${fieldTypeLabels[field.type]}`;
    };

    // Dimensões EXIBIDAS da página em pontos: convertem o mínimo por tipo
    // (definido em pontos pelo servidor) em fração da página.
    const pointsOf = (number: number): PageSize | null => {
        const meta = document.page_sizes.find((size) => size.page === number);

        return meta ? { width: meta.width_pt, height: meta.height_pt } : null;
    };

    const minOf = (field: WizardField): MinSize =>
        fieldMinSize(
            field.type,
            pointsOf(field.page === 'all' ? 1 : Number(field.page)),
        );

    // As rubricas automáticas são geradas e regeradas pelo servidor
    // (docs/campos-e-geometria.md §5): ficam fora da edição manual — mas continuam
    // sendo DESENHADAS. Escondê-las deixava quem prepara marcando "Rubrica em todas as
    // páginas", lendo "+ N rubricas automáticas" e sem ver nenhuma caixa na página:
    // não dava para perceber onde elas caem antes do envio, quando o documento já está
    // bloqueado para edição.
    const allManual = fields.filter((field) => field.auto !== true);
    const manualFields = allManual.filter(inCurrent);
    const autoCount = fields.filter(
        (field) => field.auto === true && inCurrent(field),
    ).length;

    const pageFields = manualFields.filter(
        (field) => field.page !== 'all' && Number(field.page) === page,
    );

    const autoPageFields = fields.filter(
        (field) =>
            field.auto === true &&
            inCurrent(field) &&
            field.page !== 'all' &&
            Number(field.page) === page,
    );
    const selected =
        fields.find((field) => field.client_id === selectedId) ?? null;

    const fieldCounts = manualFields.reduce<Record<number, number>>(
        (counts, field) => {
            if (field.page === 'all') {
                return counts;
            }

            const number = Number(field.page);
            counts[number] = (counts[number] ?? 0) + 1;

            return counts;
        },
        {},
    );

    const addField = (type: EditorFieldType, rect?: NormalizedRect): void => {
        if (!activeRecipient) {
            return;
        }

        if (activeIsApprover && VISUAL_TYPES.includes(type)) {
            return;
        }

        // Um tipo solto que a paleta não oferece (flag desligada) é ignorado.
        if (!palette.includes(type)) {
            return;
        }

        const size = defaultFieldSize(type);
        const created = newField(
            type,
            activeRecipient,
            page,
            rect ?? {
                x: 0.5 - size.w / 2,
                y: 0.5 - size.h / 2,
                w: size.w,
                h: size.h,
            },
            fieldMinSize(type, pointsOf(page)),
            multiDocument ? document.id : undefined,
        );

        onFieldsChange([...fields, created]);
        setSelectedId(created.client_id);
    };

    const updateField = (clientId: string, patch: Partial<WizardField>): void =>
        onFieldsChange(
            fields.map((field) =>
                field.client_id === clientId ? { ...field, ...patch } : field,
            ),
        );

    const deleteField = (clientId: string): void => {
        onFieldsChange(fields.filter((field) => field.client_id !== clientId));
        setSelectedId((current) => (current === clientId ? null : current));
    };

    const duplicateField = (clientId: string): void => {
        const original = fields.find((field) => field.client_id === clientId);

        if (!original) {
            return;
        }

        const copy: WizardField = {
            ...original,
            id: null,
            client_id: crypto.randomUUID(),
            ...clampRect(
                {
                    x: original.x + 0.02,
                    y: original.y + 0.02,
                    w: original.w,
                    h: original.h,
                },
                minOf(original),
            ),
        };

        onFieldsChange([...fields, copy]);
        setSelectedId(copy.client_id);
    };

    // Só signatário e testemunha precisam de assinatura (o aprovador aprova sem ela).
    const missingSignature = recipients.filter(
        (recipient) =>
            (roleOf(recipient) === 'signer' ||
                roleOf(recipient) === 'witness') &&
            !allManual.some(
                (field) =>
                    field.recipient_client_id === recipient.client_id &&
                    (field.type === 'signature' || field.type === 'initials'),
            ),
    );

    const countIn = (documentId: string): number =>
        allManual.filter((field) => documentOf(field) === documentId).length;

    return (
        <div className="flex flex-col gap-4">
            {multi && (
                <DocumentSwitcher
                    items={documents.map((item) => ({
                        id: item.id,
                        position: item.position ?? documents.indexOf(item) + 1,
                        name: documentName(item),
                        meta: item.processing.ready
                            ? plural(countIn(item.id), 'campo')
                            : item.processing.label,
                        tone: item.processing.ready ? 'default' : 'attention',
                    }))}
                    current={document.id}
                    onSelect={(id) => {
                        setSelectedId(null);
                        onDocumentChange?.(id);
                    }}
                    label="Posicionar campos no arquivo"
                />
            )}

            <div className="flex flex-wrap items-start gap-5">
                <PdfPageRail
                    document={pdf.document}
                    pageCount={
                        pdf.pageCount || (document.processing.pages ?? 0)
                    }
                    current={page}
                    onSelect={onPageChange}
                    fieldCounts={fieldCounts}
                    className="w-full shrink-0 md:max-h-[70vh] md:w-[72px]"
                />

                <div className="min-w-0 flex-[1.5_1_380px]">
                    <PdfViewer
                        pdf={pdf}
                        page={page}
                        onPageChange={onPageChange}
                        zoom={zoom}
                        onZoomChange={onZoomChange}
                        processing={document.processing}
                        maxPageWidth={520}
                        stamp={
                            multi
                                ? `arq. ${document.position ?? documents.indexOf(document) + 1} · pág. ${page}/${pdf.pageCount || document.processing.pages || '?'}`
                                : `pág. ${page}/${pdf.pageCount || document.processing.pages || '?'}`
                        }
                        overlay={(size) => (
                            <>
                                {autoPageFields.length > 0 && (
                                    // Camada somente leitura, sem alças e sem eventos: as
                                    // rubricas automáticas são do servidor, mas quem prepara
                                    // precisa vê-las no lugar em que vão cair.
                                    <FieldLayer<WizardField>
                                        fields={autoPageFields}
                                        page={size}
                                        selectedId={null}
                                        onSelect={() => {}}
                                        onChange={() => {}}
                                        onDelete={() => {}}
                                        onDuplicate={() => {}}
                                        colorOf={colorOf}
                                        tagOf={tagOf}
                                        variantOf={() => 'pending'}
                                        footnoteOf={() => 'Rubrica automática'}
                                        readOnly
                                        className="pointer-events-none"
                                    />
                                )}
                                <FieldLayer<WizardField, EditorFieldType>
                                    fields={pageFields}
                                    page={size}
                                    selectedId={selectedId}
                                    onSelect={setSelectedId}
                                    onChange={(clientId, rect) =>
                                        updateField(clientId, rect)
                                    }
                                    onDelete={deleteField}
                                    onDuplicate={duplicateField}
                                    onDropType={(type, rect) =>
                                        addField(type, rect)
                                    }
                                    colorOf={colorOf}
                                    tagOf={tagOf}
                                    minSizeOf={minOf}
                                    grid={grid}
                                    readOnly={disabled}
                                />
                            </>
                        )}
                    />
                    <p className="text-muted-foreground mt-2 text-[12px] leading-[1.5]">
                        Clique num campo para selecionar. Setas movem, Shift com
                        as setas redimensiona, Delete remove.
                    </p>
                </div>

                <div className="flex min-w-0 flex-[1_1_260px] flex-col gap-4">
                    <div className="border-border bg-card shadow-card flex flex-col gap-3 rounded-xl border p-4">
                        <div className="text-[13px] font-semibold">
                            Adicionar campo para
                        </div>
                        <div className="flex flex-wrap gap-1.5">
                            {eligible.map((recipient) => {
                                const index = indexOfRecipient(
                                    recipient.client_id,
                                );
                                const color = recipientColor(index);
                                const active =
                                    recipient.client_id ===
                                    activeRecipient?.client_id;
                                const participantRole = roleOf(recipient);

                                return (
                                    <button
                                        key={recipient.client_id}
                                        type="button"
                                        onClick={() =>
                                            setActiveRecipientId(
                                                recipient.client_id,
                                            )
                                        }
                                        aria-pressed={active}
                                        style={
                                            active
                                                ? {
                                                      backgroundColor:
                                                          color.solid,
                                                      borderColor: color.solid,
                                                      color: '#ffffff',
                                                  }
                                                : { borderColor: undefined }
                                        }
                                        className={cn(
                                            'inline-flex h-[30px] max-w-full items-center gap-1.5 rounded-full border px-[10px] text-[12.5px] font-semibold',
                                            !active &&
                                                'border-input text-foreground bg-white',
                                        )}
                                    >
                                        <span
                                            aria-hidden
                                            className="size-2 shrink-0 rounded-full"
                                            style={{
                                                backgroundColor: active
                                                    ? '#ffffff'
                                                    : color.solid,
                                            }}
                                        />
                                        <span className="truncate">
                                            {recipient.name ||
                                                `Signatário ${index + 1}`}
                                        </span>
                                        {participantRole !== 'signer' && (
                                            <span
                                                className={cn(
                                                    'shrink-0 text-[11px] font-medium',
                                                    active
                                                        ? 'text-white/85'
                                                        : 'text-muted-foreground',
                                                )}
                                            >
                                                ·{' '}
                                                {participantRoleLabels[
                                                    participantRole
                                                ].toLowerCase()}
                                            </span>
                                        )}
                                    </button>
                                );
                            })}
                        </div>

                        <p className="text-muted-foreground text-[12px] leading-[1.5]">
                            Arraste um tipo de campo para a página ou clique
                            para inserir no centro.
                        </p>

                        {activeIsApprover && (
                            <p className="border-primary-soft-border bg-primary-soft text-primary rounded-[10px] border p-2.5 text-[12px] leading-[1.5]">
                                Aprovadores aprovam o conteúdo sem representação
                                visual de assinatura: assinatura e rubrica não
                                estão disponíveis para eles.
                            </p>
                        )}

                        <div className="grid grid-cols-2 gap-2">
                            {palette.map((type) => {
                                const Icon = fieldTypeIcon(type);
                                const blocked =
                                    activeIsApprover &&
                                    VISUAL_TYPES.includes(type);

                                return (
                                    <button
                                        key={type}
                                        type="button"
                                        draggable={
                                            !disabled &&
                                            !blocked &&
                                            Boolean(activeRecipient)
                                        }
                                        onDragStart={(event) => {
                                            event.dataTransfer.setData(
                                                FIELD_DRAG_MIME,
                                                type,
                                            );
                                            event.dataTransfer.effectAllowed =
                                                'copy';
                                        }}
                                        disabled={
                                            disabled ||
                                            blocked ||
                                            !activeRecipient
                                        }
                                        title={
                                            blocked
                                                ? 'Aprovadores não recebem assinatura nem rubrica'
                                                : type === 'stamp' ||
                                                    type === 'cpf'
                                                  ? fieldTypeHint(type)
                                                  : undefined
                                        }
                                        onClick={() => addField(type)}
                                        className="border-border hover:border-primary hover:bg-accent-subtle flex h-[38px] cursor-grab items-center gap-2 rounded-lg border bg-white px-2.5 text-left text-[12.5px] font-semibold disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        <Icon
                                            className="size-3.5 shrink-0"
                                            style={{
                                                color: activeRecipient
                                                    ? recipientColor(
                                                          indexOfRecipient(
                                                              activeRecipient.client_id,
                                                          ),
                                                      ).solid
                                                    : undefined,
                                            }}
                                        />
                                        <span className="truncate">
                                            {fieldTypeLabels[type]}
                                        </span>
                                    </button>
                                );
                            })}
                        </div>

                        <label className="text-text-secondary flex cursor-pointer items-center gap-2 text-[12.5px]">
                            <Checkbox
                                checked={grid}
                                onCheckedChange={(value) =>
                                    setGrid(value === true)
                                }
                            />
                            Mostrar grade de alinhamento
                        </label>
                    </div>

                    {selected && (
                        <FieldProperties
                            field={selected}
                            recipients={recipients}
                            disabled={disabled}
                            onChange={(patch) =>
                                updateField(selected.client_id, patch)
                            }
                            onDelete={() => deleteField(selected.client_id)}
                            onDuplicate={() =>
                                duplicateField(selected.client_id)
                            }
                        />
                    )}

                    <div className="border-border bg-card shadow-card flex flex-col gap-2 rounded-xl border p-4">
                        <div className="flex items-center justify-between">
                            <span className="text-[13px] font-semibold">
                                {multi
                                    ? 'Campos neste arquivo'
                                    : 'Campos inseridos'}
                            </span>
                            <span className="text-muted-foreground tabular text-[12px]">
                                {manualFields.length}
                            </span>
                        </div>

                        {manualFields.length === 0 && (
                            <p className="text-muted-foreground py-2 text-[12.5px]">
                                Nenhum campo posicionado ainda.
                            </p>
                        )}

                        {manualFields.map((field) => (
                            <button
                                key={field.client_id}
                                type="button"
                                onClick={() => {
                                    if (field.page !== 'all') {
                                        onPageChange(Number(field.page));
                                    }

                                    setSelectedId(field.client_id);
                                }}
                                className={cn(
                                    'border-muted flex items-center gap-2 border-t py-1.5 text-left text-[12.5px] first:border-t-0',
                                    field.client_id === selectedId &&
                                        'text-primary font-semibold',
                                )}
                            >
                                <span
                                    aria-hidden
                                    className="size-2 shrink-0 rounded-full"
                                    style={{
                                        backgroundColor: colorOf(field).solid,
                                    }}
                                />
                                <span className="min-w-0 flex-1 truncate">
                                    {tagOf(field)}
                                </span>
                                <span className="text-muted-foreground shrink-0">
                                    {field.page === 'all'
                                        ? 'todas'
                                        : `pág. ${field.page}`}
                                </span>
                            </button>
                        ))}

                        {autoCount > 0 && (
                            <p className="text-muted-foreground border-muted border-t pt-1.5 text-[12px]">
                                + {autoCount}{' '}
                                {autoCount === 1
                                    ? 'rubrica automática'
                                    : 'rubricas automáticas'}{' '}
                                geradas pelo servidor.
                            </p>
                        )}

                        <label className="text-text-secondary mt-1 flex cursor-pointer items-start gap-2 text-[12.5px]">
                            <Checkbox
                                checked={initialsOnAllPages}
                                disabled={disabled}
                                onCheckedChange={(value) =>
                                    onInitialsOnAllPagesChange(value === true)
                                }
                                className="mt-0.5"
                            />
                            <span>
                                Rubrica em todas as páginas
                                <span className="text-muted-foreground block text-[11.5px]">
                                    {multi || rolesInUse
                                        ? `Gera uma rubrica por página${multi ? ' de cada arquivo' : ''} para cada signatário e testemunha, no rodapé à direita (${Math.round(INITIALS_ON_ALL_PAGES_RECT.x * 100)}% da largura). Aprovadores e visualizadores não rubricam.`
                                        : `Gera uma rubrica por página para cada signatário, no rodapé à direita (${Math.round(INITIALS_ON_ALL_PAGES_RECT.x * 100)}% da largura).`}
                                </span>
                            </span>
                        </label>
                    </div>

                    {(multi || rolesInUse) && (
                        <div className="border-border bg-card shadow-card flex flex-col gap-1.5 rounded-xl border p-4">
                            <span className="text-[13px] font-semibold">
                                Por participante
                            </span>
                            {recipients.map((recipient, index) => {
                                const participantRole = roleOf(recipient);
                                const mine = allManual.filter(
                                    (field) =>
                                        field.recipient_client_id ===
                                        recipient.client_id,
                                );
                                const files = new Set(mine.map(documentOf));

                                return (
                                    <div
                                        key={recipient.client_id}
                                        className="border-muted flex items-center gap-2 border-t py-1.5 text-[12.5px] first:border-t-0"
                                    >
                                        <span
                                            aria-hidden
                                            className="size-2 shrink-0 rounded-full"
                                            style={{
                                                backgroundColor:
                                                    recipientColor(index).solid,
                                            }}
                                        />
                                        <span className="min-w-0 flex-1 truncate">
                                            {recipient.name ||
                                                `${participantRoleLabels[participantRole]} ${index + 1}`}
                                            {participantRole !== 'signer' && (
                                                <span className="text-muted-foreground">
                                                    {' '}
                                                    ·{' '}
                                                    {participantRoleLabels[
                                                        participantRole
                                                    ].toLowerCase()}
                                                </span>
                                            )}
                                        </span>
                                        <span className="text-muted-foreground tabular shrink-0">
                                            {participantRole === 'viewer'
                                                ? 'sem campos'
                                                : multi
                                                  ? `${plural(mine.length, 'campo')} · ${plural(files.size, 'arquivo')}`
                                                  : plural(
                                                        mine.length,
                                                        'campo',
                                                    )}
                                        </span>
                                    </div>
                                );
                            })}
                        </div>
                    )}

                    {missingSignature.length > 0 && (
                        <div className="border-warning-border bg-warning-bg text-warning flex gap-2 rounded-[10px] border p-3 text-[12.5px] leading-[1.5]">
                            <TriangleAlert className="mt-0.5 size-4 shrink-0" />
                            <span>
                                Sem campo de assinatura:{' '}
                                {missingSignature
                                    .map(
                                        (recipient, index) =>
                                            recipient.name ||
                                            `Signatário ${recipients.indexOf(recipient) + 1 || index + 1}`,
                                    )
                                    .join(', ')}
                                .{' '}
                                {rolesInUse
                                    ? 'Signatários e testemunhas precisam de pelo menos um campo de assinatura.'
                                    : 'Todo signatário precisa de pelo menos um campo de assinatura.'}
                            </span>
                        </div>
                    )}

                    <InputError message={errors.fields} />
                </div>
            </div>
        </div>
    );
}

/** Painel de propriedades do campo selecionado. */
function FieldProperties({
    field,
    recipients,
    onChange,
    onDelete,
    onDuplicate,
    disabled,
}: {
    field: WizardField;
    recipients: WizardRecipient[];
    onChange: (patch: Partial<WizardField>) => void;
    onDelete: () => void;
    onDuplicate: () => void;
    disabled?: boolean;
}) {
    const options = field.options ?? {};
    const serverFilled = isServerFilled(field.type);
    // Carimbo visual: imagem desenhada pelo servidor — sem fonte e sem "obrigatório".
    const stamp = field.type === 'stamp';
    // Mesmas regras do servidor: nada para visualizador; nada visual para aprovador.
    const assignable = recipients.filter((recipient) => {
        const participantRole = roleOf(recipient);

        if (participantRole === 'viewer') {
            return false;
        }

        return !(
            participantRole === 'approver' && VISUAL_TYPES.includes(field.type)
        );
    });

    return (
        <div className="border-border bg-card shadow-card flex flex-col gap-3 rounded-xl border p-4">
            <div className="flex items-center justify-between gap-2">
                <span className="text-[13px] font-semibold">
                    {fieldTypeLabels[field.type]}
                </span>
                <div className="flex gap-1">
                    <Button
                        variant="ghost"
                        size="icon-xs"
                        aria-label="Duplicar campo"
                        title="Duplicar (Ctrl+D)"
                        disabled={disabled}
                        onClick={onDuplicate}
                    >
                        <Copy className="size-3.5" />
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon-xs"
                        aria-label="Remover campo"
                        title="Remover (Delete)"
                        disabled={disabled}
                        onClick={onDelete}
                        className="hover:bg-danger-bg hover:text-danger"
                    >
                        <Trash2 className="size-3.5" />
                    </Button>
                </div>
            </div>

            <div className="grid gap-1.5">
                <Label
                    htmlFor={`field-recipient-${field.client_id}`}
                    className="text-[12.5px]"
                >
                    Signatário
                </Label>
                <Select
                    value={field.recipient_client_id}
                    disabled={disabled}
                    onValueChange={(value) =>
                        onChange({ recipient_client_id: value })
                    }
                >
                    <SelectTrigger
                        id={`field-recipient-${field.client_id}`}
                        size="sm"
                        className="w-full"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {assignable.map((recipient) => (
                            <SelectItem
                                key={recipient.client_id}
                                value={recipient.client_id}
                            >
                                {recipient.name ||
                                    `Signatário ${recipients.indexOf(recipient) + 1}`}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>

            <div className="grid gap-1.5">
                <Label
                    htmlFor={`field-label-${field.client_id}`}
                    className="text-[12.5px]"
                >
                    Rótulo{' '}
                    <span className="text-muted-foreground font-normal">
                        (opcional)
                    </span>
                </Label>
                <Input
                    id={`field-label-${field.client_id}`}
                    value={field.label ?? ''}
                    maxLength={120}
                    disabled={disabled}
                    placeholder={
                        field.type === 'checkbox'
                            ? 'Li e concordo'
                            : fieldTypeLabels[field.type]
                    }
                    onChange={(event) =>
                        onChange({ label: event.target.value || null })
                    }
                />
            </div>

            <div className={cn('grid grid-cols-2 gap-2', stamp && 'hidden')}>
                <div className="grid gap-1.5">
                    <Label
                        htmlFor={`field-font-${field.client_id}`}
                        className="text-[12.5px]"
                    >
                        Tamanho da fonte
                    </Label>
                    <Select
                        value={String(options.font_size ?? DEFAULT_FONT_SIZE)}
                        disabled={disabled}
                        onValueChange={(value) =>
                            onChange({
                                options: {
                                    ...options,
                                    font_size: Number(value),
                                },
                            })
                        }
                    >
                        <SelectTrigger
                            id={`field-font-${field.client_id}`}
                            size="sm"
                            className="w-full"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {FONT_SIZES.map((size) => (
                                <SelectItem key={size} value={String(size)}>
                                    {size} pt
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                {field.type === 'date' && (
                    <div className="grid gap-1.5">
                        <Label
                            htmlFor={`field-date-${field.client_id}`}
                            className="text-[12.5px]"
                        >
                            Formato da data
                        </Label>
                        <Select
                            value={options.date_format ?? DATE_FORMATS[0].value}
                            disabled={disabled}
                            onValueChange={(value) =>
                                onChange({
                                    options: {
                                        ...options,
                                        date_format: value,
                                    },
                                })
                            }
                        >
                            <SelectTrigger
                                id={`field-date-${field.client_id}`}
                                size="sm"
                                className="w-full"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {DATE_FORMATS.map((format) => (
                                    <SelectItem
                                        key={format.value}
                                        value={format.value}
                                    >
                                        {format.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                )}
            </div>

            {!stamp && (
                <label className="text-text-secondary flex cursor-pointer items-center gap-2 text-[12.5px]">
                    <Checkbox
                        checked={field.required}
                        disabled={disabled}
                        onCheckedChange={(value) =>
                            onChange({ required: value === true })
                        }
                    />
                    Preenchimento obrigatório
                </label>
            )}

            {(stamp || field.type === 'cpf') && (
                <p className="border-border bg-sidebar text-text-secondary rounded-[10px] border p-2.5 text-[11.5px] leading-[1.5]">
                    {fieldTypeHint(field.type)}
                    {stamp &&
                        ' Usa o logo e as cores salvos em Configurações › Marca no momento do aceite.'}
                </p>
            )}

            {serverFilled && (
                <p className="text-muted-foreground text-[11.5px] leading-[1.5]">
                    A data é carimbada pelo servidor no momento do aceite, no
                    fuso da organização — o signatário não digita.
                </p>
            )}

            <p className="text-muted-foreground tabular text-[11.5px]">
                Posição {Math.round(field.x * 100)}% ×{' '}
                {Math.round(field.y * 100)}% · tamanho{' '}
                {Math.round(field.w * 100)}% × {Math.round(field.h * 100)}%
            </p>
        </div>
    );
}
