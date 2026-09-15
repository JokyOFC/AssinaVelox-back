import { CheckCircle2, UserRoundCog } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { getJson } from '@/components/dossier/http';
import { postJson } from '@/components/identity/http';
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
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useI18n } from '@/i18n';
import {
    show as delegationShow,
    store as delegationStore,
} from '@/routes/sign/delegation';

/** Contrato de `GET sign.delegation.show` (App\Services\Envelopes\Delegation\DelegationService::state). */
interface DelegationState {
    can_delegate: boolean;
    requires_confirmation: boolean;
    /** A exigência de vídeo curto passa à pessoa indicada (revisão adversarial da onda F). */
    video_required?: boolean;
    pending: {
        id: string;
        to_name: string;
        to_email_masked: string;
        requested_at: string;
    } | null;
    rejected: { rejected_at: string | null; note: string | null } | null;
    received_from: { name: string; delegated_at: string | null } | null;
    limits: { name_max: number; reason_min: number; reason_max: number };
}

interface DelegationResult {
    status: 'pending' | 'effective';
    to_email_masked: string;
    message: string;
}

type FieldKey = 'name' | 'email' | 'reason';

/**
 * "Delegar a outra pessoa" na página pública (Fase 3 §3.3 — docs/fase-3/etapas-e-delegacao.md
 * §3.2). O recurso é descoberto por `GET sign.delegation.show`: 404 (flag desligada, remetente
 * não permitiu, participação pessoal, sem a sessão do código) = o cartão não aparece.
 *
 * O delegado é OUTRA pessoa, com convite, código e aceite próprios. Quando a delegação vale,
 * o link desta pessoa deixa de funcionar — a tela diz isso antes e depois.
 *
 * Fase 3 §3.3 (F-I18N): textos pelo dicionário; as mensagens do servidor chegam já traduzidas
 * pelo catálogo (`ApplySignerLocale`).
 */
