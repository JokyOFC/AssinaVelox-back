import {
    CheckCircle2,
    Info,
    Send,
    ShieldCheck,
    TriangleAlert,
    XCircle,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { getJson } from '@/components/dossier/http';
import { postJson } from '@/components/identity/http';
import type {
    IdentityVerificationDocumentType,
    IdentityVerificationResponse,
    IdentityVerificationStatus,
    IdentityVerificationStep,
} from '@/components/identity/verification-types';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Spinner } from '@/components/ui/spinner';
import { useI18n } from '@/i18n';
import { cn } from '@/lib/utils';

/**
 * Tom da pílula de estado: repete o `status_label` do servidor, só muda a cor. Usado também
 * pelo painel do remetente (`identity-verification-panel`).
 */
export const STATUS_VARIANT: Record<
    IdentityVerificationStatus,
    'neutral' | 'info' | 'success' | 'danger' | 'warning'
> = {
    none: 'neutral',
    queued: 'info',
    pending: 'info',
    approved: 'success',
    rejected: 'danger',
    expired: 'danger',
    inconclusive: 'warning',
};

/** Tentativa aberta: a tela consulta o servidor até o provedor responder (ou o prazo vencer). */
function inFlight(step: IdentityVerificationStep): boolean {
    return step.status === 'queued' || step.status === 'pending';
}

/** Tipo pré-escolhido: o da última tentativa quando ainda é oferecido, senão o primeiro. */
function initialDocumentType(
    step: IdentityVerificationStep,
): IdentityVerificationDocumentType | null {
    const latest = step.document_types.find(
        (type) => type.value === step.document_type,
    );

    return latest?.value ?? step.document_types[0]?.value ?? null;
}

/**
 * Estado da etapa na página pública: o bloco do servidor, substituído pela resposta de cada
 * envio, de cada consulta e de cada foto nova (`CaptureController@store` também o devolve).
 * `ready` libera o aceite só com "aprovado" informado pelo provedor sobre as fotos ATUAIS —
 * o servidor exige o mesmo (`identity_verification_required`).
 */
export function useVerificationStep(
    initial: IdentityVerificationStep | null | undefined,
) {
    const [step, setStep] = useState<IdentityVerificationStep | null>(
        initial ?? null,
    );

    useEffect(() => {
        setStep(initial ?? null);
    }, [initial]);

    return {
        step,
        setStep,
        ready:
            step === null ||
            (step.status === 'approved' && !step.captures_changed),
    };
}

/**
 * Etapa "Verificação facial com documento" (Fase 4 §4.1, prop `identity_verification`).
 *
 * As três fotos são as da etapa de captura, logo acima. Aqui a pessoa escolhe o tipo do
 * documento, marca a autorização (que cita o provedor) e envia: `POST urls.store` abre a
 * tentativa (`queued`) e a tela passa a consultar `GET urls.show` a cada `poll_interval_ms`
 * até o resultado ser conclusivo. Quem compara as imagens é o provedor; a tela só repete a
 * frase que o servidor montou com o que ele informou ("Verifiky informou: aprovado").
 *
 * O botão obedece ao `can_submit` do servidor (fotos completas, tentativas restantes, nada em
 * andamento, ainda não aprovada — ou aprovada sobre fotos que foram refeitas).
 */
