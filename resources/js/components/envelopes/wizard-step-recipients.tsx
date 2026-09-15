import {
    CheckCircle2,
    ChevronDown,
    ChevronUp,
    Eye,
    GripVertical,
    Mail,
    Trash2,
    UserPlus,
} from 'lucide-react';
import { useState } from 'react';
import { CaptureRequirementControl } from '@/components/envelopes/capture-requirement-control';
import { VideoRequirementControl } from '@/components/identity/video-requirement-control';
import { RecipientLocaleControl } from '@/components/envelopes/recipient-locale-control';
import {
    RecipientChannelFields,
    RecipientPinControl,
} from '@/components/envelopes/recipient-channel-fields';
import { recipientColor } from '@/components/envelopes/recipient-colors';
import { SelectableChip } from '@/components/filter-bar';
import InputError from '@/components/input-error';
import { SegmentedControl } from '@/components/segmented-control';
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
import {
    participantRoleDescriptions,
    participantRoleLabels,
    signingOrderLabels,
} from '@/lib/labels';
import { cn } from '@/lib/utils';
import type { CaptureKind, ParticipantRole, SigningOrder } from '@/types/enums';
import type {
    ParticipantRoleOption,
    WizardChannels,
    WizardRecipient,
} from '@/types/models';

const ORDER_HINTS: Record<SigningOrder, string> = {
    sequential: 'Cada signatário recebe o documento após o anterior assinar.',
    parallel: 'Todos recebem o documento imediatamente.',
};

/** Com papéis (Fase 2 §2.4) a ordem vale para quem participa; visualizadores não têm vez. */
const ORDER_HINTS_WITH_ROLES: Record<SigningOrder, string> = {
    sequential:
        'Cada participante recebe o documento após o anterior concluir. Visualizadores recebem no envio.',
    parallel:
        'Todos recebem o documento imediatamente, inclusive os visualizadores.',
};

/** O campo "Nome completo" diz de quem é o nome (testemunha, aprovador e visualizador não assinam). */
const NAME_PLACEHOLDER: Record<ParticipantRole, string> = {
    signer: 'Nome do signatário',
    witness: 'Nome da testemunha',
    approver: 'Nome do aprovador',
    viewer: 'Nome de quem vai acompanhar',
};

const ROLE_ORDER: ParticipantRole[] = [
    'signer',
    'witness',
    'approver',
    'viewer',
];

/** Papel efetivo: ausente é `signer` (contrato da Fase 1). */
export function roleOf(recipient: {
    participant_role?: ParticipantRole;
}): ParticipantRole {
    return recipient.participant_role ?? 'signer';
}

/**
 * Passo 2 — Signatários (DESIGN §6.5). Lista reordenável (arraste ou setas),
 * nome e e-mail por signatário, papel sugerido e ordem de assinatura.
 *
 * Canal e autenticação ficam fixos em e-mail nesta fase (RECONCILIACAO §2):
 * os chips aparecem desabilitados para deixar claro o que está em uso.
 *
 * Fase 2 §2.4 (`participantRoles`): cada linha ganha o "Tipo de participante"
 * (Signatário, Testemunha, Aprovador, Visualizador) com a explicação do efeito. O
 * `role` continua sendo o rótulo livre ("Locatária"). Visualizadores não entram na ordem
 * e aparecem com o ícone de olho no lugar do número. Sem a flag, o passo é o da Fase 1.
 *
 * Fase 2, onda B — cada bloco só com a sua flag; com todas desligadas, o passo é o de antes:
 * - `channels.enabled` (§2.9, `sms_whatsapp`): celular, canal do convite e canal do código,
 *   com o motivo quando SMS/WhatsApp estão indisponíveis e o selo "simulado" no simulador;
 * - `channels.pin.enabled` (§2.9, `pin_auth`): PIN do remetente por participante;
 * - `captureEnabled` (§2.10, `identity_capture`): fotos exigidas antes do aceite.
 */