export function DelegationCard({
    token,
    noun = 'assinatura',
}: {
    token: string;
    /** O que se delega: "assinatura" (padrão) ou "aprovação". */
    noun?: 'assinatura' | 'aprovação';
}) {
    const i18n = useI18n();
    const { t, rich } = i18n;
    const [state, setState] = useState<DelegationState | null>(null);
    const [open, setOpen] = useState(false);
    const [done, setDone] = useState<DelegationResult | null>(null);
    const [busy, setBusy] = useState(false);
    const [form, setForm] = useState({ name: '', email: '', reason: '' });
    const [errors, setErrors] = useState<Partial<Record<FieldKey, string>>>({});
    const [alert, setAlert] = useState<string | null>(null);

    const load = useCallback(async () => {
        const response = await getJson<DelegationState>(
            delegationShow(token).url,
        );

        setState(response.ok && response.body ? response.body : null);
    }, [token]);

    useEffect(() => {
        void load();
    }, [load]);

    if (done?.status === 'effective') {
        return (
            <div
                role="alertdialog"
                aria-labelledby="delegation-done-title"
                className="bg-background/95 fixed inset-0 z-50 flex items-center justify-center p-6"
            >
                <div className="border-border bg-card shadow-card flex max-w-[440px] flex-col items-center gap-3 rounded-xl border p-8 text-center">
                    <span className="bg-success-bg text-success flex size-12 items-center justify-center rounded-xl">
                        <CheckCircle2 className="size-5" />
                    </span>
                    <p
                        id="delegation-done-title"
                        className="text-[16px] font-semibold"
                    >
                        {t('delegation.done_title')}
                    </p>
                    <p className="text-text-secondary text-[13px] leading-[1.55]">
                        {done.message} {t('delegation.done_close')}
                    </p>
                </div>
            </div>
        );
    }

    if (state === null) {
        return null;
    }

    const submit = async () => {
        setBusy(true);
        setErrors({});
        setAlert(null);

        const response = await postJson<
            DelegationResult & {
                message?: string;
                errors?: Record<string, string[]>;
            }
        >(delegationStore(token).url, form);

        setBusy(false);

        if (response.ok && response.body) {
            setOpen(false);
            setDone(response.body);

            if (response.body.status === 'pending') {
                setForm({ name: '', email: '', reason: '' });
                void load();
            }

            return;
        }

        const body = response.body;
        const fieldErrors: Partial<Record<FieldKey, string>> = {};

        for (const key of ['name', 'email', 'reason'] as FieldKey[]) {
            const message = body?.errors?.[key]?.[0];

            if (message) {
                fieldErrors[key] = message;
            }
        }

        setErrors(fieldErrors);
        setAlert(
            Object.keys(fieldErrors).length > 0
                ? null
                : (body?.message ??
                      (response.network
                          ? t('delegation.error_network')
                          : t('delegation.error_generic'))),
        );
    };

    const reasonShort = form.reason.trim().length < state.limits.reason_min;
    const approval = noun === 'aprovação';

    return (
        <div className="border-border bg-card shadow-card flex flex-col gap-2 rounded-xl border p-4">
            <p className="flex items-center gap-2 text-[13.5px] font-semibold">
                <UserRoundCog className="text-primary size-4" />
                {t('delegation.title')}
            </p>

            {state.received_from && (
                <p className="text-text-secondary text-[12.5px] leading-[1.5]">
                    {rich('delegation.received_from', {
                        name: (
                            <span className="font-semibold">
                                {state.received_from.name}
                            </span>
                        ),
                    })}
                </p>
            )}

            {state.pending && (
                <p className="text-warning text-[12.5px] leading-[1.5]">
                    {t('delegation.pending', {
                        name: state.pending.to_name,
                        email: state.pending.to_email_masked,
                        date: i18n.dateTime(state.pending.requested_at),
                    })}
                </p>
            )}

            {state.rejected && !state.pending && (
                <p className="text-text-secondary text-[12.5px] leading-[1.5]">
                    {t('delegation.rejected', {
                        note: state.rejected.note
                            ? t('delegation.rejected_note', {
                                  note: state.rejected.note,
                              })
                            : '',
                    })}
                </p>
            )}

            {done?.status === 'pending' && (
                <p role="status" className="text-success text-[12.5px]">
                    {done.message}
                </p>
            )}

            {state.can_delegate && (
                <>
                    <p className="text-muted-foreground text-[12.5px] leading-[1.5]">
                        {t('delegation.prompt')}
                    </p>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="self-start"
                        onClick={() => setOpen(true)}
                    >
                        {t('delegation.open')}
                    </Button>
                </>
            )}

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="sm:max-w-[460px]">
                    <DialogHeader>
                        <DialogTitle>
                            {approval
                                ? t('delegation.dialog_title_approval')
                                : t('delegation.dialog_title_signature')}
                        </DialogTitle>
                        <DialogDescription>
                            {t('delegation.dialog_intro')}{' '}
                            {state.requires_confirmation
                                ? t('delegation.dialog_requires_confirmation')
                                : t('delegation.dialog_immediate')}
                            {state.video_required &&
                                ` ${t('delegation.dialog_video')}`}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-3">
                        <div className="grid gap-1.5">
                            <Label htmlFor="delegation-name">
                                {t('delegation.name')}{' '}
                                <span className="text-danger">*</span>
                            </Label>
                            <Input
                                id="delegation-name"
                                value={form.name}
                                maxLength={state.limits.name_max}
                                aria-invalid={Boolean(errors.name)}
                                onChange={(event) =>
                                    setForm({
                                        ...form,
                                        name: event.target.value,
                                    })
                                }
                            />
                            {errors.name && (
                                <p
                                    role="alert"
                                    className="text-danger text-[12.5px]"
                                >
                                    {errors.name}
                                </p>
                            )}
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="delegation-email">
                                {t('delegation.email')}{' '}
                                <span className="text-danger">*</span>
                            </Label>
                            <Input
                                id="delegation-email"
                                type="email"
                                value={form.email}
                                maxLength={255}
                                aria-invalid={Boolean(errors.email)}
                                onChange={(event) =>
                                    setForm({
                                        ...form,
                                        email: event.target.value,
                                    })
                                }
                            />
                            {errors.email && (
                                <p
                                    role="alert"
                                    className="text-danger text-[12.5px]"
                                >
                                    {errors.email}
                                </p>
                            )}
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="delegation-reason">
                                {t('delegation.reason')}{' '}
                                <span className="text-danger">*</span>
                            </Label>
                            <Textarea
                                id="delegation-reason"
                                rows={3}
                                value={form.reason}
                                maxLength={state.limits.reason_max}
                                aria-invalid={Boolean(errors.reason)}
                                placeholder={t('delegation.reason_placeholder')}
                                onChange={(event) =>
                                    setForm({
                                        ...form,
                                        reason: event.target.value,
                                    })
                                }
                            />
                            <p className="text-muted-foreground flex justify-between text-[12px]">
                                <span>
                                    {t('delegation.reason_hint', {
                                        min: state.limits.reason_min,
                                    })}
                                </span>
                                <span className="tabular">
                                    {form.reason.length}/
                                    {state.limits.reason_max}
                                </span>
                            </p>
                            {errors.reason && (
                                <p
                                    role="alert"
                                    className="text-danger text-[12.5px]"
                                >
                                    {errors.reason}
                                </p>
                            )}
                        </div>
                        {alert && (
                            <p
                                role="alert"
                                className="text-danger text-[12.5px]"
                            >
                                {alert}
                            </p>
                        )}
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setOpen(false)}
                        >
                            {t('common.back_to_document')}
                        </Button>
                        <Button
                            type="button"
                            disabled={
                                busy ||
                                reasonShort ||
                                form.name.trim().length < 2 ||
                                form.email.trim() === ''
                            }
                            onClick={() => void submit()}
                        >
                            {busy && <Spinner className="size-4" />}
                            {t('delegation.submit')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
