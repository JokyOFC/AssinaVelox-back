import { Copy, Trash2 } from 'lucide-react';
import { useState } from 'react';
import {
    FIELD_DRAG_MIME,
    FieldLayer,
} from '@/components/envelopes/field-layer';
import {
    DATE_FORMATS,
    DEFAULT_FIELD_SIZE,
    FIELD_TYPE_ICONS,
    FIELD_TYPE_PLACEHOLDER,
    FIELD_TYPES,
    FONT_SIZES,
    minSizeFor,
} from '@/components/envelopes/field-types';
import { recipientColor } from '@/components/envelopes/recipient-colors';
import InputError from '@/components/input-error';
import { PdfPageRail } from '@/components/pdf/pdf-page-rail';
import { PdfViewer } from '@/components/pdf/pdf-viewer';
import { usePdfDocument } from '@/components/pdf/use-pdf-document';
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
import { clampRect, type NormalizedRect, type PageSize } from '@/lib/geometry';
import { fieldTypeLabels } from '@/lib/labels';
import { cn } from '@/lib/utils';
import type { FieldType } from '@/types/enums';
import type {
    TemplateFieldDraft,
    TemplatePageInfo,
    TemplateRoleDraft,
} from './types';

/** Forma que a camada de campos do wizard espera (LayerField). */
type LayerTemplateField = TemplateFieldDraft & {
    recipient_client_id: string;
    placeholder?: string | null;
};

const IMAGE_TYPES: FieldType[] = ['signature', 'initials'];

/**
 * Campos pré-posicionados de um modelo PDF fixo. Reaproveita, sem modificar, o
 * visualizador PDF.js e a camada de campos do wizard
 * (`components/envelopes/field-layer.tsx`): mesma geometria normalizada,
 * mesmos tamanhos mínimos e mesmas regras por papel (visualizador sem campos,
 * aprovador sem assinatura/rubrica). O servidor valida tudo de novo ao salvar.
 */