export function WizardStepRecipients({
    recipients,
    signingOrder,
    roleSuggestions,
    onChange,
    onSigningOrderChange,
    errors,
    disabled,
    participantRoles = false,
    roleOptions,
    envelopeId,
    channels = null,
    captureEnabled = false,
    captureRequirements = {},
    onCaptureRequirementChange,
}: {
    recipients: WizardRecipient[];
    signingOrder: SigningOrder;
    roleSuggestions: string[];
    onChange: (recipients: WizardRecipient[]) => void;
    onSigningOrderChange: (order: SigningOrder) => void;
    errors: Record<string, string>;
    disabled?: boolean;
    participantRoles?: boolean;
    roleOptions?: ParticipantRoleOption[];
    envelopeId?: string;
    channels?: WizardChannels | null;
    captureEnabled?: boolean;
    /** `{ [recipientUlid]: kinds[] }` (`IdentityCaptures::requirementsForEnvelope`). */
    captureRequirements?: Record<string, CaptureKind[]>;
    onCaptureRequirementChange?: (
        recipientId: string,
        kinds: CaptureKind[],
    ) => void;
}) {
    const channelsEnabled = channels?.enabled === true;
    const pinEnabled = channels?.pin.enabled === true;
    const [draggingIndex, setDraggingIndex] = useState<number | null>(null);

    const options: ParticipantRoleOption[] =
        roleOptions && roleOptions.length > 0
            ? roleOptions
            : ROLE_ORDER.map((value) => ({
                  value,
                  label: participantRoleLabels[value],
              }));

    const withOrder = (list: WizardRecipient[]): WizardRecipient[] =>
        list.map((recipient, index) => ({
            ...recipient,
            order: index + 1,
            color_index: index % 4,
        }));

    const update = (index: number, patch: Partial<WizardRecipient>): void => {
        onChange(
            withOrder(
                recipients.map((recipient, position) =>
                    position === index ? { ...recipient, ...patch } : recipient,
                ),
            ),
        );
    };

    const remove = (index: number): void => {
        onChange(withOrder(recipients.filter((_, i) => i !== index)));
    };

    const moveTo = (from: number, to: number): void => {
        if (to < 0 || to >= recipients.length || from === to) {
            return;
        }

        const next = [...recipients];
        const [moved] = next.splice(from, 1);
        next.splice(to, 0, moved);
        onChange(withOrder(next));
    };

    const add = (role: string, participantRole?: ParticipantRole): void => {
        onChange(
            withOrder([
                ...recipients,
                {
                    id: null,
                    client_id: crypto.randomUUID(),
                    name: '',
                    email: '',
                    role,
                    order: recipients.length + 1,
                    color_index: recipients.length % 4,
                    channel: 'email',
                    auth_methods: ['email_otp'],
                    ...(participantRoles && participantRole
                        ? {
                              participant_role: participantRole,
                              participant_role_label:
                                  participantRoleLabels[participantRole],
                          }
                        : {}),
                },
            ]),
        );
    };

    // Posição na vez (só quem participa): o visualizador não tem número.
    let turn = 0;
    const turnOf = recipients.map((recipient) =>
        participantRoles && roleOf(recipient) === 'viewer' ? null : ++turn,
    );

    const hints = participantRoles ? ORDER_HINTS_WITH_ROLES : ORDER_HINTS;
    const full = recipients.length >= 20;

    return (
        <div className="flex flex-col gap-4">
            <div className="border-border bg-card shadow-card flex flex-wrap items-center justify-between gap-3 rounded-xl border px-5 py-3.5">
                <div>
                    <div className="text-[14px] font-semibold">
                        Ordem de assinatura
                    </div>
                    <div className="text-muted-foreground mt-0.5 text-[12.5px]">
                        {hints[signingOrder]}
                    </div>
                </div>
                <SegmentedControl
                    value={signingOrder}
                    onChange={onSigningOrderChange}
                    ariaLabel="Ordem de assinatura"
                    options={[
                        {
                            value: 'sequential',
                            label: signingOrderLabels.sequential,
                            disabled,
                        },
                        {
                            value: 'parallel',
                            label: signingOrderLabels.parallel,
                            disabled,
                        },
                    ]}
                />
            </div>

            {recipients.map((recipient, index) => {
                const color = recipientColor(index);
                const participantRole = roleOf(recipient);
                const viewer = participantRoles && participantRole === 'viewer';
                const fallbackName = participantRoles
                    ? `${participantRoleLabels[participantRole]} ${index + 1}`
                    : `Signatário ${index + 1}`;

                return (
                    <div
                        key={recipient.client_id}
                        draggable={!disabled}
                        onDragStart={() => setDraggingIndex(index)}
                        onDragEnd={() => setDraggingIndex(null)}
                        onDragOver={(event) => {
                            if (draggingIndex === null) {
                                return;
                            }

                            event.preventDefault();
                        }}
                        onDrop={(event) => {
                            event.preventDefault();

                            if (draggingIndex !== null) {
                                moveTo(draggingIndex, index);
                                setDraggingIndex(null);
                            }
                        }}
                        className={cn(
                            'border-border bg-card shadow-card flex flex-col gap-3.5 rounded-xl border px-5 py-[18px]',
                            draggingIndex === index && 'opacity-60',
                        )}
                    >
                        <div className="flex items-center gap-3">
                            <span
                                aria-hidden
                                className="text-muted-foreground hidden cursor-grab md:block"
                                title="Arraste para reordenar"
                            >
                                <GripVertical className="size-4" />
                            </span>
                            <span
                                style={{
                                    backgroundColor: color.soft,
                                    color: color.text,
                                }}
                                title={
                                    viewer
                                        ? 'Visualizador: não entra na ordem'
                                        : undefined
                                }
                                className="tabular flex size-7 shrink-0 items-center justify-center rounded-lg text-[12.5px] font-bold"
                            >
                                {viewer ? (
                                    <Eye className="size-3.5" />
                                ) : participantRoles ? (
                                    turnOf[index]
                                ) : (
                                    index + 1
                                )}
                            </span>
                            <span className="min-w-0 flex-1 truncate text-[14px] font-semibold">
                                {recipient.name || fallbackName}
                                {participantRoles &&
                                    participantRole !== 'signer' && (
                                        <span className="text-muted-foreground font-medium">
                                            {' '}
                                            ·{' '}
                                            {participantRoleLabels[
                                                participantRole
                                            ].toLowerCase()}
                                        </span>
                                    )}
                            </span>

                            <div className="flex items-center gap-1">
                                <Button
                                    variant="ghost"
                                    size="icon-xs"
                                    aria-label={`Mover ${recipient.name || fallbackName.toLowerCase()} para cima`}
                                    disabled={disabled || index === 0}
                                    onClick={() => moveTo(index, index - 1)}
                                >
                                    <ChevronUp className="size-4" />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="icon-xs"
                                    aria-label={`Mover ${recipient.name || fallbackName.toLowerCase()} para baixo`}
                                    disabled={
                                        disabled ||
                                        index === recipients.length - 1
                                    }
                                    onClick={() => moveTo(index, index + 1)}
                                >
                                    <ChevronDown className="size-4" />
                                </Button>
                            </div>

                            <Select
                                value={recipient.role || 'Parte'}
                                disabled={disabled}
                                onValueChange={(value) =>
                                    update(index, { role: value })
                                }
                            >
                                <SelectTrigger
                                    size="sm"
                                    className="h-[30px] w-auto min-w-[120px] text-[12.5px] font-semibold"
                                    aria-label={
                                        participantRoles
                                            ? 'Papel no documento (rótulo livre)'
                                            : 'Papel do signatário'
                                    }
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {[
                                        ...new Set([
                                            ...roleSuggestions,
                                            recipient.role || 'Parte',
                                        ]),
                                    ]
                                        .filter(Boolean)
                                        .map((role) => (
                                            <SelectItem key={role} value={role}>
                                                {role}
                                            </SelectItem>
                                        ))}
                                </SelectContent>
                            </Select>

                            <Button
                                variant="ghost"
                                size="icon-xs"
                                title="Remover"
                                aria-label={`Remover ${recipient.name || fallbackName.toLowerCase()}`}
                                disabled={disabled || recipients.length <= 1}
                                onClick={() => remove(index)}
                                className="hover:bg-danger-bg hover:text-danger"
                            >
                                <Trash2 className="size-[15px]" />
                            </Button>
                        </div>

                        {participantRoles && (
                            <div className="flex flex-wrap items-start gap-3">
                                <div className="grid w-full gap-1.5 sm:w-[220px]">
                                    <Label
                                        htmlFor={`recipient-participant-role-${recipient.client_id}`}
                                        className="text-[12.5px]"
                                    >
                                        Tipo de participante
                                    </Label>
                                    <Select
                                        value={participantRole}
                                        disabled={disabled}
                                        onValueChange={(value) =>
                                            update(index, {
                                                participant_role:
                                                    value as ParticipantRole,
                                                participant_role_label:
                                                    options.find(
                                                        (option) =>
                                                            option.value ===
                                                            value,
                                                    )?.label ??
                                                    participantRoleLabels[
                                                        value as ParticipantRole
                                                    ],
                                            })
                                        }
                                    >
                                        <SelectTrigger
                                            id={`recipient-participant-role-${recipient.client_id}`}
                                            size="sm"
                                            className="w-full"
                                            aria-invalid={Boolean(
                                                errors[
                                                    `recipients.${index}.participant_role`
                                                ],
                                            )}
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {options.map((option) => (
                                                <SelectItem
                                                    key={option.value}
                                                    value={option.value}
                                                >
                                                    {option.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                                <p className="text-muted-foreground min-w-0 flex-1 basis-[240px] pt-0 text-[12.5px] leading-[1.5] sm:pt-6">
                                    {
                                        participantRoleDescriptions[
                                            participantRole
                                        ]
                                    }
                                </p>
                                <InputError
                                    className="w-full"
                                    message={
                                        errors[
                                            `recipients.${index}.participant_role`
                                        ]
                                    }
                                />
                            </div>
                        )}

                        <div
                            className="grid gap-3"
                            style={{
                                gridTemplateColumns:
                                    'repeat(auto-fit, minmax(200px, 1fr))',
                            }}
                        >
                            <div className="grid gap-1.5">
                                <Label
                                    htmlFor={`recipient-name-${recipient.client_id}`}
                                    className="text-[12.5px]"
                                >
                                    Nome completo
                                </Label>
                                <Input
                                    id={`recipient-name-${recipient.client_id}`}
                                    value={recipient.name}
                                    placeholder={
                                        NAME_PLACEHOLDER[
                                            recipient.participant_role ??
                                                'signer'
                                        ]
                                    }
                                    maxLength={120}
                                    disabled={disabled}
                                    aria-invalid={Boolean(
                                        errors[`recipients.${index}.name`],
                                    )}
                                    onChange={(event) =>
                                        update(index, {
                                            name: event.target.value,
                                        })
                                    }
                                />
                                <InputError
                                    message={errors[`recipients.${index}.name`]}
                                />
                            </div>
                            <div className="grid gap-1.5">
                                <Label
                                    htmlFor={`recipient-email-${recipient.client_id}`}
                                    className="text-[12.5px]"
                                >
                                    E-mail
                                </Label>
                                <Input
                                    id={`recipient-email-${recipient.client_id}`}
                                    type="email"
                                    inputMode="email"
                                    autoComplete="off"
                                    value={recipient.email}
                                    placeholder="email@exemplo.com"
                                    maxLength={255}
                                    disabled={disabled}
                                    aria-invalid={Boolean(
                                        errors[`recipients.${index}.email`],
                                    )}
                                    onChange={(event) =>
                                        update(index, {
                                            email: event.target.value,
                                        })
                                    }
                                />
                                <InputError
                                    message={
                                        errors[`recipients.${index}.email`]
                                    }
                                />
                            </div>
                        </div>

                        {channelsEnabled && channels ? (
                            <RecipientChannelFields
                                recipient={recipient}
                                index={index}
                                channels={channels}
                                errors={errors}
                                disabled={disabled}
                                participantRoles={participantRoles}
                                onChange={(patch) => update(index, patch)}
                            />
                        ) : (
                            <div className="flex flex-wrap items-end gap-6">
                                <div className="flex flex-col gap-1.5">
                                    <span className="text-[12.5px] font-semibold">
                                        Enviar por
                                    </span>
                                    <span className="bg-muted text-text-secondary inline-flex items-center gap-[5px] rounded-md px-[7px] py-1 text-[11.5px] font-semibold">
                                        <Mail className="size-3" />
                                        E-mail
                                    </span>
                                </div>
                                <div className="flex flex-1 flex-col gap-1.5">
                                    <span className="text-[12.5px] font-semibold">
                                        {participantRoles
                                            ? 'Como o participante se autentica'
                                            : 'Como o signatário se autentica'}
                                    </span>
                                    <div className="flex flex-wrap gap-1.5">
                                        <SelectableChip selected disabled>
                                            Código por e-mail
                                        </SelectableChip>
                                        <SelectableChip
                                            selected={false}
                                            disabled
                                        >
                                            Token SMS · não ativado
                                        </SelectableChip>
                                    </div>
                                </div>
                            </div>
                        )}

                        {pinEnabled && channels && (
                            <div className="border-muted border-t pt-3">
                                <RecipientPinControl
                                    recipient={recipient}
                                    index={index}
                                    pin={channels.pin}
                                    errors={errors}
                                    disabled={disabled}
                                    onChange={(patch) => update(index, patch)}
                                />
                            </div>
                        )}

                        {captureEnabled && envelopeId && !viewer && (
                            <div className="border-muted border-t pt-3">
                                <CaptureRequirementControl
                                    envelopeId={envelopeId}
                                    recipientId={recipient.id}
                                    recipientName={recipient.name}
                                    kinds={
                                        recipient.id
                                            ? (captureRequirements[
                                                  recipient.id
                                              ] ?? [])
                                            : []
                                    }
                                    disabled={disabled}
                                    onSaved={(kinds) =>
                                        recipient.id &&
                                        onCaptureRequirementChange?.(
                                            recipient.id,
                                            kinds,
                                        )
                                    }
                                />
                            </div>
                        )}

                        {/* Fase 3 §3.3 (F-VIDEO): sem a flag `identity_video`, não renderiza nada. */}
                        {envelopeId && !viewer && (
                            <VideoRequirementControl
                                envelopeId={envelopeId}
                                recipientId={recipient.id}
                                recipientName={recipient.name}
                                disabled={disabled}
                            />
                        )}

                        {/* Fase 3 §3.3 (F-I18N): sem a flag `multilingual`, não renderiza nada. */}
                        {envelopeId && (
                            <RecipientLocaleControl
                                envelopeId={envelopeId}
                                recipientId={recipient.id}
                                disabled={disabled}
                            />
                        )}
                    </div>
                );
            })}

            <InputError message={errors.recipients} />

            <div className="flex flex-wrap gap-2">
                <Button
                    variant="dashed"
                    disabled={disabled || full}
                    onClick={() => add('Parte', 'signer')}
                >
                    <UserPlus className="size-[15px]" />
                    Adicionar signatário
                </Button>
                <Button
                    variant="dashed"
                    disabled={disabled || full}
                    onClick={() => add('Testemunha', 'witness')}
                >
                    <Eye className="size-[15px]" />
                    Adicionar testemunha
                </Button>
                {participantRoles && (
                    <>
                        <Button
                            variant="dashed"
                            disabled={disabled || full}
                            onClick={() => add('Aprovador', 'approver')}
                        >
                            <CheckCircle2 className="size-[15px]" />
                            Adicionar aprovador
                        </Button>
                        <Button
                            variant="dashed"
                            disabled={disabled || full}
                            onClick={() => add('Cópia', 'viewer')}
                        >
                            <Eye className="size-[15px]" />
                            Adicionar visualizador
                        </Button>
                    </>
                )}
            </div>

            {full && (
                <p className="text-muted-foreground text-[12.5px]">
                    {participantRoles
                        ? 'Máximo de 20 participantes por documento.'
                        : 'Máximo de 20 signatários por documento.'}
                </p>
            )}
        </div>
    );
}
