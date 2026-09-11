import { Head, router, usePage } from '@inertiajs/react';
import {
    Check,
    CheckCircle2,
    Download,
    FileLock2,
    PenLine,
    ShieldCheck,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import type { SignerBrand } from '@/components/branding/types';
import {
    CaptureStepCard,
    CaptureStepPreview,
} from '@/components/identity/capture-step';
import { DocumentSwitcher } from '@/components/pdf/document-switcher';
import type { PdfDocumentStatus } from '@/components/pdf/use-pdf-document';
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
import type { StepperStep } from '@/components/stepper';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import {
    formatDateMedium,
    formatDateTime,
    isValidCpf,
    plural,
} from '@/lib/format';
import { channelPhraseLabels } from '@/lib/labels';
import {
    complete as signComplete,
    document as signDocument,
} from '@/routes/sign';
import type {
    AcceptanceAction,
    EnvelopeStatus,
    IdentityCaptureStep,
    ParticipantRole,
    RecipientStatus,
    SignatureKind,
    SignerAuth,
    SignerAuthMethod,
    SigningFieldType,
    SigningOrder,
} from '@/types';

type SignerScreen =
    | 'identify'
    | 'sign'
    /** Fase 2 §2.4: visualizador depois do código — leitura, sem aceite. */
    | 'view'
    | 'completed'
    | 'refused'
    | 'expired'
    | 'canceled'
    | 'already_signed_pending_others'
    /** Todos assinaram; o arquivo final está sendo preparado. */
    | 'finalizing'
    | 'invalid';

/** O que o botão principal faz (`SignerPageProps::action`, Fase 2 §2.4). */
export interface SignerAction {
    type: AcceptanceAction | 'view';
    label: string;
    /** "Assinar documento" | "Assinar como testemunha" | "Aprovar documento" | null. */
    button_label: string | null;
    requires_signature: boolean;
    requires_consent: boolean;
}

/** Um arquivo apresentado nesta sessão (Fase 2 §2.3). */
export interface SignerDocumentItem {
    id: string;
    position: number;
    name: string | null;
    pages: number;
    /** `sign.document` (com `?document=` do 2º em diante): exige sessão. */
    pdf_url: string;
    page_sizes: {
        page: number;
        width_pt: number;
        height_pt: number;
        rotation: number;
    }[];
    sha256: string | null;
    /** Já entregue a ESTA sessão (o aceite exige todos). */
    presented: boolean;
}

/** Cópia do visualizador (`SignerPageProps::viewProps`). */
export interface ViewerCopy {
    final_available: boolean;
    completed_at: string | null;
    can_download: boolean;
    downloads: {
        document_id: string;
        name: string | null;
        position: number;
        available: boolean;
        url: string | null;
    }[];
    notice: string;
}

export interface SignShowProps {
    token: string;
    screen: SignerScreen;
    sender: {
        organization_name: string;
        organization_initials: string;
        logo_url: string | null;
        user_name: string;
        /** Fase 2 §2.8 (`BrandingPresenter::forSigner`): flag `branding` + marca salva. */
        brand?: SignerBrand | null;
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
        participant_role?: ParticipantRole;
        participant_role_label?: string;
    } | null;
    others: {
        name: string;
        role: string | null;
        order: number;
        status: RecipientStatus;
        signs_after_me: boolean;
        participant_role?: ParticipantRole;
        participant_role_label?: string;
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
    /** Fase 2 §2.3: em `sign` e `view`, um item por arquivo. */
    documents?: SignerDocumentItem[];
    my_fields: {
        id: string;
        type: SigningFieldType;
        page: number | 'all';
        x: number;
        y: number;
        w: number;
        h: number;
        required: boolean;
        label: string | null;
        placeholder: string | null;
        prefill: string | null;
        document_id?: string | null;
    }[];
    other_fields: {
        recipient_name: string;
        role: string | null;
        type: SigningFieldType;
        page: number;
        x: number;
        y: number;
        w: number;
        h: number;
        signed: boolean;
        document_id?: string | null;
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
    /** Ex.: `['sms_otp', 'sender_pin']` (`SignerAuthProps::authMethods`). */
    auth_methods?: SignerAuthMethod[];
    /**
     * Fase 2 §2.9 (`SignerAuthProps::for`): canal do código, destino mascarado, simulador e a
     * etapa do PIN. Ausente = código por e-mail (Fase 1).
     */
    signer_auth?: SignerAuth | null;
    /**
     * CUIDADO: `auth` é também a prop compartilhada `{ user }` do `HandleInertiaRequests`
     * (o Inertia mescla as compartilhadas nas da página). Aqui ela só vale como
     * `SignerAuth` se tiver a forma certa (`signerAuthOf`); o nome preferido do contrato
     * é `signer_auth`, que não colide.
     */
    auth?: unknown;
    /**
     * Fase 2 §2.10 (`CaptureStep::props`): fotos pedidas pelo remetente antes do aceite.
     * `null` quando não se aplica.
     */
    identity_capture?: IdentityCaptureStep | null;
    limits?: {
        otp_length?: number;
        otp_ttl_minutes?: number;
        otp_max_attempts?: number;
        max_text_length?: number;
        signature_image_max_kb?: number;
        refusal_reason?: { min: number; max: number };
        typed_name?: { min: number; max: number };
    } | null;
    /** Fase 2 §2.4 — `null` só em `invalid`. */
    action?: SignerAction | null;
    /** Fase 2 §2.4 — só em `view`. */
    copy?: ViewerCopy | null;
}

const STEP_BY_SCREEN: Record<SignerScreen, number | null> = {
    identify: 0,
    sign: 1,
    view: 1,
    completed: 2,
    already_signed_pending_others: 2,
    finalizing: 2,
    refused: null,
    expired: null,
    canceled: null,
    invalid: null,
};

/**
 * Passos do cabeçalho por papel (Fase 2 §2.4). `undefined` = os do layout (Fase 1).
 * O aprovador não "assina": o passo diz o que ele faz.
 */
function stepsFor(
    action: SignerAction | null | undefined,
): StepperStep[] | undefined {
    if (action?.type === 'approve') {
        return [
            { key: 'identify', title: 'Confirmar identidade' },
            { key: 'sign', title: 'Aprovar' },
            { key: 'completed', title: 'Concluído' },
        ];
    }

    if (action?.type === 'view') {
        return [
            { key: 'identify', title: 'Confirmar identidade' },
            { key: 'sign', title: 'Acompanhar' },
        ];
    }

    return undefined;
}

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

/** Campo do próprio participante já ligado ao arquivo em que está (Fase 2 §2.3). */
type PlacedField = SignerField & { documentId: string | null };
type PlacedOther = OtherField & { documentId: string | null };

/**
 * `SignerAuth` só quando o valor tem a forma do contrato (`SignerAuthProps::for`). O `auth`
 * compartilhado (`{ user: null }` na página pública) cai em `null`, e a tela fica a da
 * Fase 1. Sem esta checagem, o cartão do código achava que o canal estava indisponível e
 * escondia o botão "Receber código por e-mail".
 */
function signerAuthOf(value: unknown): SignerAuth | null {
    if (typeof value !== 'object' || value === null) {
        return null;
    }

    const candidate = value as Partial<SignerAuth>;

    return typeof candidate.method === 'string' &&
        typeof candidate.channel === 'string' &&
        typeof candidate.destination === 'string'
        ? (candidate as SignerAuth)
        : null;
}

function fileLabel(item: { position: number; name: string | null }): string {
    return `${item.position}. ${item.name?.trim() || `Arquivo ${item.position}`}`;
}

/**
 * Página pública do signatário (ROUTES §2.18 e §3; DESIGN §6.12).
 *
 * Vocabulário (arquitetura §2): a imagem capturada é a **representação
 * visual**; o que vale é o **aceite eletrônico** registrado com as evidências.
 * Sem certificado da operadora ativo, o envelope conclui como "aceite
 * eletrônico com evidências" — e é isso que a tela diz.
 *
 * Fase 2 (onda A), sempre pelo que o servidor manda — nunca por flag no cliente:
 * - `action` (§2.4): testemunha assina "como testemunha" com declaração própria; o
 *   aprovador aprova SEM representação visual (sem captura, sem `signature` no POST); o
 *   visualizador vê a tela `view`, só leitura, sem aceite nem recusa;
 * - `documents` (§2.3): com mais de um arquivo, navegação entre eles com os campos
 *   pendentes de cada um; o aceite só habilita depois de TODOS os arquivos terem sido
 *   entregues a esta sessão (o servidor exige o mesmo — `document_not_presented`).
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
        action = null,
        copy = null,
        identity_capture = null,
    } = props;

    const errors = usePage().props.errors;
    // Fase 2 §2.9: nunca confundir com o `auth` compartilhado (`{ user }`).
    const auth = signerAuthOf(props.signer_auth ?? props.auth);

    /*
     * Fase 2 §2.10: a etapa de captura é atualizada pela resposta de cada envio de foto
     * (JSON), sem recarregar a página. Uma resposta nova do servidor substitui a local.
     */
    const [captureStep, setCaptureStep] = useState<IdentityCaptureStep | null>(
        identity_capture,
    );

    useEffect(() => {
        setCaptureStep(identity_capture);
    }, [identity_capture]);

    const stampOwner = {
        brand: sender.brand ?? null,
        organizationName: sender.organization_name,
        organizationInitials: sender.organization_initials,
    };

    const docs = useMemo(() => props.documents ?? [], [props.documents]);
    const multi = docs.length > 1;
    const firstDocId = docs[0]?.id ?? null;

    const [page, setPage] = useState(1);
    const [docId, setDocId] = useState<string | null>(firstDocId);
    const [signature, setSignature] = useState<SignatureValue | null>(null);
    const [initials, setInitials] = useState<SignatureValue | null>(null);
    const [values, setValues] = useState<Record<string, string | boolean>>({});
    const [accepted, setAccepted] = useState(false);
    const [activeFieldId, setActiveFieldId] = useState<string | null>(null);
    const [refuseOpen, setRefuseOpen] = useState(false);
    const [submitting, setSubmitting] = useState(false);
    const [localError, setLocalError] = useState<string | null>(null);
    /**
     * Estado do visualizador. A declaração que a pessoa assina diz "Li integralmente o
     * documento […], cujo conteúdo apresentado nesta tela corresponde ao resumo SHA-256 …",
     * e o servidor recusa o aceite sem a marca de apresentação na sessão
     * (`RecordAcceptance` exige `document_presented_at`). O botão espera a ENTREGA dos
     * bytes, não o desenho: um PDF que o visualizador não consegue renderizar ainda assim
     * saiu do servidor e continua acessível pelo botão "Baixar PDF" da barra — bloquear
     * nesse caso deixaria a pessoa sem saída a não ser recusar. O que bloqueia de verdade é
     * o documento NÃO ter chegado (403, 404, rede), que é exatamente o caso em que ela não
     * teve como ler nada.
     */
    const [documentStatus, setDocumentStatus] =
        useState<PdfDocumentStatus>('idle');
    const [documentDelivered, setDocumentDelivered] = useState(false);
    /**
     * Fase 2 §2.3: entrega por arquivo. Começa com o que o servidor já registrou nesta
     * sessão (`presented`) — recarregar a página não obriga a abrir tudo de novo.
     */
    const [delivered, setDelivered] = useState<Record<string, boolean>>(() =>
        Object.fromEntries(docs.map((item) => [item.id, item.presented])),
    );

    const fieldRefs = useRef<Record<string, HTMLElement | null>>({});
    const signatureRef = useRef<HTMLDivElement | null>(null);
    const initialsRef = useRef<HTMLDivElement | null>(null);

    const currentDoc = multi
        ? (docs.find((item) => item.id === docId) ?? docs[0])
        : null;
    const currentDocId = currentDoc?.id ?? null;
    const pageCount = currentDoc?.pages ?? envelope?.pages ?? 1;

    const approving = action?.requires_signature === false;
    const witnessing = action?.type === 'witness';

    const docIndex = (id: string | null): number =>
        Math.max(
            0,
            docs.findIndex((item) => item.id === id),
        );

    // `page: 'all'` (rubrica em todas as páginas) vira uma caixa por página.
    const myFields: PlacedField[] = useMemo(() => {
        const pagesIn = (id: string | null): number =>
            docs.find((item) => item.id === id)?.pages ?? envelope?.pages ?? 1;

        const placed = my_fields.flatMap((field) => {
            const documentId = field.document_id ?? firstDocId;
            const pages =
                field.page === 'all'
                    ? Array.from(
                          { length: multi ? pagesIn(documentId) : pageCount },
                          (_, i) => i + 1,
                      )
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
                documentId,
            }));
        });

        if (!multi) {
            return placed;
        }

        const order = (id: string | null) =>
            docs.findIndex((item) => item.id === id);

        return [...placed].sort(
            (a, b) =>
                order(a.documentId) - order(b.documentId) || a.page - b.page,
        );
    }, [my_fields, pageCount, docs, multi, firstDocId, envelope?.pages]);

    const otherFields: PlacedOther[] = useMemo(() => {
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
            documentId: field.document_id ?? firstDocId,
        }));
    }, [other_fields, others, firstDocId]);

    /** Um campo por id (a rubrica repetida em N páginas conta uma vez). */
    const uniqueFields = useMemo(() => {
        const seen = new Map<string, PlacedField>();
        myFields.forEach((field) => {
            if (!seen.has(field.id)) {
                seen.set(field.id, field);
            }
        });

        return [...seen.values()];
    }, [myFields]);

    // O aprovador não tem representação visual (servidor proíbe o campo; aqui, a captura).
    const needsSignature =
        !approving && uniqueFields.some((f) => f.type === 'signature');
    const needsInitials =
        !approving && uniqueFields.some((f) => f.type === 'initials');
    // O carimbo visual (§2.8) é desenhado pelo servidor: não é preenchido por ninguém.
    const inputFields = uniqueFields.filter(
        (field) =>
            field.type !== 'signature' &&
            field.type !== 'initials' &&
            field.type !== 'stamp',
    );

    const isFilled = (field: SignerField): boolean => {
        if (field.type === 'stamp') {
            return true;
        }

        if (field.type === 'cpf') {
            const value = values[field.id];

            return typeof value === 'string' && isValidCpf(value);
        }

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

        if (!multi) {
            return (
                ordered.find((field) => field.page > page) ?? ordered[0] ?? null
            );
        }

        const current = docIndex(currentDocId);

        return (
            ordered.find((field) => {
                const index = docIndex(field.documentId);

                return (
                    index > current || (index === current && field.page > page)
                );
            }) ??
            ordered[0] ??
            null
        );
    }, [myFields, page, signature, initials, values, multi, currentDocId]);

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

        if (
            multi &&
            nextPending.documentId &&
            nextPending.documentId !== currentDocId
        ) {
            setDocId(nextPending.documentId);
        }

        setPage(nextPending.page);
        focusField(nextPending);
    };

    const selectDocument = (id: string) => {
        setDocId(id);
        setPage(1);
        setDocumentStatus('idle');
    };

    const onDocumentStatus = (status: PdfDocumentStatus, arrived: boolean) => {
        setDocumentStatus(status);
        setDocumentDelivered(arrived);

        if (multi && currentDocId && (arrived || status === 'ready')) {
            // Devolve o mesmo objeto quando nada muda: o efeito do visualizador roda a
            // cada render e um objeto novo aqui entraria em laço.
            setDelivered((current) =>
                current[currentDocId]
                    ? current
                    : { ...current, [currentDocId]: true },
            );
        }
    };

    const missingDocs = multi ? docs.filter((item) => !delivered[item.id]) : [];
    const documentPresented = multi
        ? missingDocs.length === 0
        : documentDelivered || documentStatus === 'ready';

    // CPF opcional digitado errado também impede o envio (o servidor recusaria, §2.11).
    const cpfInvalid = uniqueFields.some((field) => {
        const value = values[field.id];

        return (
            field.type === 'cpf' &&
            typeof value === 'string' &&
            value.trim() !== '' &&
            !isValidCpf(value)
        );
    });
    // Fotos exigidas (§2.10): o servidor recusa o aceite sem elas (`identity_capture_missing`).
    const captureReady = captureStep === null || captureStep.complete;

    const canSubmit =
        accepted &&
        documentPresented &&
        pending.length === 0 &&
        !cpfInvalid &&
        captureReady &&
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

        if (oversize && !approving) {
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

        const base = {
            authorization: authorization?.token ?? '',
            fields: values,
            consent: true,
        };

        router.post(
            signComplete(token).url,
            // Aprovador (Fase 2 §2.4): aprovação SEM representação visual — nenhuma
            // imagem viaja, nem a de marcação vazia.
            approving
                ? base
                : {
                      ...base,
                      signature: signature
                          ? encode(signature)
                          : { method: 'draw', kind: 'drawn' },
                      initials: initials ? encode(initials) : null,
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

    const myRoleLabel =
        recipient.participant_role && recipient.participant_role !== 'signer'
            ? (recipient.participant_role_label ?? null)
            : null;

    if (screen === 'expired' || screen === 'canceled' || screen === 'refused') {
        const approver = action?.type === 'approve';

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
                            title={
                                approver
                                    ? 'Aprovação recusada'
                                    : 'Assinatura recusada'
                            }
                        >
                            {refusal ? (
                                <>
                                    Você recusou{' '}
                                    {approver ? 'aprovar' : 'assinar'} este
                                    documento em{' '}
                                    {formatDateTime(refusal.refused_at)}.
                                    <span className="mt-1.5 block">
                                        Motivo informado: “{refusal.reason}”
                                    </span>
                                </>
                            ) : approver ? (
                                'Você recusou aprovar este documento.'
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
                            recipientRoleLabel={myRoleLabel}
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
                                stampOwner={stampOwner}
                            />
                        </div>
                    )}
                </div>
            </>
        );
    }

    // --- Confirmar identidade ---------------------------------------------

    if (screen === 'identify') {
        // Título da aba conforme o papel (Fase 2 §2.4, regra T1): aprovar não é
        // assinar, e o visualizador só lê o documento.
        const tabVerb =
            recipient.participant_role === 'viewer'
                ? 'Documento'
                : approving
                  ? 'Aprovar'
                  : witnessing
                    ? 'Assinar como testemunha'
                    : 'Assinar';

        return (
            <>
                <Head title={`${tabVerb} · ${envelope.title}`} />
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
                            heading={
                                action?.type === 'approve'
                                    ? 'Confirme sua identidade para aprovar'
                                    : action?.type === 'view'
                                      ? 'Confirme sua identidade para ver o documento'
                                      : undefined
                            }
                            auth={auth}
                            extra={
                                identity_capture ? (
                                    <CaptureStepPreview
                                        step={identity_capture}
                                    />
                                ) : undefined
                            }
                        />
                    </aside>

                    <div className="order-2 min-w-0 lg:order-1 lg:flex-[1.5_1_380px]">
                        <LockedDocument
                            title={envelope.title}
                            pages={envelope.pages}
                            displayCode={envelope.display_code}
                            channel={auth?.channel ?? 'email'}
                        />
                    </div>
                </div>
            </>
        );
    }

    const switcher = multi && (
        <DocumentSwitcher
            className="mb-3"
            label={
                screen === 'view'
                    ? 'Arquivos deste documento'
                    : 'Confira todos os arquivos'
            }
            items={docs.map((item) => {
                const open = delivered[item.id] === true;
                const missing = uniqueFields.filter(
                    (field) =>
                        field.documentId === item.id &&
                        field.required &&
                        !isFilled(field),
                ).length;

                return {
                    id: item.id,
                    position: item.position,
                    name: item.name?.trim() || `Arquivo ${item.position}`,
                    meta:
                        screen === 'view'
                            ? plural(item.pages, 'página')
                            : !open
                              ? 'Ainda não aberto'
                              : missing > 0
                                ? plural(
                                      missing,
                                      'campo pendente',
                                      'campos pendentes',
                                  )
                                : 'Aberto · sem pendências',
                    tone:
                        screen === 'view'
                            ? 'default'
                            : open && missing === 0
                              ? 'done'
                              : 'attention',
                };
            })}
            current={currentDocId}
            onSelect={selectDocument}
        />
    );

    // --- Visualizador (Fase 2 §2.4) ----------------------------------------

    if (screen === 'view') {
        const available = (copy?.downloads ?? []).filter(
            (item) => item.available && item.url,
        );

        return (
            <>
                <Head title={`Documento · ${envelope.title}`} />

                <div className="flex flex-col gap-4 lg:flex-row lg:items-start">
                    <div className="min-w-0 lg:flex-[1.5_1_380px]">
                        {switcher}
                        {documentProps || currentDoc ? (
                            <SignerDocument
                                key={currentDocId ?? 'single'}
                                pdfUrl={
                                    currentDoc?.pdf_url ??
                                    signDocument(token).url
                                }
                                title={
                                    currentDoc
                                        ? fileLabel(currentDoc)
                                        : envelope.title
                                }
                                pages={pageCount}
                                displayCode={envelope.display_code}
                                fields={[]}
                                others={[]}
                                values={{}}
                                signatureImage={null}
                                initialsImage={null}
                                activeFieldId={null}
                                onActivateField={() => undefined}
                                page={page}
                                onPageChange={setPage}
                                readOnly
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
                                    Código confirmado
                                </Badge>
                                <h1 className="mt-3 text-[20px] leading-[1.25] font-bold tracking-[-.01em]">
                                    Cópia para acompanhamento
                                </h1>
                                <p className="text-text-secondary mt-1.5 text-[13.5px] leading-[1.55]">
                                    {copy?.notice ??
                                        'Você recebeu este documento para acompanhamento. Não é necessário assinar nem aprovar.'}
                                </p>
                            </div>

                            <p className="border-border bg-sidebar text-text-secondary rounded-[10px] border p-3 text-[12.5px] leading-[1.5]">
                                Como visualizador, você não registra aceite nem
                                recusa. Esta tela é somente leitura e não altera
                                o andamento do documento.
                            </p>

                            {copy?.can_download && available.length > 0 ? (
                                <div className="flex flex-col gap-2">
                                    <p className="text-[13px] font-semibold">
                                        {copy.completed_at
                                            ? `Cópia final — concluído em ${formatDateTime(copy.completed_at)}`
                                            : 'Cópia final'}
                                    </p>
                                    {available.map((item) => (
                                        <Button
                                            key={item.document_id}
                                            asChild
                                            variant="outline"
                                            className="justify-start"
                                        >
                                            <a href={item.url ?? '#'}>
                                                <Download className="size-4" />
                                                <span className="truncate">
                                                    {available.length > 1 ||
                                                    multi
                                                        ? `Baixar ${fileLabel(item)}`
                                                        : 'Baixar cópia final'}
                                                </span>
                                            </a>
                                        </Button>
                                    ))}
                                </div>
                            ) : (
                                <Button variant="outline" disabled>
                                    <Download className="size-4" />
                                    Disponível quando todos concluírem
                                </Button>
                            )}
                        </div>

                        <ParticipantsCard
                            others={others}
                            recipientName={recipient.name}
                            recipientRoleLabel={myRoleLabel}
                        />
                    </aside>
                </div>
            </>
        );
    }

    // --- Assinar -----------------------------------------------------------

    const visibleFields = multi
        ? myFields.filter((field) => field.documentId === currentDocId)
        : myFields;
    const visibleOthers = multi
        ? otherFields.filter((field) => field.documentId === currentDocId)
        : otherFields;
    const buttonLabel = action?.button_label ?? 'Assinar documento';

    return (
        <>
            <Head title={`Assinar · ${envelope.title}`} />

            <div className="flex flex-col gap-4 lg:flex-row lg:items-start">
                <div className="min-w-0 lg:flex-[1.5_1_380px]">
                    {switcher}
                    {documentProps || currentDoc ? (
                        <SignerDocument
                            key={currentDocId ?? 'single'}
                            pdfUrl={
                                currentDoc?.pdf_url ?? signDocument(token).url
                            }
                            title={
                                currentDoc
                                    ? fileLabel(currentDoc)
                                    : envelope.title
                            }
                            pages={pageCount}
                            displayCode={envelope.display_code}
                            fields={visibleFields}
                            others={visibleOthers}
                            values={values}
                            signatureImage={
                                approving
                                    ? null
                                    : (signature?.image_base64 ?? null)
                            }
                            initialsImage={
                                approving
                                    ? null
                                    : (initials?.image_base64 ?? null)
                            }
                            activeFieldId={activeFieldId}
                            onActivateField={activateField}
                            page={page}
                            onPageChange={setPage}
                            nextPending={nextPending}
                            onGoToNextPending={goToNextPending}
                            onStatusChange={onDocumentStatus}
                            stampOwner={stampOwner}
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
                                Código confirmado
                            </Badge>
                            <h1 className="mt-3 text-[20px] leading-[1.25] font-bold tracking-[-.01em]">
                                {approving
                                    ? 'Sua aprovação'
                                    : witnessing
                                      ? 'Sua assinatura como testemunha'
                                      : 'Sua assinatura'}
                            </h1>
                            <p className="text-text-secondary mt-1.5 text-[13.5px] leading-[1.55]">
                                {approving ? (
                                    <>
                                        Você aprova o conteúdo{' '}
                                        {multi
                                            ? 'dos arquivos'
                                            : 'do documento'}
                                        . Não há representação visual de
                                        assinatura: o que registra sua{' '}
                                        <b className="text-foreground">
                                            aprovação eletrônica
                                        </b>{' '}
                                        é o aceite abaixo.
                                    </>
                                ) : witnessing ? (
                                    <>
                                        Você participa como{' '}
                                        <b className="text-foreground">
                                            testemunha
                                        </b>
                                        . A imagem é a representação visual da
                                        sua assinatura; o que registra sua
                                        manifestação é o aceite abaixo, com a
                                        declaração própria de testemunha.
                                    </>
                                ) : (
                                    <>
                                        Escolha como quer assinar. A imagem é a{' '}
                                        <b className="text-foreground">
                                            representação visual
                                        </b>{' '}
                                        da sua assinatura; o que registra sua
                                        vontade é o aceite abaixo.
                                    </>
                                )}
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

                        {captureStep && (
                            <CaptureStepCard
                                step={captureStep}
                                onChange={setCaptureStep}
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

                        {!documentPresented &&
                            (multi ? (
                                <p className="text-warning text-[12.5px]">
                                    Abra e confira todos os arquivos antes de{' '}
                                    {approving ? 'aprovar' : 'assinar'}. Falta
                                    {missingDocs.length === 1 ? '' : 'm'}:{' '}
                                    {missingDocs.map(fileLabel).join(', ')}.
                                    {documentStatus === 'error' &&
                                        ' O arquivo aberto não chegou — use “Tentar de novo” ou “Baixar PDF” na barra do documento.'}
                                </p>
                            ) : (
                                <p className="text-warning text-[12.5px]">
                                    {documentStatus === 'error'
                                        ? 'O documento não chegou. Use “Tentar de novo” ou “Baixar PDF” na barra do documento — só é possível assinar depois de conferir o que está sendo assinado.'
                                        : 'Aguarde o documento terminar de carregar para assinar.'}
                                </p>
                            ))}
                        {documentPresented && pending.length > 0 && (
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
                        {cpfInvalid && (
                            <p className="text-warning text-[12.5px]">
                                Confira o CPF informado: os dígitos não
                                conferem.
                            </p>
                        )}
                        {!captureReady && captureStep && (
                            <p className="text-warning text-[12.5px]">
                                Antes de {approving ? 'aprovar' : 'assinar'},
                                envie:{' '}
                                {captureStep.items
                                    .filter((item) => !item.captured)
                                    .map((item) => item.label.toLowerCase())
                                    .join(', ')}
                                .
                            </p>
                        )}
                        {(localError ||
                            errors.signature ||
                            errors.authorization ||
                            errors.consent ||
                            errors.document) && (
                            <p
                                role="alert"
                                className="text-danger text-[12.5px]"
                            >
                                {localError ??
                                    errors.signature ??
                                    errors.authorization ??
                                    errors.consent ??
                                    errors.document}
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
                            ) : approving ? (
                                <CheckCircle2 className="size-4" />
                            ) : (
                                <PenLine className="size-4" />
                            )}
                            {buttonLabel}
                        </Button>

                        <button
                            type="button"
                            onClick={() => setRefuseOpen(true)}
                            className="text-muted-foreground hover:text-danger text-center text-[12.5px] font-semibold"
                        >
                            {approving
                                ? 'Recusar aprovação'
                                : 'Recusar assinatura'}
                        </button>
                    </div>

                    <ParticipantsCard
                        others={others}
                        recipientName={recipient.name}
                        recipientRoleLabel={myRoleLabel}
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
                noun={approving ? 'aprovação' : 'assinatura'}
            />
        </>
    );
}

/** Placeholder do documento antes da confirmação de identidade. */
function LockedDocument({
    title,
    pages,
    displayCode,
    channel = 'email',
}: {
    title: string;
    pages: number;
    displayCode: string;
    /** Fase 2 §2.9: por onde o código chega (o texto do e-mail é o da Fase 1). */
    channel?: SignerAuth['channel'];
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
                {channel === 'email'
                    ? 'O documento é exibido depois que você confirmar o código enviado ao seu e-mail.'
                    : `O documento é exibido depois que você confirmar o código enviado por ${channelPhraseLabels[channel]} ao seu celular.`}{' '}
                Ele nunca fica acessível por um endereço público.
            </p>
        </div>
    );
}

/**
 * Lista dos demais participantes — sem e-mails (ROUTES §2.18). Visualizadores não
 * aparecem (o servidor já os exclui de `others`); testemunhas e aprovadores mostram o
 * papel, e o aprovador que concluiu aparece como "Aprovou".
 */
function ParticipantsCard({
    others,
    recipientName,
    recipientRoleLabel = null,
    className,
}: {
    others: SignShowProps['others'];
    recipientName: string;
    recipientRoleLabel?: string | null;
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
                        <span className="text-muted-foreground">
                            (você
                            {recipientRoleLabel
                                ? ` · ${recipientRoleLabel.toLowerCase()}`
                                : ''}
                            )
                        </span>
                    </span>
                </li>
                {others.map((other) => {
                    const special =
                        other.participant_role !== undefined &&
                        other.participant_role !== 'signer';

                    return (
                        <li
                            key={`${other.order}-${other.name}`}
                            className="flex items-center justify-between gap-2"
                        >
                            <span className="truncate">
                                {other.name}
                                {special && other.participant_role_label && (
                                    <span className="text-primary">
                                        {' '}
                                        · {other.participant_role_label}
                                    </span>
                                )}
                                {other.role && (
                                    <span className="text-muted-foreground">
                                        {' '}
                                        · {other.role}
                                    </span>
                                )}
                            </span>
                            <span className="text-muted-foreground shrink-0 text-[12px]">
                                {other.status === 'signed'
                                    ? other.participant_role === 'approver'
                                        ? 'Aprovou'
                                        : 'Assinou'
                                    : other.status === 'refused'
                                      ? 'Recusou'
                                      : other.signs_after_me
                                        ? special
                                            ? 'depois de você'
                                            : 'assina depois de você'
                                        : 'pendente'}
                            </span>
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}

SignShow.layout = (props: SignShowProps) => {
    const steps = stepsFor(props.action);

    return {
        sender: props.sender,
        documentTitle: props.envelope?.title ?? null,
        step: STEP_BY_SCREEN[props.screen],
        privacyUrl: props.legal.privacy_url,
        termsUrl: props.legal.terms_url,
        ...(steps ? { steps } : {}),
    };
};
