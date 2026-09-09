import { Head, router, usePage } from '@inertiajs/react';
import { Check, FileLock2, PenLine, ShieldCheck } from 'lucide-react';
import { useMemo, useRef, useState } from 'react';
import { ConsentBox, defaultConsentLabel } from '@/components/sign/consent-box';
import { FieldChecklist } from '@/components/sign/field-checklist';
import { OtpCard } from '@/components/sign/otp-card';
import {
    PrivacyNotice,
    type PrivacyNoticeContent,
    defaultPrivacyNotice,
} from '@/components/sign/privacy-notice';
import {
    ReceiptCard,
    type SignerReceipt,
} from '@/components/sign/receipt-card';
import { RefusalDialog } from '@/components/sign/refusal-dialog';
import { SignerDocument } from '@/components/sign/signer-document';
import type {
    OtherField,
    SignerField,
} from '@/components/sign/signer-field-layer';
import { TERMINAL_ICONS, TerminalCard } from '@/components/sign/terminal-card';
import { InitialsCapture } from '@/components/signature/initials-capture';
import {
    SignatureCapture,
    type SignatureValue,
} from '@/components/signature/signature-capture';
import {
    SIGNATURE_PAYLOAD_MAX_BYTES,
    dataUrlBytes,
} from '@/components/signature/signature-image';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { formatDateMedium, formatDateTime, plural } from '@/lib/format';
import {
    complete as signComplete,
    document as signDocument,
} from '@/routes/sign';
import type {
    AuthMethod,
    EnvelopeStatus,
    FieldType,
    RecipientStatus,
    SignatureKind,
    SigningOrder,
} from '@/types';

type SignerScreen =
    | 'identify'
    | 'sign'
    | 'completed'
    | 'refused'
    | 'expired'
    | 'canceled'
    | 'already_signed_pending_others'
    /** Todos assinaram; o arquivo final está sendo preparado. */
    | 'finalizing'
    | 'invalid';

export interface SignShowProps {
    token: string;
    screen: SignerScreen;
    sender: {
        organization_name: string;
        organization_initials: string;
        logo_url: null;
        user_name: string;
    };
    /** null apenas quando `screen === 'invalid'` (link desconhecido). */
    envelope: {
        display_code: string;
        title: string;
        pages: number;
        sent_at: string | null;
        expires_at: string | null;
        status: EnvelopeStatus;
        completed_at: string | null;
        message: string | null;
    } | null;
    /** null apenas quando `screen === 'invalid'`. */
    recipient: {
        first_name: string;
        name: string;
        role: string | null;
        email_masked: string;
        status: RecipientStatus;
        order: number;
    } | null;
    others: {
        name: string;
        role: string | null;
        order: number;
        status: RecipientStatus;
        signs_after_me: boolean;
    }[];
    signing_order: SigningOrder;
    otp: {
        sent_at: string | null;
        expires_at: string | null;
        resend_available_at: string | null;
        attempts_left: number;
    } | null;
    document: {
        pdf_url: string;
        page_thumb_url_template: string | null;
        page_sizes: {
            page: number;
            width_pt: number;
            height_pt: number;
            rotation: number;
        }[];
        sha256: string | null;
    } | null;
    my_fields: {
        id: string;
        type: FieldType;
        page: number | 'all';
        x: number;
        y: number;
        w: number;
        h: number;
        required: boolean;
        label: string | null;
        placeholder: string | null;
        prefill: string | null;
    }[];
    other_fields: {
        recipient_name: string;
        role: string | null;
        type: FieldType;
        page: number;
        x: number;
        y: number;
        w: number;
        h: number;
        signed: boolean;
    }[];
    signature_options: {
        draw: boolean;
        type: boolean;
        upload: boolean;
        /** Famílias aceitas em `signature.font` (`RecordAcceptance::FONTS`). */
        fonts: string[];
        /** Certificado ICP-Brasil do signatário é Fase 2 — sempre `false`. */
        certificate?: boolean;
    };
    /** Declaração completa (`declaracao-de-aceite.md` §3), já resolvida. */
    consent_text: string;
    /** Textos do aceite; `null` fora da tela `sign`. */
    consent: {
        version: string;
        checkbox_label: string;
        statement: string;
        /** O que a plataforma afirma sobre a conclusão (§7 do doc jurídico). */
        completion_notice: string;
    } | null;
    /** Aviso de privacidade ao signatário, já com as variáveis substituídas. */
    privacy: {
        version: string;
        summary: string;
        notice: string;
    } | null;
    /** Token de autorização final, exigido por `sign.complete`. */
    authorization: { token: string; expires_at: string | null } | null;
    legal: { terms_url: string; privacy_url: string };
    receipt: SignerReceipt | null;
    refusal: { refused_at: string | null; reason: string } | null;
    auth_methods?: AuthMethod[];
    limits?: {
        otp_length?: number;
        otp_ttl_minutes?: number;
        otp_max_attempts?: number;
        max_text_length?: number;
        signature_image_max_kb?: number;
        refusal_reason?: { min: number; max: number };
        typed_name?: { min: number; max: number };
    } | null;
}