export function TemplateFieldEditor({
    pdfUrl,
    pages,
    roles,
    fields,
    onChange,
    errors,
    maxFields,
    disabled,
}: {
    pdfUrl: string;
    pages: TemplatePageInfo[];
    roles: TemplateRoleDraft[];
    fields: TemplateFieldDraft[];
    onChange: (fields: TemplateFieldDraft[]) => void;
    errors: Record<string, string>;
    maxFields: number;
    disabled?: boolean;
}) {
    const pdf = usePdfDocument(pdfUrl);
    const [page, setPage] = useState(1);
    const [zoom, setZoom] = useState(1);
    const [selectedId, setSelectedId] = useState<string | null>(null);
    const [activeRef, setActiveRef] = useState<string | null>(null);

    const eligibleRoles = roles.filter(
        (role) => role.participant_role !== 'viewer',
    );
    const activeRole =
        eligibleRoles.find((role) => role.ref === activeRef) ??
        eligibleRoles[0] ??
        null;

    const roleIndex = (ref: string): number =>
        Math.max(
            0,
            roles.findIndex((role) => role.ref === ref),
        );
    const roleOf = (ref: string) =>
        roles.find((role) => role.ref === ref) ?? null;

    const pointsOf = (number: number): PageSize | null => {
        const info = pages.find((item) => item.page === number);

        return info ? { width: info.width_pt, height: info.height_pt } : null;
    };

    const allowed = (
        type: FieldType,
        role: TemplateRoleDraft | null,
    ): boolean =>
        role !== null &&
        role.participant_role !== 'viewer' &&
        !(role.participant_role === 'approver' && IMAGE_TYPES.includes(type));

    const add = (type: FieldType, rect?: NormalizedRect) => {
        if (
            !activeRole ||
            !allowed(type, activeRole) ||
            fields.length >= maxFields
        ) {
            return;
        }

        const size = DEFAULT_FIELD_SIZE[type];
        const min = minSizeFor(type, pointsOf(page));
        const placed = clampRect(
            rect ?? { x: 0.5 - size.w / 2, y: 0.4, w: size.w, h: size.h },
            min,
        );
        const field: TemplateFieldDraft = {
            id: null,
            client_id: crypto.randomUUID(),
            role_ref: activeRole.ref,
            type,
            page,
            ...placed,
            required: type !== 'checkbox',
            label: null,
            options: {
                placeholder: FIELD_TYPE_PLACEHOLDER[type],
                ...(type === 'date'
                    ? { date_format: DATE_FORMATS[0].value }
                    : {}),
            },
        };

        onChange([...fields, field]);
        setSelectedId(field.client_id);
    };

    const update = (clientId: string, patch: Partial<TemplateFieldDraft>) =>
        onChange(
            fields.map((field) =>
                field.client_id === clientId ? { ...field, ...patch } : field,
            ),
        );

    const remove = (clientId: string) => {
        onChange(fields.filter((field) => field.client_id !== clientId));
        setSelectedId(null);
    };

    const duplicate = (clientId: string) => {
        const source = fields.find((field) => field.client_id === clientId);

        if (!source || fields.length >= maxFields) {
            return;
        }

        const copy: TemplateFieldDraft = {
            ...source,
            id: null,
            client_id: crypto.randomUUID(),
            ...clampRect(
                {
                    x: source.x + 0.02,
                    y: source.y + 0.02,
                    w: source.w,
                    h: source.h,
                },
                minSizeFor(source.type, pointsOf(source.page)),
            ),
        };

        onChange([...fields, copy]);
        setSelectedId(copy.client_id);
    };

    const layerFields: LayerTemplateField[] = fields
        .filter((field) => field.page === page)
        .map((field) => ({
            ...field,
            recipient_client_id: field.role_ref,
            placeholder:
                field.options.placeholder ?? FIELD_TYPE_PLACEHOLDER[field.type],
        }));

    const selected =
        fields.find((field) => field.client_id === selectedId) ?? null;
    const selectedIndex = selected ? fields.indexOf(selected) : -1;

    const fieldCounts: Record<number, number> = {};

    for (const field of fields) {
        fieldCounts[field.page] = (fieldCounts[field.page] ?? 0) + 1;
    }

    const fieldErrors = Object.entries(errors).filter(([key]) =>
        key.startsWith('fields'),
    );

    return (
        <div className="grid gap-4 xl:grid-cols-[72px_minmax(0,1fr)_300px]">
            <PdfPageRail
                document={pdf.document}
                pageCount={pdf.pageCount}
                current={page}
                onSelect={setPage}
                fieldCounts={fieldCounts}
                className="hidden xl:flex"
            />

            <PdfViewer
                pdf={pdf}
                page={page}
                onPageChange={setPage}
                zoom={zoom}
                onZoomChange={setZoom}
                maxPageWidth={560}
                overlay={(size) => (
                    <FieldLayer<LayerTemplateField>
                        fields={layerFields}
                        page={{ width: size.width, height: size.height }}
                        selectedId={selectedId}
                        onSelect={setSelectedId}
                        onChange={(clientId, rect) => update(clientId, rect)}
                        onDelete={remove}
                        onDuplicate={duplicate}
                        onDropType={(type, rect) => add(type, rect)}
                        colorOf={(field) =>
                            recipientColor(roleIndex(field.role_ref))
                        }
                        tagOf={(field) =>
                            `${roleOf(field.role_ref)?.name || 'Participante'} · ${fieldTypeLabels[field.type]}`
                        }
                        minSizeOf={(field) =>
                            minSizeFor(field.type, pointsOf(field.page))
                        }
                        readOnly={disabled}
                    />
                )}
            />

            <aside className="flex flex-col gap-4">
                <section className="border-border rounded-xl border bg-white p-4">
                    <h3 className="text-foreground mb-2 text-[13px] font-bold">
                        Campos de
                    </h3>
                    {eligibleRoles.length === 0 ? (
                        <p className="text-muted-foreground text-[12.5px]">
                            Adicione um participante que assine ou aprove para
                            posicionar campos.
                        </p>
                    ) : (
                        <div className="flex flex-wrap gap-1.5">
                            {eligibleRoles.map((role) => {
                                const color = recipientColor(
                                    roleIndex(role.ref),
                                );
                                const active = activeRole?.ref === role.ref;

                                return (
                                    <button
                                        key={role.ref}
                                        type="button"
                                        onClick={() => setActiveRef(role.ref)}
                                        aria-pressed={active}
                                        className={cn(
                                            'inline-flex h-[28px] items-center gap-1.5 rounded-full border px-2.5 text-[12.5px] font-semibold',
                                            active
                                                ? 'text-foreground'
                                                : 'text-text-secondary bg-white',
                                        )}
                                        style={
                                            active
                                                ? {
                                                      borderColor: color.solid,
                                                      backgroundColor:
                                                          color.soft,
                                                  }
                                                : undefined
                                        }
                                    >
                                        <span
                                            className="size-2 rounded-full"
                                            style={{
                                                backgroundColor: color.solid,
                                            }}
                                        />
                                        {role.name || 'Sem nome'}
                                    </button>
                                );
                            })}
                        </div>
                    )}

                    <div className="mt-3 grid grid-cols-2 gap-1.5">
                        {FIELD_TYPES.map((type) => {
                            const Icon = FIELD_TYPE_ICONS[type];
                            const enabled =
                                !disabled && allowed(type, activeRole);

                            return (
                                <button
                                    key={type}
                                    type="button"
                                    draggable={enabled}
                                    disabled={!enabled}
                                    onDragStart={(event) =>
                                        event.dataTransfer.setData(
                                            FIELD_DRAG_MIME,
                                            type,
                                        )
                                    }
                                    onClick={() => add(type)}
                                    className="border-border hover:bg-accent-subtle flex items-center gap-2 rounded-lg border bg-white px-2.5 py-2 text-[12.5px] font-semibold disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    <Icon className="text-muted-foreground size-4" />
                                    {fieldTypeLabels[type]}
                                </button>
                            );
                        })}
                    </div>
                    <p className="text-muted-foreground mt-2 text-[11.5px]">
                        Clique para adicionar na página {page} ou arraste até a
                        posição. Assinatura e rubrica são sempre obrigatórias.
                    </p>
                </section>

                {selected && (
                    <section className="border-border rounded-xl border bg-white p-4">
                        <div className="mb-3 flex items-center justify-between">
                            <h3 className="text-foreground text-[13px] font-bold">
                                {fieldTypeLabels[selected.type]} ·{' '}
                                {roleOf(selected.role_ref)?.name}
                            </h3>
                            <div className="flex gap-1">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon-sm"
                                    aria-label="Duplicar campo"
                                    disabled={disabled}
                                    onClick={() =>
                                        duplicate(selected.client_id)
                                    }
                                >
                                    <Copy />
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon-sm"
                                    aria-label="Remover campo"
                                    disabled={disabled}
                                    onClick={() => remove(selected.client_id)}
                                >
                                    <Trash2 />
                                </Button>
                            </div>
                        </div>

                        <div className="grid gap-3">
                            <div className="grid gap-1.5">
                                <Label htmlFor="field-role">Participante</Label>
                                <Select
                                    value={selected.role_ref}
                                    disabled={disabled}
                                    onValueChange={(value) =>
                                        update(selected.client_id, {
                                            role_ref: value,
                                        })
                                    }
                                >
                                    <SelectTrigger
                                        id="field-role"
                                        className="w-full"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {eligibleRoles
                                            .filter((role) =>
                                                allowed(selected.type, role),
                                            )
                                            .map((role) => (
                                                <SelectItem
                                                    key={role.ref}
                                                    value={role.ref}
                                                >
                                                    {role.name || 'Sem nome'}
                                                </SelectItem>
                                            ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            {!IMAGE_TYPES.includes(selected.type) && (
                                <label className="flex items-center gap-2 text-[13px]">
                                    <Checkbox
                                        checked={selected.required}
                                        disabled={disabled}
                                        onCheckedChange={(checked) =>
                                            update(selected.client_id, {
                                                required: checked === true,
                                            })
                                        }
                                    />
                                    Obrigatório
                                </label>
                            )}

                            <div className="grid gap-1.5">
                                <Label htmlFor="field-label">
                                    Rótulo (opcional)
                                </Label>
                                <Input
                                    id="field-label"
                                    value={selected.label ?? ''}
                                    maxLength={120}
                                    disabled={disabled}
                                    onChange={(e) =>
                                        update(selected.client_id, {
                                            label: e.target.value || null,
                                        })
                                    }
                                />
                            </div>

                            {(selected.type === 'text' ||
                                selected.type === 'name' ||
                                selected.type === 'date') && (
                                <div className="grid gap-1.5">
                                    <Label htmlFor="field-font">
                                        Tamanho da fonte
                                    </Label>
                                    <Select
                                        value={String(
                                            selected.options.font_size ?? 10,
                                        )}
                                        disabled={disabled}
                                        onValueChange={(value) =>
                                            update(selected.client_id, {
                                                options: {
                                                    ...selected.options,
                                                    font_size: Number(value),
                                                },
                                            })
                                        }
                                    >
                                        <SelectTrigger
                                            id="field-font"
                                            className="w-full"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {FONT_SIZES.map((size) => (
                                                <SelectItem
                                                    key={size}
                                                    value={String(size)}
                                                >
                                                    {size} pt
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                            )}

                            {selected.type === 'date' && (
                                <div className="grid gap-1.5">
                                    <Label htmlFor="field-date">
                                        Formato da data
                                    </Label>
                                    <Select
                                        value={
                                            selected.options.date_format ??
                                            DATE_FORMATS[0].value
                                        }
                                        disabled={disabled}
                                        onValueChange={(value) =>
                                            update(selected.client_id, {
                                                options: {
                                                    ...selected.options,
                                                    date_format: value,
                                                },
                                            })
                                        }
                                    >
                                        <SelectTrigger
                                            id="field-date"
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

                            {selectedIndex >= 0 &&
                                Object.entries(errors)
                                    .filter(([key]) =>
                                        key.startsWith(
                                            `fields.${selectedIndex}.`,
                                        ),
                                    )
                                    .map(([key, message]) => (
                                        <InputError
                                            key={key}
                                            message={message}
                                        />
                                    ))}
                        </div>
                    </section>
                )}

                <section className="border-border rounded-xl border bg-white p-4">
                    <h3 className="text-foreground mb-2 text-[13px] font-bold">
                        {fields.length === 1
                            ? '1 campo'
                            : `${fields.length} campos`}
                    </h3>
                    {errors.fields && <InputError message={errors.fields} />}
                    {fieldErrors.length > 0 && !selected && (
                        <p className="text-danger mb-2 text-[12.5px]">
                            Há campos com problema. Selecione cada um para ver o
                            detalhe.
                        </p>
                    )}
                    <ul className="flex max-h-[260px] flex-col gap-1 overflow-y-auto">
                        {fields.map((field, index) => (
                            <li key={field.client_id}>
                                <button
                                    type="button"
                                    onClick={() => {
                                        setPage(field.page);
                                        setSelectedId(field.client_id);
                                    }}
                                    className={cn(
                                        'hover:bg-accent-subtle flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-[12.5px]',
                                        field.client_id === selectedId &&
                                            'bg-primary-soft',
                                        errors[`fields.${index}.page`] ||
                                            errors[`fields.${index}.width`] ||
                                            errors[`fields.${index}.height`]
                                            ? 'text-danger'
                                            : 'text-foreground',
                                    )}
                                >
                                    <span
                                        className="size-2 shrink-0 rounded-full"
                                        style={{
                                            backgroundColor: recipientColor(
                                                roleIndex(field.role_ref),
                                            ).solid,
                                        }}
                                    />
                                    <span className="flex-1 truncate">
                                        {fieldTypeLabels[field.type]} ·{' '}
                                        {roleOf(field.role_ref)?.name}
                                    </span>
                                    <span className="text-muted-foreground">
                                        pág. {field.page}
                                    </span>
                                </button>
                            </li>
                        ))}
                    </ul>
                </section>
            </aside>
        </div>
    );
}
