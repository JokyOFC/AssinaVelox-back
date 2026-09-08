import { Head, Link, router } from '@inertiajs/react';
import { Construction, Save } from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import { PageHeader } from '@/components/page-header';
import { Stepper } from '@/components/stepper';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatTime } from '@/lib/format';
import {
    edit as envelopeEdit,
    index as envelopesIndex,
    show as envelopeShow,
} from '@/routes/envelopes';
import type {
    DocumentProcessingStatus,
    EnvelopeDocument,
    FieldType,
    FolderRef,
    SigningOrder,
} from '@/types';

export interface WizardRecipient {
    id: string | null;
    client_id: string;
    name: string;
    email: string;
    role: string;
    order: number;
    color_index: 0 | 1 | 2 | 3;
    channel: 'email';
    auth_methods: ['email_otp'];
}

export interface WizardField {
    id: string | null;
    client_id: string;
    recipient_client_id: string;
    type: FieldType;
    page: number | 'all';
    x: number;
    y: number;
    w: number;
    h: number;
    required: boolean;
    label: string | null;
    placeholder: string | null;
}

export interface WizardProps {
    envelope: {
        id: string;
        display_code: string;
        status: 'draft' | 'preparing' | 'ready';
        title: string;
        folder_id: string | null;
        expires_in_days: number;
        message: string;
        signing_order: SigningOrder;
        send_copy_to_all: boolean;
        initials_on_all_pages: boolean;
        updated_at: string;
    };
    step: 1 | 2 | 3 | 4;
    document: EnvelopeDocument | null;
    recipients: WizardRecipient[];
    fields: WizardField[];
    folders: FolderRef[];
    defaults: {
        expires_in_days: number;
        signing_order: SigningOrder;
        initials_on_all_pages: boolean;
    };
    role_suggestions: string[];
    plan: {
        envelopes_used: number;
        envelopes_limit: number | null;
        can_send: boolean;
        reason: string | null;
    };
    limits: { max_upload_bytes: number; accepted_mimes: string[] };
    completeness: { document: boolean; recipients: boolean; fields: boolean };
}

export const WIZARD_STEPS = [
    { key: 'document', title: 'Documento', subtitle: 'Upload ou modelo' },
    { key: 'recipients', title: 'Signatários', subtitle: 'Quem assina e como' },
    { key: 'fields', title: 'Campos', subtitle: 'Posição das assinaturas' },
    { key: 'review', title: 'Revisar', subtitle: 'Mensagem e envio' },
];

const PROCESSING_LABEL: Record<DocumentProcessingStatus, string> = {
    uploaded: 'enviado',
    converting: 'convertendo…',
    ready: 'pronto',
    failed: 'falhou',
    blocked: 'bloqueado',
};

/**
 * Wizard "Nova solicitação" (ROUTES §2.6; DESIGN §6.5) — casca inicial:
 * stepper clicável, header com autosave e resumo do estado do rascunho.
 * Wave B implementa upload, signatários, editor de campos e revisão.
 */
export default function EnvelopeWizard({
    envelope,
    step,
    document,
    recipients,
    fields,
    completeness,
    plan,
}: WizardProps) {
    const goTo = (index: number) => {
        router.get(
            envelopeEdit(envelope.id, { query: { step: index + 1 } }).url,
            {},
            { preserveScroll: true },
        );
    };

    return (
        <>
            <Head title={envelope.title || 'Nova solicitação'} />
            <div className="mx-auto flex w-full max-w-[1040px] flex-col gap-5">
                <PageHeader
                    title={envelope.title || 'Nova solicitação'}
                    subtitle={`${envelope.display_code} · rascunho`}
                    actions={
                        <Button asChild variant="outline">
                            <Link href={envelopesIndex()}>
                                <Save className="size-[15px]" />
                                Salvar rascunho e sair
                            </Link>
                        </Button>
                    }
                />

                <Stepper
                    steps={WIZARD_STEPS.map((s, index) => ({
                        ...s,
                        disabled: index > 0 && !completeness.document,
                    }))}
                    current={step - 1}
                    onSelect={goTo}
                />

                <div className="border-border bg-card shadow-card rounded-xl border">
                    <EmptyState
                        icon={Construction}
                        title={`Passo ${step} · ${WIZARD_STEPS[step - 1].title}`}
                        description={
                            <>
                                <Badge variant="phase" className="mb-3">
                                    Em construção · Wave B
                                </Badge>
                                <br />
                                Upload do documento, cadastro de signatários,
                                editor de campos sobre o PDF e revisão serão
                                entregues na próxima onda. O rascunho já está
                                persistido e pode ser retomado.
                            </>
                        }
                        action={
                            <Button asChild variant="outline">
                                <Link href={envelopeShow(envelope.id)}>
                                    Ver detalhe do documento
                                </Link>
                            </Button>
                        }
                    />
                    <dl className="border-muted grid gap-x-6 gap-y-2 border-t px-6 py-4 text-[12.5px] sm:grid-cols-2">
                        <div className="flex justify-between gap-3">
                            <dt className="text-muted-foreground">Documento</dt>
                            <dd className="font-medium">
                                {document
                                    ? `${document.original_name} · ${PROCESSING_LABEL[document.processing.status]}`
                                    : 'nenhum arquivo'}
                            </dd>
                        </div>
                        <div className="flex justify-between gap-3">
                            <dt className="text-muted-foreground">
                                Signatários
                            </dt>
                            <dd className="font-medium">{recipients.length}</dd>
                        </div>
                        <div className="flex justify-between gap-3">
                            <dt className="text-muted-foreground">Campos</dt>
                            <dd className="font-medium">{fields.length}</dd>
                        </div>
                        <div className="flex justify-between gap-3">
                            <dt className="text-muted-foreground">
                                Consumo do plano
                            </dt>
                            <dd className="tabular font-medium">
                                {plan.envelopes_used}
                                {plan.envelopes_limit !== null &&
                                    ` / ${plan.envelopes_limit}`}
                                {!plan.can_send && plan.reason && (
                                    <span className="text-danger ml-1">
                                        · {plan.reason}
                                    </span>
                                )}
                            </dd>
                        </div>
                    </dl>
                </div>
            </div>
        </>
    );
}

EnvelopeWizard.layout = (props: WizardProps) => ({
    breadcrumbs: [
        { title: 'Documentos', href: envelopesIndex() },
        { title: 'Nova solicitação', href: envelopeEdit(props.envelope.id) },
    ],
    hideSearch: true,
    topbarExtra: (
        <span className="text-muted-foreground hidden text-[12.5px] sm:inline">
            Rascunho salvo às {formatTime(props.envelope.updated_at)}
        </span>
    ),
});