const STEP_BY_SCREEN: Record<SignerScreen, number | null> = {
    identify: 0,
    sign: 1,
    completed: 2,
    already_signed_pending_others: 2,
    finalizing: 2,
    refused: null,
    expired: null,
    canceled: null,
    invalid: null,
};

/**
 * Alias de `SignatureKind` usado no contrato de ROUTES §2.18
 * (`method: 'draw' | 'type' | 'upload'`). Enviamos os dois nomes: o canônico
 * (`kind`, RECONCILIACAO §2) e o do contrato de rotas, para que o servidor
 * aceite qualquer um dos dois sem uma rodada de integração.
 */
const METHOD_ALIAS: Record<SignatureKind, string> = {
    drawn: 'draw',
    typed: 'type',
    uploaded: 'upload',
};

/** Remove o prefixo `data:image/png;base64,` — o contrato pede base64 puro. */
function toBase64(dataUrl: string): string {
    const comma = dataUrl.indexOf(',');

    return comma >= 0 ? dataUrl.slice(comma + 1) : dataUrl;
}

/**
 * Página pública do signatário (ROUTES §2.18 e §3; DESIGN §6.12).
 *
 * Vocabulário (arquitetura §2): a imagem capturada é a **representação
 * visual**; o que vale é o **aceite eletrônico** registrado com as evidências.
 * Sem certificado da operadora ativo, o envelope conclui como "aceite
 * eletrônico com evidências" — e é isso que a tela diz.
 */