export function VerificationStepCard({
    step,
    onChange,
    className,
}: {
    step: IdentityVerificationStep;
    onChange: (next: IdentityVerificationStep) => void;
    className?: string;
}) {
    const i18n = useI18n();
    const { t } = i18n;
    const [documentType, setDocumentType] =
        useState<IdentityVerificationDocumentType | null>(() =>
            initialDocumentType(step),
        );
    const [consent, setConsent] = useState(false);
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [pollNotice, setPollNotice] = useState<string | null>(null);

    // A consulta periódica chama o `onChange` mais recente sem reiniciar o relógio a cada render.
    const onChangeRef = useRef(onChange);

    useEffect(() => {
        onChangeRef.current = onChange;
    }, [onChange]);

    const polling = inFlight(step);
    const showUrl = step.urls.show;
    const interval = Math.max(1000, step.poll_interval_ms || 3000);

    // F-I18N: `consent.reviewed` só vem fora do PT-BR; `false` = tradução ainda não revisada.
    const consentCourtesy =
        !i18n.isReference && step.consent.reviewed === false;

    /*
     * Consulta encadeada (uma de cada vez, nunca duas no ar) enquanto a tentativa está na fila
     * ou em análise. Para sozinha quando o estado deixa de ser `queued`/`pending` (o efeito é
     * desmontado) ou quando a etapa some da tela. Rede ou tempo esgotado NÃO param a consulta:
     * a pessoa vê o aviso e a próxima tentativa acontece no intervalo de sempre (T5).
     */
    useEffect(() => {
        if (!polling || !showUrl) {
            return;
        }

        let alive = true;
        let timer = 0;
        let failures = 0;

        const tick = async () => {
            const response = await getJson<IdentityVerificationResponse>(
                showUrl,
                { timeoutMs: 15000 },
            );

            if (!alive) {
                return;
            }

            if (response.ok && response.body?.identity_verification) {
                failures = 0;
                setPollNotice(null);
                onChangeRef.current(response.body.identity_verification);
            } else if (response.status === 404) {
                setPollNotice(t('verification.error.not_required'));

                return;
            } else if (response.status === 401 || response.status === 419) {
                setPollNotice(t('common.session_expired'));

                return;
            } else {
                failures += 1;

                if (failures >= 3) {
                    setPollNotice(t('verification.error.poll'));
                }
            }

            timer = window.setTimeout(tick, interval);
        };

        timer = window.setTimeout(tick, interval);

        return () => {
            alive = false;
            window.clearTimeout(timer);
        };
    }, [polling, showUrl, interval, t]);

    /** Uma consulta avulsa — usada quando não sabemos se o envio chegou. */
    const refresh = async () => {
        if (!showUrl) {
            return;
        }

        const response = await getJson<IdentityVerificationResponse>(showUrl, {
            timeoutMs: 15000,
        });

        if (response.ok && response.body?.identity_verification) {
            onChangeRef.current(response.body.identity_verification);
        }
    };

    const submit = async () => {
        if (!step.urls.store) {
            setError(t('verification.confirm_code_first'));

            return;
        }

        if (documentType === null) {
            return;
        }

        setSubmitting(true);
        setError(null);

        const response = await postJson<IdentityVerificationResponse>(
            step.urls.store,
            { document_type: documentType, consent: true },
            { timeoutMs: 30000 },
        );

        setSubmitting(false);

        if (response.ok && response.body?.identity_verification) {
            onChange(response.body.identity_verification);
            // A autorização vale para ESTE envio; um reenvio pede a marca de novo.
            setConsent(false);
            toast.success(t('verification.sent'));

            return;
        }

        if (response.status === 0) {
            // Tempo esgotado ou rede: não sabemos se chegou (T5). Se a tentativa nasceu, a
            // consulta a traz como `queued` e a consulta periódica assume dali.
            setError(t('verification.error.unknown_delivery'));
            void refresh();

            return;
        }

        if (response.status === 404) {
            setError(t('verification.error.not_required'));

            return;
        }

        if (response.status === 419 || response.status === 401) {
            setError(t('common.session_expired'));

            return;
        }

        // Recusa com o bloco atualizado (ex.: 409 "em andamento" → a tela volta a consultar).
        if (response.body?.identity_verification) {
            onChange(response.body.identity_verification);
        }

        setError(
            response.body?.errors?.verification?.[0] ??
                response.body?.errors?.document_type?.[0] ??
                response.body?.errors?.consent?.[0] ??
                response.body?.message ??
                t('verification.error.generic'),
        );
    };

    const approved = step.status === 'approved';
    const exhausted = step.attempts_left <= 0 && !approved;
    // O formulário some quando não há mais o que enviar: em análise, aprovada sobre as fotos
    // atuais, ou sem tentativas — nesses casos fica só o resultado.
    const showForm =
        !polling && !exhausted && (!approved || step.captures_changed);
    const canSend =
        step.can_submit &&
        consent &&
        documentType !== null &&
        !submitting &&
        step.urls.store !== null;

    return (
        <section
            className={cn('flex flex-col gap-3', className)}
            aria-labelledby="verification-step-title"
        >
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2
                    id="verification-step-title"
                    className="flex items-center gap-1.5 text-[13.5px] font-semibold"
                >
                    <ShieldCheck className="text-primary size-3.5" />
                    {t('verification.title')}
                </h2>
                <Badge variant={STATUS_VARIANT[step.status]} dot>
                    {step.status_label}
                </Badge>
            </div>

            <div className="border-border bg-sidebar text-text-secondary flex items-start gap-2 rounded-[10px] border p-3 text-[12.5px] leading-[1.5]">
                <Info className="text-primary mt-0.5 size-3.5 shrink-0" />
                <span>{step.notice}</span>
            </div>

            {polling && (
                <div
                    className="border-info-border bg-info-bg flex items-start gap-2.5 rounded-[10px] border p-3 text-[12.5px] leading-[1.5]"
                    role="status"
                    aria-live="polite"
                >
                    <Spinner className="text-info mt-0.5 size-4 shrink-0" />
                    <span className="flex flex-col gap-1">
                        <span className="font-semibold">
                            {t('verification.waiting')}
                        </span>
                        {step.message && (
                            <span className="text-text-secondary">
                                {step.message}
                            </span>
                        )}
                        {pollNotice && (
                            <span className="text-warning">{pollNotice}</span>
                        )}
                    </span>
                </div>
            )}

            {!polling && step.message && (
                <div
                    className={cn(
                        'flex items-start gap-2.5 rounded-[10px] border p-3 text-[12.5px] leading-[1.5]',
                        approved
                            ? 'border-success-border bg-success-bg'
                            : step.status === 'inconclusive'
                              ? 'border-warning-border bg-warning-bg'
                              : 'border-danger-border bg-danger-bg',
                    )}
                    role="status"
                    aria-live="polite"
                >
                    {approved ? (
                        <CheckCircle2 className="text-success mt-0.5 size-4 shrink-0" />
                    ) : step.status === 'inconclusive' ? (
                        <TriangleAlert className="text-warning mt-0.5 size-4 shrink-0" />
                    ) : (
                        <XCircle className="text-danger mt-0.5 size-4 shrink-0" />
                    )}
                    <span className="flex min-w-0 flex-col gap-1">
                        <span className="font-semibold">{step.message}</span>
                        {step.provider_message && (
                            <span className="text-text-secondary">
                                {step.provider_message}
                            </span>
                        )}
                    </span>
                </div>
            )}

            {approved && step.captures_changed && (
                <p className="border-warning-border bg-warning-bg flex items-start gap-2.5 rounded-[10px] border p-3 text-[12.5px] leading-[1.5]">
                    <TriangleAlert className="text-warning mt-0.5 size-4 shrink-0" />
                    <span>{t('verification.captures_changed')}</span>
                </p>
            )}

            {step.attempts_used > 0 && (
                <p className="text-muted-foreground tabular text-[12px]">
                    {t('verification.attempts', {
                        used: step.attempts_used,
                        max: step.max_attempts,
                    })}
                </p>
            )}

            {exhausted && (
                <p
                    role="alert"
                    className="border-danger-border bg-danger-bg flex items-start gap-2.5 rounded-[10px] border p-3 text-[12.5px] leading-[1.5]"
                >
                    <TriangleAlert className="text-danger mt-0.5 size-4 shrink-0" />
                    <span>{t('verification.no_attempts_left')}</span>
                </p>
            )}

            {showForm && (
                <div className="flex flex-col gap-3">
                    {!step.captures_complete && (
                        <p className="text-warning text-[12.5px] leading-[1.5]">
                            {t('verification.take_photos_first')}
                        </p>
                    )}

                    <fieldset className="flex flex-col gap-2">
                        <legend className="mb-2 text-[12.5px] font-semibold">
                            {t('verification.document_type')}
                        </legend>
                        <RadioGroup
                            value={documentType ?? ''}
                            onValueChange={(value) =>
                                setDocumentType(
                                    value as IdentityVerificationDocumentType,
                                )
                            }
                            className="flex flex-wrap gap-2"
                        >
                            {step.document_types.map((type) => {
                                const id = `verification-document-${type.value}`;

                                return (
                                    <label
                                        key={type.value}
                                        htmlFor={id}
                                        className="border-border has-[[data-state=checked]]:border-primary has-[[data-state=checked]]:bg-primary-soft/30 flex cursor-pointer items-center gap-2 rounded-[10px] border px-3 py-2 text-[13px] has-[:disabled]:cursor-not-allowed has-[:disabled]:opacity-60"
                                    >
                                        <RadioGroupItem
                                            id={id}
                                            value={type.value}
                                            disabled={submitting}
                                        />
                                        {type.label}
                                    </label>
                                );
                            })}
                        </RadioGroup>
                    </fieldset>

                    <label
                        htmlFor="verification-consent"
                        className="border-border flex items-start gap-2.5 rounded-[10px] border p-3 text-[12.5px] leading-[1.5]"
                    >
                        <Checkbox
                            id="verification-consent"
                            checked={consent}
                            disabled={submitting}
                            onCheckedChange={(value) =>
                                setConsent(value === true)
                            }
                            className="mt-0.5"
                        />
                        <span>
                            {step.consent.text}
                            {consentCourtesy && (
                                <span className="text-muted-foreground mt-1 block text-[11.5px]">
                                    {t('verification.consent_courtesy')}
                                </span>
                            )}
                        </span>
                    </label>

                    {error && (
                        <p role="alert" className="text-danger text-[12.5px]">
                            {error}
                        </p>
                    )}

                    <Button
                        type="button"
                        disabled={!canSend}
                        onClick={() => void submit()}
                        className="w-full sm:w-auto sm:self-start"
                    >
                        {submitting ? (
                            <Spinner className="size-4" />
                        ) : (
                            <Send className="size-4" />
                        )}
                        {step.attempts_used > 0
                            ? t('verification.resubmit')
                            : t('verification.submit')}
                    </Button>
                </div>
            )}
        </section>
    );
}

/**
 * Aviso na tela de identificação: o que será pedido depois do código e das fotos. Sem sessão
 * não há URL de envio (`urls` nulas), então só o aviso, nomeando o provedor.
 */
export function VerificationStepPreview({
    step,
}: {
    step: IdentityVerificationStep;
}) {
    const { rich } = useI18n();

    return (
        <div className="border-border bg-sidebar flex items-start gap-2.5 rounded-[10px] border p-3 text-[12.5px] leading-[1.5]">
            <ShieldCheck className="text-primary mt-0.5 size-4 shrink-0" />
            <span className="text-text-secondary">
                {rich('verification.preview', {
                    provider: (
                        <b className="text-foreground">{step.provider_label}</b>
                    ),
                })}
            </span>
        </div>
    );
}
