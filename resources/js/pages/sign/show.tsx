import { Head, router, usePage } from '@inertiajs/react';
import {
    Check,
    CheckCircle2,
    Download,
    FileLock2,
    PenLine,
    ShieldCheck,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import type { SignerBrand } from '@/components/branding/types';
import { ParticipantCertificateCard } from '@/components/certificates/participant-certificate-card';
import { ExternalSigningCard } from '@/components/external-signing/external-signing-card';
import { GovBrReturnCard } from '@/components/external-signing/govbr-return-card';
import { DelegationCard } from '@/components/sign/delegation/delegation-card';
import {
    CaptureStepCard,
    CaptureStepPreview,
} from '@/components/identity/capture-step';
import {
    useVideoStep,
    VideoCaptureStepCard,
} from '@/components/identity/video-capture-step';
import type { IdentityVideoStep } from '@/components/identity/video-types';
import { DocumentSwitcher } from '@/components/pdf/document-switcher';
import type { PdfDocumentStatus } from '@/components/pdf/use-pdf-document';
import {
    ConsentBox,
    type ConsentTranslation,
    defaultConsentLabel,
} from '@/components/sign/consent-box';
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
    type I18n,
    type Locale,
    type SignerI18nProps,
    translate,
    useI18n,
} from '@/i18n';
import { isLocale, REFERENCE_LOCALE } from '@/i18n/locales';
import { isValidCpf } from '@/lib/format';
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
        /**
         * Fase 3 §3.3 (F-I18N): tradução de cortesia do rótulo e da declaração. Só existe
         * com a flag `multilingual` ligada e idioma diferente do PT-BR; `checkbox_label` e
         * `statement` continuam sendo o texto de referência, o que é gravado.
         */
        translation?: ConsentTranslation | null;
    } | null;
    /** Aviso de privacidade ao signatário, já com as variáveis substituídas. */
    privacy: {
        version: string;
        summary: string;
        notice: string;
        /** F-I18N: o original em PT-BR quando `summary`/`notice` são tradução. */
        reference?: { summary: string | null; notice: string | null } | null;
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
    /** Fase 3 §3.3 (F-VIDEO): só existe quando o vídeo curto foi exigido desta pessoa. */
    identity_video?: IdentityVideoStep | null;
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
    /** Fase 3 §3.3 (F-I18N): prop compartilhada, só com a flag `multilingual` ligada. */
    i18n?: SignerI18nProps | null;
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
    locale: Locale,
): StepperStep[] | undefined {
    if (action?.type === 'approve') {
        return [
            {
                key: 'identify',
                title: translate(locale, 'layout.steps.identify'),
            },
            { key: 'sign', title: translate(locale, 'sign.steps.approve') },
            {
                key: 'completed',
                title: translate(locale, 'layout.steps.completed'),
            },
        ];
    }

    if (action?.type === 'view') {
        return [
            {
                key: 'identify',
                title: translate(locale, 'layout.steps.identify'),
            },
            { key: 'sign', title: translate(locale, 'sign.steps.view') },
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

function fileLabel(
    item: { position: number; name: string | null },
    t: I18n['t'],
): string {
    return t('sign.file_label', {
        position: item.position,
        name:
            item.name?.trim() ||
            t('sign.file_fallback', { position: item.position }),
    });
}

/** O original em PT-BR do aviso de privacidade, quando o exibido é tradução. */
function referenceNotice(
    privacy: SignShowProps['privacy'],
): PrivacyNoticeContent | null {
    const reference = privacy?.reference;

    return reference?.summary
        ? { summary: reference.summary, body: reference.notice ?? null }
        : null;
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
 *
 * Fase 3 §3.3 (F-I18N): os textos da tela vêm do dicionário (`@/i18n`) no idioma da prop
 * `i18n` — PT-BR, idêntico ao de antes, quando ela não existe. O texto jurídico traduzido
 * é tradução de cortesia; o de referência continua sendo o conferido e gravado.
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
        identity_video = null,
    } = props;

    const i18n = useI18n();
    const { t, tp, rich } = i18n;
    const errors = usePage().props.errors;

    /*
     * Fase 2 §2.12: quando a assinatura com o certificado do participante sai de "em
     * aplicação", o comprovante e a tela (finalizando → concluído) são recarregados.
     */
    const reloadReceipt = useCallback(() => {
        router.reload({ only: ['screen', 'receipt', 'others'] });
    }, []);
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

    // Fase 3 §3.3 (F-VIDEO): vídeo curto exigido; o servidor recusa o aceite sem ele.
    const videoCapture = useVideoStep(identity_video);

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
    const verb = approving ? t('common.verb.approve') : t('common.verb.sign');

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
                          ? t('sign.other.signs_after_you')
                          : t('sign.other.not_signed_yet')
                  }`,
            documentId: field.document_id ?? firstDocId,
        }));
    }, [other_fields, others, firstDocId, t]);

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
        videoCapture.ready &&
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
            setLocalError(t('sign.error.signature_too_large'));

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
                <Head title={t('sign.invalid.title')} />
                <div className="mx-auto w-full max-w-[520px]">
                    <TerminalCard
                        icon={TERMINAL_ICONS.invalid}
                        title={t('sign.invalid.title')}
                    >
                        {t('sign.invalid.body')}
                    </TerminalCard>
                </div>
            </>
        );
    }

    const notice: PrivacyNoticeContent = privacy
        ? { summary: privacy.summary, body: privacy.notice }
        : defaultPrivacyNotice(sender.organization_name);
    const noticeReference = referenceNotice(privacy);

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
                            title={t('sign.expired.title')}
                        >
                            {t('sign.expired.body', {
                                date: i18n.dateMedium(envelope.expires_at),
                                sender: sender.user_name,
                                organization: sender.organization_name,
                            })}
                        </TerminalCard>
                    )}
                    {screen === 'canceled' && (
                        <TerminalCard
                            icon={TERMINAL_ICONS.canceled}
                            title={t('sign.canceled.title')}
                        >
                            {t('sign.canceled.body', {
                                organization: sender.organization_name,
                            })}
                        </TerminalCard>
                    )}
                    {screen === 'refused' && (
                        <TerminalCard
                            icon={TERMINAL_ICONS.refused}
                            tone="danger"
                            title={
                                approver
                                    ? t('sign.refused.title_approve')
                                    : t('sign.refused.title_sign')
                            }
                        >
                            {refusal ? (
                                <>
                                    {t(
                                        approver
                                            ? 'sign.refused.at_approve'
                                            : 'sign.refused.at_sign',
                                        {
                                            date: i18n.dateTime(
                                                refusal.refused_at,
                                            ),
                                        },
                                    )}
                                    <span className="mt-1.5 block">
                                        {t('sign.refused.reason', {
                                            reason: refusal.reason,
                                        })}
                                    </span>
                                </>
                            ) : approver ? (
                                t('sign.refused.approve')
                            ) : (
                                t('sign.refused.sign')
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
                <Head
                    title={t('sign.tab.receipt', { title: envelope.title })}
                />
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
                                    {t('sign.receipt_fallback')}
                                </p>
                            )}
                        </div>
                        {/*
                         * Fase 2 §2.12: quem já aceitou e voltou para enviar o próprio
                         * certificado sem a janela de download confirma a identidade de
                         * novo aqui (o servidor só manda `otp` nesse caso).
                         */}
                        {otp && (
                            <div
                                id="certificate-reauth"
                                className="border-border bg-card shadow-card mt-4 rounded-[14px] border p-5 sm:p-[22px]"
                            >
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
                                    noticeReference={noticeReference}
                                    termsUrl={legal.terms_url}
                                    privacyUrl={legal.privacy_url}
                                    errors={errors}
                                    codeLength={limits?.otp_length}
                                    ttlMinutes={limits?.otp_ttl_minutes}
                                    heading={t('sign.heading.certificate')}
                                    auth={auth}
                                />
                            </div>
                        )}
                        {/* Fase 2 §2.12: só aparece se o servidor oferecer (404 = oculto). */}
                        {action?.type !== 'approve' &&
                            action?.type !== 'view' && (
                                <ParticipantCertificateCard
                                    token={token}
                                    className="mt-4"
                                    onApplied={reloadReceipt}
                                />
                            )}
                        {/*
                         * Fase 3 §3.4 e §3.5: token (A3) por componente local e devolução pelo
                         * portal gov.br — cada um só aparece se o servidor oferecer (404 = oculto).
                         */}
                        {action?.type !== 'approve' &&
                            action?.type !== 'view' && (
                                <>
                                    {/*
                                     * `key={screen}`: finalizando → concluído remonta os cartões,
                                     * que reconsultam o estado (senão ficariam com o de antes).
                                     */}
                                    <ExternalSigningCard
                                        key={`external-${screen}`}
                                        token={token}
                                        className="mt-4"
                                        onApplied={reloadReceipt}
                                        reauthAvailable={otp !== null}
                                    />
                                    <GovBrReturnCard
                                        key={`govbr-${screen}`}
                                        token={token}
                                        className="mt-4"
                                        onCompleted={reloadReceipt}
                                        reauthAvailable={otp !== null}
                                    />
                                </>
                            )}
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
        const tabKey =
            recipient.participant_role === 'viewer'
                ? 'sign.tab.document'
                : approving
                  ? 'sign.tab.approve'
                  : witnessing
                    ? 'sign.tab.witness'
                    : 'sign.tab.sign';

        return (
            <>
                <Head title={t(tabKey, { title: envelope.title })} />
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
                            noticeReference={noticeReference}
                            termsUrl={legal.terms_url}
                            privacyUrl={legal.privacy_url}
                            errors={errors}
                            codeLength={limits?.otp_length}
                            ttlMinutes={limits?.otp_ttl_minutes}
                            heading={
                                action?.type === 'approve'
                                    ? t('sign.heading.approve')
                                    : action?.type === 'view'
                                      ? t('sign.heading.view')
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
                    ? t('sign.files.view_label')
                    : t('sign.files.sign_label')
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
                    name:
                        item.name?.trim() ||
                        t('sign.file_fallback', { position: item.position }),
                    meta:
                        screen === 'view'
                            ? tp('common.pages', item.pages)
                            : !open
                              ? t('sign.files.not_opened')
                              : missing > 0
                                ? tp('sign.files.pending', missing)
                                : t('sign.files.done'),
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
                <Head
                    title={t('sign.tab.document', { title: envelope.title })}
                />

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
                                        ? fileLabel(currentDoc, t)
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
                                    {t('sign.code_confirmed')}
                                </Badge>
                                <h1 className="mt-3 text-[20px] leading-[1.25] font-bold tracking-[-.01em]">
                                    {t('sign.viewer.title')}
                                </h1>
                                <p className="text-text-secondary mt-1.5 text-[13.5px] leading-[1.55]">
                                    {copy?.notice ??
                                        t('sign.viewer.notice_fallback')}
                                </p>
                            </div>

                            <p className="border-border bg-sidebar text-text-secondary rounded-[10px] border p-3 text-[12.5px] leading-[1.5]">
                                {t('sign.viewer.readonly')}
                            </p>

                            {copy?.can_download && available.length > 0 ? (
                                <div className="flex flex-col gap-2">
                                    <p className="text-[13px] font-semibold">
                                        {copy.completed_at
                                            ? t('sign.viewer.final_completed', {
                                                  date: i18n.dateTime(
                                                      copy.completed_at,
                                                  ),
                                              })
                                            : t('sign.viewer.final')}
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
                                                        ? t(
                                                              'sign.viewer.download_file',
                                                              {
                                                                  file: fileLabel(
                                                                      item,
                                                                      t,
                                                                  ),
                                                              },
                                                          )
                                                        : t(
                                                              'sign.viewer.download_final',
                                                          )}
                                                </span>
                                            </a>
                                        </Button>
                                    ))}
                                </div>
                            ) : (
                                <Button variant="outline" disabled>
                                    <Download className="size-4" />
                                    {t('sign.viewer.available_when_done')}
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
    const buttonLabel = action?.button_label ?? t('sign.button_fallback');

    return (
        <>
            <Head title={t('sign.tab.sign', { title: envelope.title })} />

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
                                    ? fileLabel(currentDoc, t)
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
                                {t('sign.code_confirmed')}
                            </Badge>
                            <h1 className="mt-3 text-[20px] leading-[1.25] font-bold tracking-[-.01em]">
                                {approving
                                    ? t('sign.panel.title_approve')
                                    : witnessing
                                      ? t('sign.panel.title_witness')
                                      : t('sign.panel.title_sign')}
                            </h1>
                            <p className="text-text-secondary mt-1.5 text-[13.5px] leading-[1.55]">
                                {approving
                                    ? rich('sign.panel.approve_intro', {
                                          what: multi
                                              ? t('sign.panel.approve_files')
                                              : t(
                                                    'sign.panel.approve_document',
                                                ),
                                          bold: (
                                              <b className="text-foreground">
                                                  {t('sign.panel.approve_bold')}
                                              </b>
                                          ),
                                      })
                                    : witnessing
                                      ? rich('sign.panel.witness_intro', {
                                            bold: (
                                                <b className="text-foreground">
                                                    {t(
                                                        'sign.panel.witness_bold',
                                                    )}
                                                </b>
                                            ),
                                        })
                                      : rich('sign.panel.sign_intro', {
                                            bold: (
                                                <b className="text-foreground">
                                                    {t('sign.panel.sign_bold')}
                                                </b>
                                            ),
                                        })}
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
                                    {t('sign.panel.initials')}
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

                        {videoCapture.step && (
                            <VideoCaptureStepCard
                                step={videoCapture.step}
                                onChange={videoCapture.setStep}
                                className="border-border border-t pt-4"
                            />
                        )}

                        <PrivacyNotice
                            notice={notice}
                            privacyUrl={legal.privacy_url}
                            reference={noticeReference}
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
                            translation={consent?.translation ?? null}
                        />

                        {!documentPresented &&
                            (multi ? (
                                <p className="text-warning text-[12.5px]">
                                    {tp(
                                        'sign.warn.open_all',
                                        missingDocs.length,
                                        {
                                            verb,
                                            files: missingDocs
                                                .map((item) =>
                                                    fileLabel(item, t),
                                                )
                                                .join(', '),
                                        },
                                    )}
                                    {documentStatus === 'error' &&
                                        t('sign.warn.file_not_arrived')}
                                </p>
                            ) : (
                                <p className="text-warning text-[12.5px]">
                                    {documentStatus === 'error'
                                        ? t('sign.warn.document_not_arrived')
                                        : t('sign.warn.document_loading')}
                                </p>
                            ))}
                        {documentPresented && pending.length > 0 && (
                            <p className="text-warning text-[12.5px]">
                                {tp('sign.warn.pending', pending.length)}
                            </p>
                        )}
                        {cpfInvalid && (
                            <p className="text-warning text-[12.5px]">
                                {t('sign.warn.cpf')}
                            </p>
                        )}
                        {!videoCapture.ready && (
                            <p className="text-warning text-[12.5px]">
                                {t('sign.warn.video', { verb })}
                            </p>
                        )}
                        {!captureReady && captureStep && (
                            <p className="text-warning text-[12.5px]">
                                {t('sign.warn.capture', {
                                    verb,
                                    items: captureStep.items
                                        .filter((item) => !item.captured)
                                        .map((item) => item.label.toLowerCase())
                                        .join(', '),
                                })}
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
                                ? t('sign.refuse.approve')
                                : t('sign.refuse.sign')}
                        </button>
                    </div>

                    {/*
                     * Fase 2 §2.12: opção SEPARADA da representação visual e do aceite —
                     * o servidor decide se é oferecida (404 = o cartão não aparece).
                     */}
                    {!approving && <ParticipantCertificateCard token={token} />}
                    {/* Fase 3 §3.4 e §3.5: 404 = o cartão não existe (flag desligada). */}
                    {!approving && <ExternalSigningCard token={token} />}
                    {!approving && <GovBrReturnCard token={token} />}
                    {/* Fase 3 §3.3 (F-FLOW): 404 = o cartão de delegação não existe. */}
                    <DelegationCard
                        token={token}
                        noun={approving ? 'aprovação' : 'assinatura'}
                    />

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
    const { t, tp } = useI18n();

    return (
        <div className="border-border bg-card shadow-card flex flex-col items-center gap-3 rounded-xl border p-8 text-center">
            <span className="bg-primary-soft text-primary flex size-12 items-center justify-center rounded-xl">
                <FileLock2 className="size-5" />
            </span>
            <p className="text-[15px] font-semibold">{title}</p>
            <p className="text-muted-foreground tabular text-[12.5px]">
                {displayCode} · {tp('common.pages', pages)}
            </p>
            <p className="text-text-secondary max-w-[380px] text-[13px] leading-[1.55]">
                {channel === 'email'
                    ? t('sign.locked.email')
                    : t('sign.locked.phone', {
                          channel: t(`common.channel.${channel}`),
                      })}{' '}
                {t('sign.locked.never_public')}
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
    const { t } = useI18n();

    if (others.length === 0) {
        return null;
    }

    return (
        <div
            className={`border-border bg-card shadow-card rounded-[14px] border p-4 ${className ?? ''}`}
        >
            <h2 className="flex items-center gap-1.5 text-[13.5px] font-semibold">
                <ShieldCheck className="text-primary size-3.5" />
                {t('sign.participants.title')}
            </h2>
            <ul className="mt-2.5 flex flex-col gap-2 text-[12.5px]">
                <li className="flex items-center justify-between gap-2">
                    <span className="truncate font-medium">
                        {recipientName}{' '}
                        <span className="text-muted-foreground">
                            ({t('sign.participants.you')}
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
                                        ? t('sign.participants.approved')
                                        : t('sign.participants.signed')
                                    : other.status === 'refused'
                                      ? t('sign.participants.refused')
                                      : other.signs_after_me
                                        ? special
                                            ? t('sign.participants.after_you')
                                            : t(
                                                  'sign.participants.signs_after_you',
                                              )
                                        : t('sign.participants.pending')}
                            </span>
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}

SignShow.layout = (props: SignShowProps) => {
    const locale: Locale = isLocale(props.i18n?.locale)
        ? props.i18n.locale
        : REFERENCE_LOCALE;
    const steps = stepsFor(props.action, locale);

    return {
        sender: props.sender,
        documentTitle: props.envelope?.title ?? null,
        step: STEP_BY_SCREEN[props.screen],
        privacyUrl: props.legal.privacy_url,
        termsUrl: props.legal.terms_url,
        ...(steps ? { steps } : {}),
    };
};