export default function SignShow(props: SignShowProps) {
    const {
        token,
        screen,
        sender,
        envelope,
        recipient,
        others,
        otp,
        document: documentProps,
        my_fields,
        other_fields,
        signature_options,
        consent_text,
        consent,
        privacy,
        authorization,
        legal,
        receipt,
        refusal,
        limits,
    } = props;

    const errors = usePage().props.errors;

    const [page, setPage] = useState(1);
    const [signature, setSignature] = useState<SignatureValue | null>(null);
    const [initials, setInitials] = useState<SignatureValue | null>(null);
    const [values, setValues] = useState<Record<string, string | boolean>>({});
    const [accepted, setAccepted] = useState(false);
    const [activeFieldId, setActiveFieldId] = useState<string | null>(null);
    const [refuseOpen, setRefuseOpen] = useState(false);
    const [submitting, setSubmitting] = useState(false);
    const [localError, setLocalError] = useState<string | null>(null);

    const fieldRefs = useRef<Record<string, HTMLElement | null>>({});
    const signatureRef = useRef<HTMLDivElement | null>(null);
    const initialsRef = useRef<HTMLDivElement | null>(null);

    const pageCount = envelope?.pages ?? 1;

    // `page: 'all'` (rubrica em todas as páginas) vira uma caixa por página.
    const myFields: SignerField[] = useMemo(
        () =>
            my_fields.flatMap((field) => {
                const pages =
                    field.page === 'all'
                        ? Array.from({ length: pageCount }, (_, i) => i + 1)
                        : [Number(field.page)];

                return pages.map((number) => ({
                    id: field.id,
                    type: field.type,
                    page: number,
                    x: field.x,
                    y: field.y,
                    w: field.w,
                    h: field.h,
                    required: field.required,
                    label: field.label,
                    placeholder: field.placeholder,
                    prefill: field.prefill,
                }));
            }),
        [my_fields, pageCount],
    );

    const otherFields: OtherField[] = useMemo(() => {
        const afterMe = new Map(
            others.map((other) => [other.name, other.signs_after_me]),
        );

        return other_fields.map((field, index) => ({
            key: `${field.recipient_name}-${index}`,
            recipient_name: field.recipient_name,
            role: field.role,
            type: field.type,
            page: field.page,
            x: field.x,
            y: field.y,
            w: field.w,
            h: field.h,
            signed: field.signed,
            hint: field.signed
                ? null
                : `${field.recipient_name.split(' ')[0]} · ${
                      afterMe.get(field.recipient_name)
                          ? 'assina depois de você'
                          : 'ainda não assinou'
                  }`,
        }));
    }, [other_fields, others]);

    /** Um campo por id (a rubrica repetida em N páginas conta uma vez). */
    const uniqueFields = useMemo(() => {
        const seen = new Map<string, SignerField>();
        myFields.forEach((field) => {
            if (!seen.has(field.id)) {
                seen.set(field.id, field);
            }
        });

        return [...seen.values()];
    }, [myFields]);

    const needsSignature = uniqueFields.some((f) => f.type === 'signature');
    const needsInitials = uniqueFields.some((f) => f.type === 'initials');
    const inputFields = uniqueFields.filter(
        (field) => field.type !== 'signature' && field.type !== 'initials',
    );

    const isFilled = (field: SignerField): boolean => {
        if (field.type === 'signature') {
            return signature !== null;
        }

        if (field.type === 'initials') {
            return initials !== null;
        }

        if (field.type === 'checkbox') {
            return values[field.id] === true;
        }

        const value = values[field.id];

        if (typeof value === 'string' && value.trim() !== '') {
            return true;
        }

        return (field.prefill ?? '').trim() !== '';
    };

    const pending = uniqueFields.filter(
        (field) => field.required && !isFilled(field),
    );
    const nextPending = useMemo(() => {
        const ordered = myFields.filter(
            (field) => field.required && !isFilled(field),
        );

        return ordered.find((field) => field.page > page) ?? ordered[0] ?? null;
    }, [myFields, page, signature, initials, values]);

    const setValue = (fieldId: string, value: string | boolean) => {
        setValues((current) => ({ ...current, [fieldId]: value }));
    };

    const focusField = (field: SignerField) => {
        setActiveFieldId(field.id);

        const target =
            field.type === 'signature'
                ? signatureRef.current
                : field.type === 'initials'
                  ? initialsRef.current
                  : fieldRefs.current[field.id];

        target?.scrollIntoView({ behavior: 'smooth', block: 'center' });

        if (target instanceof HTMLInputElement) {
            target.focus({ preventScroll: true });
        }
    };

    const activateField = (field: SignerField) => {
        if (field.type === 'checkbox') {
            setValue(field.id, values[field.id] !== true);
            setActiveFieldId(field.id);

            return;
        }

        focusField(field);
    };

    const goToNextPending = () => {
        if (!nextPending) {
            return;
        }

        setPage(nextPending.page);
        focusField(nextPending);
    };

    const canSubmit =
        accepted &&
        pending.length === 0 &&
        (!needsSignature || signature !== null) &&
        (!needsInitials || initials !== null) &&
        !submitting;

    const submit = () => {
        if (!canSubmit) {
            return;
        }

        const oversize = [signature, initials].some(
            (image) =>
                image !== null &&
                dataUrlBytes(image.image_base64) > SIGNATURE_PAYLOAD_MAX_BYTES,
        );

        if (oversize) {
            setLocalError(
                'A imagem da assinatura ficou grande demais. Limpe o quadro e faça um traço mais simples, ou envie uma imagem menor.',
            );

            return;
        }

        setLocalError(null);
        setSubmitting(true);

        const encode = (image: SignatureValue) => ({
            method: METHOD_ALIAS[image.kind],
            // `kind` é o nome canônico (RECONCILIACAO §2); `method` é o alias do
            // contrato de rotas, que é o que o FormRequest valida hoje.
            kind: image.kind,
            image_base64: toBase64(image.image_base64),
            text: image.text,
            font: image.font,
        });

        router.post(
            signComplete(token).url,
            {
                authorization: authorization?.token ?? '',
                signature: signature
                    ? encode(signature)
                    : { method: 'draw', kind: 'drawn' },
                initials: initials ? encode(initials) : null,
                fields: values,
                consent: true,
            },
            {
                preserveScroll: true,
                // Sem isto o Inertia remonta a página a cada resposta e o
                // signatário perde a assinatura desenhada quando o servidor
                // recusa o aceite (POST remonta por padrão).
                preserveState: true,
                onFinish: () => setSubmitting(false),
            },
        );
    };

    // --- Estados terminais -------------------------------------------------

    if (screen === 'invalid' || envelope === null || recipient === null) {
        return (
            <>
                <Head title="Link inválido" />
                <div className="mx-auto w-full max-w-[520px]">
                    <TerminalCard
                        icon={TERMINAL_ICONS.invalid}
                        title="Link inválido"
                    >
                        Este link não existe ou foi substituído. Verifique o
                        e-mail mais recente.
                    </TerminalCard>
                </div>
            </>
        );
    }

    const notice: PrivacyNoticeContent = privacy
        ? { summary: privacy.summary, body: privacy.notice }
        : defaultPrivacyNotice(sender.organization_name);

    if (screen === 'expired' || screen === 'canceled' || screen === 'refused') {
        return (
            <>
                <Head title={envelope.title} />
                <div className="mx-auto w-full max-w-[520px]">
                    {screen === 'expired' && (
                        <TerminalCard
                            icon={TERMINAL_ICONS.expired}
                            title="Prazo encerrado"
                        >
                            O prazo para assinar este documento terminou em{' '}
                            {formatDateMedium(envelope.expires_at)}. Entre em
                            contato com {sender.user_name} (
                            {sender.organization_name}).
                        </TerminalCard>
                    )}
                    {screen === 'canceled' && (
                        <TerminalCard
                            icon={TERMINAL_ICONS.canceled}
                            title="Documento cancelado"
                        >
                            {sender.organization_name} cancelou esta solicitação
                            de assinatura.
                        </TerminalCard>
                    )}
                    {screen === 'refused' && (
                        <TerminalCard
                            icon={TERMINAL_ICONS.refused}
                            tone="danger"
                            title="Assinatura recusada"
                        >
                            {refusal ? (
                                <>
                                    Você recusou assinar este documento em{' '}
                                    {formatDateTime(refusal.refused_at)}.
                                    <span className="mt-1.5 block">
                                        Motivo informado: “{refusal.reason}”
                                    </span>
                                </>
                            ) : (
                                'Você recusou assinar este documento.'
                            )}
                        </TerminalCard>
                    )}
                </div>
            </>
        );
    }

    // --- Comprovante -------------------------------------------------------

    if (
        screen === 'completed' ||
        screen === 'finalizing' ||
        screen === 'already_signed_pending_others'
    ) {
        // No comprovante o aceite já foi registrado: os campos do próprio
        // signatário aparecem concluídos, e não como caixas azuis "clique para
        // assinar" (a imagem aplicada mora no servidor, não aqui).
        const signedFields: OtherField[] = [
            ...myFields.map((field) => ({
                key: `mine-${field.id}-${field.page}`,
                recipient_name: recipient.name,
                role: recipient.role,
                type: field.type,
                page: field.page,
                x: field.x,
                y: field.y,
                w: field.w,
                h: field.h,
                signed: true,
            })),
            ...otherFields,
        ];

        return (
            <>
                <Head title={`Comprovante · ${envelope.title}`} />
                <div className="flex flex-col gap-4 lg:flex-row lg:items-start">
                    <aside
                        className={`order-1 w-full min-w-0 lg:order-2 lg:max-w-[420px] lg:flex-[1_1_320px] ${documentProps ? '' : 'lg:mx-auto'}`}
                    >
                        <div className="border-border bg-card shadow-card rounded-[14px] border p-5 sm:p-[22px]">
                            {receipt ? (
                                <ReceiptCard
                                    receipt={receipt}
                                    emailMasked={recipient.email_masked}
                                    completed={screen === 'completed'}
                                    finalizing={screen === 'finalizing'}
                                />
                            ) : (
                                <p className="text-text-secondary text-[13.5px]">
                                    Seu aceite foi registrado.
                                </p>
                            )}
                        </div>
                        <ParticipantsCard
                            others={others}
                            className="mt-4"
                            recipientName={recipient.name}
                        />
                    </aside>

                    {documentProps && (
                        <div className="order-2 min-w-0 lg:order-1 lg:flex-[1.5_1_380px]">
                            <SignerDocument
                                pdfUrl={signDocument(token).url}
                                title={envelope.title}
                                pages={envelope.pages}
                                displayCode={envelope.display_code}
                                fields={[]}
                                others={signedFields}
                                values={values}
                                signatureImage={null}
                                initialsImage={null}
                                activeFieldId={null}
                                onActivateField={() => undefined}
                                page={page}
                                onPageChange={setPage}
                                readOnly
                            />
                        </div>
                    )}
                </div>
            </>
        );
    }

    // --- Confirmar identidade ---------------------------------------------

    if (screen === 'identify') {
        return (
            <>
                <Head title={`Assinar · ${envelope.title}`} />
                <div className="flex flex-col gap-4 lg:flex-row lg:items-start">
                    <aside className="order-1 w-full min-w-0 lg:sticky lg:top-[76px] lg:order-2 lg:max-w-[420px] lg:flex-[1_1_320px]">
                        <OtpCard
                            token={token}
                            firstName={recipient.first_name}
                            emailMasked={recipient.email_masked}
                            senderName={sender.user_name}
                            organizationName={sender.organization_name}
                            sentAt={envelope.sent_at}
                            expiresAt={envelope.expires_at}
                            otp={otp}
                            notice={notice}
                            termsUrl={legal.terms_url}
                            privacyUrl={legal.privacy_url}
                            errors={errors}
                            codeLength={limits?.otp_length}
                            ttlMinutes={limits?.otp_ttl_minutes}
                        />
                    </aside>

                    <div className="order-2 min-w-0 lg:order-1 lg:flex-[1.5_1_380px]">
                        <LockedDocument
                            title={envelope.title}
                            pages={envelope.pages}
                            displayCode={envelope.display_code}
                        />
                    </div>
                </div>
            </>
        );
    }

    // --- Assinar -----------------------------------------------------------

    return (
        <>
            <Head title={`Assinar · ${envelope.title}`} />

            <div className="flex flex-col gap-4 lg:flex-row lg:items-start">
                <div className="min-w-0 lg:flex-[1.5_1_380px]">
                    {documentProps ? (
                        <SignerDocument
                            pdfUrl={signDocument(token).url}
                            title={envelope.title}
                            pages={envelope.pages}
                            displayCode={envelope.display_code}
                            fields={myFields}
                            others={otherFields}
                            values={values}
                            signatureImage={signature?.image_base64 ?? null}
                            initialsImage={initials?.image_base64 ?? null}
                            activeFieldId={activeFieldId}
                            onActivateField={activateField}
                            page={page}
                            onPageChange={setPage}
                            nextPending={nextPending}
                            onGoToNextPending={goToNextPending}
                        />
                    ) : (
                        <LockedDocument
                            title={envelope.title}
                            pages={envelope.pages}
                            displayCode={envelope.display_code}
                        />
                    )}
                </div>

                <aside className="flex w-full min-w-0 flex-col gap-4 lg:sticky lg:top-[76px] lg:max-w-[420px] lg:flex-[1_1_320px]">
                    <div className="border-border bg-card shadow-card flex flex-col gap-4 rounded-[14px] border p-5 sm:p-[22px]">
                        <div>
                            <Badge variant="success">
                                <Check className="size-3 stroke-[3]" />
                                Identidade confirmada
                            </Badge>
                            <h1 className="mt-3 text-[20px] leading-[1.25] font-bold tracking-[-.01em]">
                                Sua assinatura
                            </h1>
                            <p className="text-text-secondary mt-1.5 text-[13.5px] leading-[1.55]">
                                Escolha como quer assinar. A imagem é a{' '}
                                <b className="text-foreground">
                                    representação visual
                                </b>{' '}
                                da sua assinatura; o que registra sua vontade é
                                o aceite abaixo.
                            </p>
                        </div>

                        {needsSignature && (
                            <div ref={signatureRef} className="scroll-mt-24">
                                <SignatureCapture
                                    value={signature}
                                    onChange={setSignature}
                                    defaultText={recipient.name}
                                    options={signature_options}
                                />
                            </div>
                        )}

                        {needsInitials && (
                            <div
                                ref={initialsRef}
                                className="border-border scroll-mt-24 border-t pt-4"
                            >
                                <p className="text-text-secondary mb-2 text-[12.5px] font-semibold">
                                    Sua rubrica
                                </p>
                                <InitialsCapture
                                    value={initials}
                                    onChange={setInitials}
                                    name={recipient.name}
                                    options={signature_options}
                                />
                            </div>
                        )}

                        {inputFields.length > 0 && (
                            <FieldChecklist
                                fields={inputFields}
                                values={values}
                                onChange={setValue}
                                activeId={activeFieldId}
                                registerRef={(id, element) => {
                                    fieldRefs.current[id] = element;
                                }}
                                maxTextLength={limits?.max_text_length}
                                errors={errors}
                                className="border-border border-t pt-4"
                            />
                        )}

                        <PrivacyNotice
                            notice={notice}
                            privacyUrl={legal.privacy_url}
                        />

                        {consent?.completion_notice && (
                            <p className="border-border bg-sidebar text-text-secondary rounded-[10px] border p-3 text-[12.5px] leading-[1.5]">
                                {consent.completion_notice}
                            </p>
                        )}

                        <ConsentBox
                            checked={accepted}
                            onCheckedChange={setAccepted}
                            label={
                                consent?.checkbox_label ??
                                defaultConsentLabel(envelope.title)
                            }
                            statement={consent?.statement ?? consent_text}
                            version={consent?.version}
                            termsUrl={legal.terms_url}
                            privacyUrl={legal.privacy_url}
                        />

                        {pending.length > 0 && (
                            <p className="text-warning text-[12.5px]">
                                Faltam{' '}
                                {plural(
                                    pending.length,
                                    'campo obrigatório',
                                    'campos obrigatórios',
                                )}{' '}
                                — use “Próximo campo” na barra do documento.
                            </p>
                        )}
                        {(localError ||
                            errors.signature ||
                            errors.authorization ||
                            errors.consent) && (
                            <p
                                role="alert"
                                className="text-danger text-[12.5px]"
                            >
                                {localError ??
                                    errors.signature ??
                                    errors.authorization ??
                                    errors.consent}
                            </p>
                        )}

                        <Button
                            type="button"
                            size="xl"
                            disabled={!canSubmit}
                            onClick={submit}
                        >
                            {submitting ? (
                                <Spinner className="size-4" />
                            ) : (
                                <PenLine className="size-4" />
                            )}
                            Assinar documento
                        </Button>

                        <button
                            type="button"
                            onClick={() => setRefuseOpen(true)}
                            className="text-muted-foreground hover:text-danger text-center text-[12.5px] font-semibold"
                        >
                            Recusar assinatura
                        </button>
                    </div>

                    <ParticipantsCard
                        others={others}
                        recipientName={recipient.name}
                    />
                </aside>
            </div>

            <RefusalDialog
                token={token}
                open={refuseOpen}
                onOpenChange={setRefuseOpen}
                organizationName={sender.organization_name}
                minReason={limits?.refusal_reason?.min}
                maxReason={limits?.refusal_reason?.max}
            />
        </>
    );
}

