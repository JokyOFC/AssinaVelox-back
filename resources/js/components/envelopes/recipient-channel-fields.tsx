import {
    Copy,
    Info,
    KeyRound,
    Mail,
    MessageCircle,
    MessageSquare,
    RefreshCw,
    Smartphone,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import {
    checkPhone,
    formatPhoneInput,
    formatStoredPhone,
    PHONE_MESSAGES,
} from '@/components/identity/phone';
import { generatePin, pinDigits, pinProblem } from '@/components/identity/pin';
import { SelectableChip } from '@/components/filter-bar';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
import { inviteChannelLabels } from '@/lib/labels';
import type { AuthMethod, DeliveryChannel } from '@/types/enums';
import type { WizardChannels, WizardRecipient } from '@/types/models';

const CHANNEL_ORDER: DeliveryChannel[] = ['email', 'sms', 'whatsapp'];

const CHANNEL_ICONS = {
    email: Mail,
    sms: MessageSquare,
    whatsapp: MessageCircle,
} as const;

/** Método do código do participante (ausente = o primeiro de `auth_methods`). */
export function authMethodOf(recipient: WizardRecipient): AuthMethod {
    return recipient.auth_method ?? recipient.auth_methods[0] ?? 'email_otp';
}

/** O participante precisa de celular: código ou aviso de convite por SMS/WhatsApp. */
export function needsPhone(recipient: WizardRecipient): boolean {
    return (
        authMethodOf(recipient) !== 'email_otp' ||
        (recipient.channel ?? 'email') !== 'email'
    );
}

function SimulatedBadge() {
    return (
        <Badge
            variant="warning"
            className="px-1.5 py-px text-[10.5px]"
            title="Ambiente de testes: nenhuma mensagem sai de verdade."
        >
            simulado
        </Badge>
    );
}

/**
 * Canal do convite, canal do código e celular de um participante (Fase 2 §2.9,
 * docs/fase-2/canais-e-pin.md §9.1). Só aparece com a flag `sms_whatsapp`.
 *
 * - O e-mail sai sempre; SMS/WhatsApp somam um aviso com o link.
 * - O código prova a posse do canal (e-mail ou celular), não a identidade.
 * - Canal indisponível (flag, provedor desativado) fica desabilitado com o motivo que o
 *   servidor mandou — visível no texto, não só no `title`, para funcionar no celular.
 * - Provedor simulado ganha o selo "simulado" e o aviso do servidor.
 */
export function RecipientChannelFields({
    recipient,
    index,
    channels,
    errors,
    disabled,
    participantRoles,
    onChange,
}: {
    recipient: WizardRecipient;
    index: number;
    channels: WizardChannels;
    errors: Record<string, string>;
    disabled?: boolean;
    participantRoles?: boolean;
    onChange: (patch: Partial<WizardRecipient>) => void;
}) {
    const method = authMethodOf(recipient);
    const channel = recipient.channel ?? 'email';
    const phoneRequired = needsPhone(recipient);
    const phoneValue = recipient.phone ?? '';
    const phoneState = checkPhone(phoneValue);
    const [phoneTouched, setPhoneTouched] = useState(false);

    const localPhoneError =
        phoneState === 'ok'
            ? null
            : phoneState === 'invalid'
              ? PHONE_MESSAGES.invalid
              : phoneRequired && (phoneTouched || phoneState === 'empty')
                ? PHONE_MESSAGES[phoneState]
                : null;
    const phoneError =
        errors[`recipients.${index}.phone`] ?? localPhoneError ?? undefined;

    // Motivos e avisos, sem repetição (SMS e WhatsApp costumam ter o mesmo motivo).
    const unavailable = CHANNEL_ORDER.map((key) => channels.channels[key])
        .filter((info) => info && !info.available && info.reason)
        .map((info) => info.reason as string);
    const notices = CHANNEL_ORDER.map((key) => channels.channels[key])
        .filter((info) => info && info.available && info.notice)
        .map((info) => info.notice as string);

    const id = recipient.client_id;

    return (
        <div className="flex flex-col gap-3">
            <div className="grid gap-1.5 sm:max-w-[280px]">
                <Label
                    htmlFor={`recipient-phone-${id}`}
                    className="text-[12.5px]"
                >
                    Celular{' '}
                    <span className="text-muted-foreground font-normal">
                        {phoneRequired
                            ? '(obrigatório para SMS ou WhatsApp)'
                            : '(opcional)'}
                    </span>
                </Label>
                <div className="relative">
                    <Smartphone className="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2" />
                    <Input
                        id={`recipient-phone-${id}`}
                        type="tel"
                        inputMode="tel"
                        autoComplete="off"
                        className="tabular pl-8"
                        value={formatStoredPhone(phoneValue)}
                        placeholder={channels.phone.example}
                        maxLength={20}
                        disabled={disabled}
                        aria-invalid={Boolean(phoneError)}
                        aria-describedby={`recipient-phone-hint-${id}`}
                        onBlur={() => setPhoneTouched(true)}
                        onChange={(event) =>
                            onChange({
                                phone:
                                    formatPhoneInput(event.target.value) ||
                                    null,
                            })
                        }
                    />
                </div>
                <span
                    id={`recipient-phone-hint-${id}`}
                    className="text-muted-foreground text-[11.5px]"
                >
                    Só celular, com DDD. Números de outros países começam por +.
                </span>
                <InputError message={phoneError} />
            </div>

            <div className="flex flex-col gap-1.5">
                <span className="text-[12.5px] font-semibold">
                    Enviar convite por
                </span>
                <div className="flex flex-wrap items-center gap-1.5">
                    {CHANNEL_ORDER.map((key) => {
                        const info = channels.channels[key];
                        const Icon = CHANNEL_ICONS[key];
                        const available = key === 'email' || info?.available;

                        return (
                            <SelectableChip
                                key={key}
                                selected={channel === key}
                                disabled={
                                    disabled || (!available && channel !== key)
                                }
                                onClick={() => onChange({ channel: key })}
                            >
                                <Icon className="size-3" />
                                {inviteChannelLabels[key]}
                                {key !== 'email' &&
                                    info?.simulated &&
                                    available && <SimulatedBadge />}
                            </SelectableChip>
                        );
                    })}
                </div>
                <span className="text-muted-foreground text-[11.5px] leading-[1.5]">
                    O convite sempre sai por e-mail. SMS ou WhatsApp enviam
                    também um aviso com o link.
                </span>
                <InputError message={errors[`recipients.${index}.channel`]} />
            </div>

            <div className="flex flex-col gap-1.5">
                <span className="text-[12.5px] font-semibold">
                    {participantRoles
                        ? 'Como o participante se autentica'
                        : 'Como o signatário se autentica'}
                </span>
                <div className="flex flex-wrap items-center gap-1.5">
                    {channels.auth_methods.map((option) => (
                        <SelectableChip
                            key={option.value}
                            selected={method === option.value}
                            disabled={
                                disabled ||
                                (!option.available && method !== option.value)
                            }
                            onClick={() =>
                                onChange({
                                    auth_method: option.value,
                                    auth_methods: [option.value],
                                    auth_method_label: option.label,
                                })
                            }
                        >
                            {option.label}
                            {option.simulated && option.available && (
                                <SimulatedBadge />
                            )}
                        </SelectableChip>
                    ))}
                </div>
                <span className="text-muted-foreground text-[11.5px] leading-[1.5]">
                    O código confirma que a pessoa tem acesso ao e-mail ou ao
                    celular informado. Não confirma quem ela é.
                </span>
                <InputError
                    message={errors[`recipients.${index}.auth_method`]}
                />
            </div>

            {(unavailable.length > 0 || notices.length > 0) && (
                <div className="flex flex-col gap-1">
                    {[...new Set(unavailable)].map((reason) => (
                        <p
                            key={reason}
                            className="text-muted-foreground flex items-start gap-1.5 text-[11.5px] leading-[1.5]"
                        >
                            <Info className="mt-px size-3 shrink-0" />
                            {reason}
                        </p>
                    ))}
                    {[...new Set(notices)].map((notice) => (
                        <p
                            key={notice}
                            className="text-warning flex items-start gap-1.5 text-[11.5px] leading-[1.5]"
                        >
                            <Info className="mt-px size-3 shrink-0" />
                            {notice}
                        </p>
                    ))}
                </div>
            )}
        </div>
    );
}

/**
 * PIN do remetente (Fase 2 §2.9, flag `pin_auth`): segredo que quem envia combina com o
 * participante por fora do AssinaVelox. É pedido depois do código e nunca o substitui.
 *
 * O PIN digitado fica só no estado local até o próximo `recipients.sync`, que o envia uma
 * única vez; depois disso a tela só sabe que existe (`has_pin`). Ele não é exibido de novo.
 */
export function RecipientPinControl({
    recipient,
    index,
    pin,
    errors,
    disabled,
    onChange,
}: {
    recipient: WizardRecipient;
    index: number;
    pin: WizardChannels['pin'];
    errors: Record<string, string>;
    disabled?: boolean;
    onChange: (patch: Partial<WizardRecipient>) => void;
}) {
    const [open, setOpen] = useState(false);
    const [draft, setDraft] = useState('');
    const [problem, setProblem] = useState<string | null>(null);

    const pending = typeof recipient.pin === 'string' && recipient.pin !== '';
    const removing = recipient.remove_pin === true;
    const saved = recipient.has_pin === true;
    const serverError = errors[`recipients.${index}.pin`];

    const openDialog = () => {
        setDraft('');
        setProblem(null);
        setOpen(true);
    };

    const confirm = () => {
        const issue = pinProblem(draft, pin.min_length, pin.max_length);

        if (issue) {
            setProblem(issue);

            return;
        }

        onChange({ pin: draft, remove_pin: false });
        setDraft('');
        setOpen(false);
    };

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(draft);
            toast.success(
                'PIN copiado. Combine-o com o participante por fora do AssinaVelox.',
            );
        } catch {
            toast.error(
                'Não foi possível copiar. Anote o PIN antes de salvar.',
            );
        }
    };

    return (
        <div className="flex flex-col gap-1.5">
            <span className="flex items-center gap-1.5 text-[12.5px] font-semibold">
                <KeyRound className="size-3.5" />
                PIN do remetente{' '}
                <span className="text-muted-foreground font-normal">
                    (opcional)
                </span>
            </span>

            <div className="flex flex-wrap items-center gap-2 text-[12.5px]">
                {removing ? (
                    <>
                        <span className="text-text-secondary">
                            O PIN será removido ao salvar.
                        </span>
                        <Button
                            variant="link"
                            size="sm"
                            className="h-auto px-0"
                            disabled={disabled}
                            onClick={() => onChange({ remove_pin: false })}
                        >
                            Desfazer
                        </Button>
                    </>
                ) : pending ? (
                    <>
                        <Badge variant="info">PIN novo · salvando</Badge>
                        <Button
                            variant="link"
                            size="sm"
                            className="h-auto px-0"
                            disabled={disabled}
                            onClick={() => onChange({ pin: undefined })}
                        >
                            Descartar
                        </Button>
                    </>
                ) : saved ? (
                    <>
                        <Badge variant="success">PIN definido</Badge>
                        <Button
                            variant="outline"
                            size="xs"
                            disabled={disabled}
                            onClick={openDialog}
                        >
                            Trocar PIN
                        </Button>
                        <Button
                            variant="ghost"
                            size="xs"
                            disabled={disabled}
                            className="hover:bg-danger-bg hover:text-danger"
                            onClick={() =>
                                onChange({ remove_pin: true, pin: undefined })
                            }
                        >
                            Remover PIN
                        </Button>
                    </>
                ) : (
                    <Button
                        variant="outline"
                        size="xs"
                        disabled={disabled}
                        onClick={openDialog}
                    >
                        Definir PIN
                    </Button>
                )}
            </div>
            <span className="text-muted-foreground text-[11.5px] leading-[1.5]">
                Pedido depois do código. Você combina o PIN com o participante
                por fora do AssinaVelox.
            </span>
            <InputError message={serverError} />

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            PIN para {recipient.name || 'o participante'}
                        </DialogTitle>
                        <DialogDescription>{pin.notice}</DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-1.5">
                        <Label htmlFor={`pin-${recipient.client_id}`}>
                            PIN ({pin.min_length} a {pin.max_length} dígitos)
                        </Label>
                        <div className="flex gap-2">
                            <Input
                                id={`pin-${recipient.client_id}`}
                                inputMode="numeric"
                                autoComplete="off"
                                spellCheck={false}
                                className="tabular font-mono text-[16px] tracking-[.3em]"
                                value={draft}
                                maxLength={pin.max_length}
                                aria-invalid={Boolean(problem)}
                                onChange={(event) => {
                                    setDraft(
                                        pinDigits(
                                            event.target.value,
                                            pin.max_length,
                                        ),
                                    );
                                    setProblem(null);
                                }}
                                onKeyDown={(event) => {
                                    if (event.key === 'Enter') {
                                        event.preventDefault();
                                        confirm();
                                    }
                                }}
                            />
                            <Button
                                type="button"
                                variant="outline"
                                title="Gerar um PIN aleatório"
                                onClick={() => {
                                    setDraft(
                                        generatePin(
                                            Math.max(6, pin.min_length),
                                        ),
                                    );
                                    setProblem(null);
                                }}
                            >
                                <RefreshCw className="size-3.5" />
                                Gerar
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                aria-label="Copiar PIN"
                                disabled={draft === ''}
                                onClick={copy}
                            >
                                <Copy className="size-3.5" />
                            </Button>
                        </div>
                        <InputError message={problem ?? undefined} />
                    </div>

                    <p className="border-warning-border bg-warning-bg text-warning rounded-[10px] border p-2.5 text-[12px] leading-[1.5]">
                        Anote o PIN agora: depois de salvo, ele não é exibido de
                        novo, nem para você. O AssinaVelox não envia o PIN ao
                        participante.
                    </p>

                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setOpen(false)}
                        >
                            Cancelar
                        </Button>
                        <Button onClick={confirm} disabled={draft === ''}>
                            Salvar PIN
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