/** Placeholder do documento antes da confirmação de identidade. */
function LockedDocument({
    title,
    pages,
    displayCode,
}: {
    title: string;
    pages: number;
    displayCode: string;
}) {
    return (
        <div className="border-border bg-card shadow-card flex flex-col items-center gap-3 rounded-xl border p-8 text-center">
            <span className="bg-primary-soft text-primary flex size-12 items-center justify-center rounded-xl">
                <FileLock2 className="size-5" />
            </span>
            <p className="text-[15px] font-semibold">{title}</p>
            <p className="text-muted-foreground tabular text-[12.5px]">
                {displayCode} · {plural(pages, 'página')}
            </p>
            <p className="text-text-secondary max-w-[380px] text-[13px] leading-[1.55]">
                O documento é exibido depois que você confirmar o código enviado
                ao seu e-mail. Ele nunca fica acessível por um endereço público.
            </p>
        </div>
    );
}

/** Lista dos demais participantes — sem e-mails (ROUTES §2.18). */
function ParticipantsCard({
    others,
    recipientName,
    className,
}: {
    others: SignShowProps['others'];
    recipientName: string;
    className?: string;
}) {
    if (others.length === 0) {
        return null;
    }

    return (
        <div
            className={`border-border bg-card shadow-card rounded-[14px] border p-4 ${className ?? ''}`}
        >
            <h2 className="flex items-center gap-1.5 text-[13.5px] font-semibold">
                <ShieldCheck className="text-primary size-3.5" />
                Participantes
            </h2>
            <ul className="mt-2.5 flex flex-col gap-2 text-[12.5px]">
                <li className="flex items-center justify-between gap-2">
                    <span className="truncate font-medium">
                        {recipientName}{' '}
                        <span className="text-muted-foreground">(você)</span>
                    </span>
                </li>
                {others.map((other) => (
                    <li
                        key={`${other.order}-${other.name}`}
                        className="flex items-center justify-between gap-2"
                    >
                        <span className="truncate">
                            {other.name}
                            {other.role && (
                                <span className="text-muted-foreground">
                                    {' '}
                                    · {other.role}
                                </span>
                            )}
                        </span>
                        <span className="text-muted-foreground shrink-0 text-[12px]">
                            {other.status === 'signed'
                                ? 'Assinou'
                                : other.status === 'refused'
                                  ? 'Recusou'
                                  : other.signs_after_me
                                    ? 'assina depois de você'
                                    : 'pendente'}
                        </span>
                    </li>
                ))}
            </ul>
        </div>
    );
}

SignShow.layout = (props: SignShowProps) => ({
    sender: props.sender,
    documentTitle: props.envelope?.title ?? null,
    step: STEP_BY_SCREEN[props.screen],
    privacyUrl: props.legal.privacy_url,
    termsUrl: props.legal.terms_url,
});
