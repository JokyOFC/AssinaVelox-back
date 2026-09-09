import type { FormDataConvertible } from '@inertiajs/core';
import { Head, Link, router, setLayoutProps, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    Check,
    Loader2,
    MonitorSmartphone,
    Send,
    TriangleAlert,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { useWizardAutosave } from '@/components/envelopes/use-wizard-autosave';
import {
    WizardStepDocument,
    type WizardMetadata,
} from '@/components/envelopes/wizard-step-document';
import { WizardStepFields } from '@/components/envelopes/wizard-step-fields';
import { WizardStepRecipients } from '@/components/envelopes/wizard-step-recipients';
import { WizardStepReview } from '@/components/envelopes/wizard-step-review';
import { usePdfDocument } from '@/components/pdf/use-pdf-document';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { PageHeader } from '@/components/page-header';
import { Stepper } from '@/components/stepper';
import { Button } from '@/components/ui/button';
import { DEFAULT_ZOOM } from '@/components/pdf/pdf-zoom-controls';
import { formatTime } from '@/lib/format';
import {
    destroy as envelopeDestroy,
    edit as envelopeEdit,
    index as envelopesIndex,
    send as envelopeSend,
    update as envelopeUpdate,
} from '@/routes/envelopes';
import {
    destroy as documentDestroy,
    store as documentStore,
} from '@/routes/envelopes/document';
import { sync as fieldsSync } from '@/routes/envelopes/fields';
import { sync as recipientsSync } from '@/routes/envelopes/recipients';
import type { FieldType, SigningOrder } from '@/types/enums';
import type {
    EnvelopeDocument,
    FolderRef,
    WizardField,
    WizardRecipient,
} from '@/types/models';

export type { WizardField, WizardRecipient };

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
    limits: {
        max_upload_bytes: number;
        accepted_mimes: string[];
        max_fields: number;
        max_recipients: number;
        /**
         * Mínimo por tipo em pontos da página exibida, tal como o servidor valida
         * (`FieldGeometry::MINIMUM_POINTS`). `FIELD_MIN_SIZE_PT` em
         * `components/envelopes/field-types.ts` é o espelho local desta tabela; se
         * as duas divergirem, o servidor devolve 422 no `fields.sync`.
         */
        field_minimums: Record<
            FieldType,
            { width_pt: number; height_pt: number }
        >;
    };
    completeness: { document: boolean; recipients: boolean; fields: boolean };
    /**
     * Pendências em PT-BR calculadas pelo backend
     * (`App\Services\Envelopes\EnvelopeReadiness::issues()`).
     */
    issues?: string[];
}

export const WIZARD_STEPS = [
    { key: 'document', title: 'Documento', subtitle: 'Upload ou modelo' },
    { key: 'recipients', title: 'Signatários', subtitle: 'Quem assina e como' },
    { key: 'fields', title: 'Campos', subtitle: 'Posição das assinaturas' },
    { key: 'review', title: 'Revisar', subtitle: 'Mensagem e envio' },
];

const POLL_INTERVAL_MS = 3000;

/**
 * Assinatura da lista local no momento do envio. Se ela mudar enquanto a
 * requisição estava no ar (o usuário continuou editando), a resposta do
 * servidor **não** é adotada — o próximo autosave reconcilia. Sem isso, um
 * arraste concorrente seria desfeito pela resposta.
 */
function signatureOf(items: { client_id: string }[]): string {
    return items.map((item) => item.client_id).join('|');
}

function normalizeEmail(email: string): string {
    return email.trim().toLowerCase();
}

const EMAIL_SHAPE = /^\S+@\S+\.\S+$/;

/**
 * Um destinatário só é sincronizável com nome e e-mail preenchidos: o
 * `PUT recipients.sync` substitui a lista inteira e recusa linhas incompletas
 * (422), então enviar um rascunho pela metade apagaria o que já está salvo.
 */
function recipientIsComplete(recipient: WizardRecipient): boolean {
    return (
        recipient.name.trim().length >= 2 &&
        EMAIL_SHAPE.test(recipient.email.trim())
    );
}

/**
 * Wizard "Nova solicitação" (ROUTES §2.6; DESIGN §6.5): 4 passos com autosave
 * por grupo de alterações. O passo vem da query-string (`?step=`) e o backend
 * pode rebaixá-lo quando o passo anterior está incompleto.
 */
export default function EnvelopeWizard({
    envelope,
    step,
    document,
    recipients: serverRecipients,
    fields: serverFields,
    folders,
    role_suggestions,
    plan,
    limits,
    completeness,
    issues: serverIssues,
}: WizardProps) {
    const { errors } = usePage().props;
    const autosave = useWizardAutosave();

    const [metadata, setMetadata] = useState<WizardMetadata>({
        title: envelope.title,
        folder_id: envelope.folder_id,
        expires_in_days: envelope.expires_in_days,
        message: envelope.message,
        send_copy_to_all: envelope.send_copy_to_all,
    });
    const [signingOrder, setSigningOrder] = useState<SigningOrder>(
        envelope.signing_order,
    );
    const [recipients, setRecipients] =
        useState<WizardRecipient[]>(serverRecipients);
    const [fields, setFields] = useState<WizardField[]>(serverFields);
    const [initialsOnAllPages, setInitialsOnAllPages] = useState(
        envelope.initials_on_all_pages,
    );

    const [page, setPage] = useState(1);
    const [zoom, setZoom] = useState(DEFAULT_ZOOM);
    const [uploadProgress, setUploadProgress] = useState<number | null>(null);
    const [uploadError, setUploadError] = useState<string | null>(null);
    const [sending, setSending] = useState(false);
    const [discarding, setDiscarding] = useState(false);
    const [discardingInFlight, setDiscardingInFlight] = useState(false);

    const processing = document?.processing.status ?? null;
    // `ready` do resource já exige versão exibível — não basta o status.
    const documentReady = document?.processing.ready === true;
    const pdf = usePdfDocument(
        step === 3 && documentReady ? (document?.pdf_url ?? null) : null,
    );

    // Enquanto o arquivo é convertido, recarrega só o que muda (ROUTES §2.6).
    useEffect(() => {
        if (processing !== 'uploaded' && processing !== 'converting') {
            return;
        }

        const timer = setInterval(() => {
            router.reload({
                only: ['document', 'completeness', 'envelope'],
            });
        }, POLL_INTERVAL_MS);

        return () => clearInterval(timer);
    }, [processing]);

    // ---------------------------------------------------------------- autosave

    const saveMetadata = (
        next: WizardMetadata,
        order: SigningOrder = signingOrder,
    ): void => {
        autosave.schedule('metadata', (done) => {
            router.patch(
                envelopeUpdate(envelope.id).url,
                {
                    title: next.title,
                    folder_id: next.folder_id,
                    expires_in_days: next.expires_in_days,
                    message: next.message,
                    signing_order: order,
                    send_copy_to_all: next.send_copy_to_all,
                },
                {
                    preserveState: true,
                    preserveScroll: true,
                    onSuccess: () => done(true),
                    onError: () => done(false),
                },
            );
        });
    };

    const saveRecipients = (
        next: WizardRecipient[],
        order: SigningOrder = signingOrder,
    ): void => {
        if (next.length === 0 || !next.every(recipientIsComplete)) {
            return;
        }

        const sent = signatureOf(next);

        autosave.schedule('recipients', (done) => {
            router.put(
                recipientsSync(envelope.id).url,
                {
                    signing_order: order,
                    recipients: next.map((recipient, index) => ({
                        id: recipient.id,
                        client_id: recipient.client_id,
                        name: recipient.name,
                        email: recipient.email,
                        role: recipient.role,
                        order: index + 1,
                    })),
                },
                {
                    preserveState: true,
                    preserveScroll: true,
                    onSuccess: (nextPage) => {
                        const fresh = (nextPage.props as unknown as WizardProps)
                            .recipients;

                        // O `client_id` que volta é o ULID do servidor, não o id
                        // temporário do cliente: a ligação é feita pelo e-mail,
                        // que é único por envelope. Os campos são remapeados
                        // junto, senão apontariam para um id inexistente.
                        if (Array.isArray(fresh)) {
                            setRecipients((current) => {
                                if (signatureOf(current) !== sent) {
                                    return current;
                                }

                                const remap = new Map<string, string>();

                                for (const row of fresh) {
                                    const local = current.find(
                                        (item) =>
                                            normalizeEmail(item.email) ===
                                            normalizeEmail(row.email),
                                    );

                                    if (local) {
                                        remap.set(
                                            local.client_id,
                                            row.client_id,
                                        );
                                    }
                                }

                                if (remap.size > 0) {
                                    setFields((currentFields) =>
                                        currentFields.map((field) => {
                                            const mapped = remap.get(
                                                field.recipient_client_id,
                                            );

                                            return mapped
                                                ? {
                                                      ...field,
                                                      recipient_client_id:
                                                          mapped,
                                                  }
                                                : field;
                                        }),
                                    );
                                }

                                return fresh;
                            });
                        }

                        done(true);
                    },
                    onError: () => done(false),
                },
            );
        });
    };

    const saveFields = (
        next: WizardField[],
        initials: boolean = initialsOnAllPages,
    ): void => {
        // As rubricas de "todas as páginas" são geradas pelo servidor a cada
        // sync (docs/campos-e-geometria.md §5): reenviá-las duplicaria trabalho
        // e contaria duas vezes no limite de 200 campos.
        const manual = next.filter((field) => field.auto !== true);
        const sent = signatureOf(manual);

        const payload: Record<string, FormDataConvertible>[] = manual.map(
            (field) => ({
                id: field.id,
                client_id: field.client_id,
                recipient_id: field.recipient_client_id,
                recipient_client_id: field.recipient_client_id,
                type: field.type,
                page: field.page,
                x: field.x,
                y: field.y,
                w: field.w,
                h: field.h,
                required: field.required,
                label: field.label,
                placeholder: field.placeholder,
                // Preserva chaves que o backend acrescente (`default`, etc.).
                options: { ...field.options },
            }),
        );

        autosave.schedule('fields', (done) => {
            router.put(
                fieldsSync(envelope.id).url,
                {
                    initials_on_all_pages: initials,
                    fields: payload,
                },
                {
                    preserveState: true,
                    preserveScroll: true,
                    onSuccess: (nextPage) => {
                        const fresh = (nextPage.props as unknown as WizardProps)
                            .fields;

                        // A lista do servidor é a autoridade (traz as rubricas
                        // automáticas e os ULIDs recém-criados) — adotada só se
                        // nada mudou localmente durante a requisição.
                        if (Array.isArray(fresh)) {
                            setFields((current) =>
                                signatureOf(
                                    current.filter(
                                        (field) => field.auto !== true,
                                    ),
                                ) === sent
                                    ? fresh
                                    : current,
                            );
                        }

                        done(true);
                    },
                    onError: () => done(false),
                },
            );
        });
    };

    const patchMetadata = (patch: Partial<WizardMetadata>): void => {
        const next = { ...metadata, ...patch };
        setMetadata(next);
        saveMetadata(next);
    };

    const changeSigningOrder = (order: SigningOrder): void => {
        setSigningOrder(order);
        saveMetadata(metadata, order);
        saveRecipients(recipients, order);
    };

    const changeRecipients = (next: WizardRecipient[]): void => {
        setRecipients(next);
        saveRecipients(next);
    };

    const changeFields = (next: WizardField[]): void => {
        setFields(next);
        saveFields(next);
    };

    const changeInitials = (value: boolean): void => {
        setInitialsOnAllPages(value);
        saveFields(fields, value);
    };

    // ------------------------------------------------------------------ upload

    const uploadDocument = (file: File): void => {
        setUploadError(null);
        setUploadProgress(0);

        router.post(
            documentStore(envelope.id).url,
            { file },
            {
                forceFormData: true,
                preserveState: true,
                preserveScroll: true,
                onProgress: (event) =>
                    setUploadProgress(Math.round(event?.percentage ?? 0)),
                onError: (bag) =>
                    setUploadError(
                        bag.file ?? 'Não foi possível enviar o arquivo.',
                    ),
                onFinish: () => setUploadProgress(null),
            },
        );
    };

    const removeDocument = (): void => {
        setFields([]);
        setUploadError(null);
        router.delete(documentDestroy(envelope.id).url, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    // -------------------------------------------------------------- navegação

    const goToStep = (target: number): void => {
        autosave.flush();
        router.get(
            envelopeEdit(envelope.id, { query: { step: target } }).url,
            {},
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    // Pendências do servidor (autoridade) + as que só o cliente conhece, por
    // haver edição ainda não salva. Os textos repetem palavra por palavra os de
    // `EnvelopeReadiness::issues()` (docs/campos-e-geometria.md §7), então o
    // `Set` elimina a duplicata quando as duas fontes apontam a mesma coisa.
    const localIssues = [
        ...(!completeness.document
            ? [
                  processing === 'blocked'
                      ? 'O arquivo está protegido por senha ou já assinado digitalmente. Envie outro PDF.'
                      : processing === 'failed'
                        ? 'Falha ao processar o arquivo. Envie um PDF válido.'
                        : processing === null
                          ? 'Envie o documento que será assinado.'
                          : 'O documento ainda está sendo processado.',
              ]
            : []),
        ...(!completeness.recipients
            ? ['Adicione pelo menos um signatário.']
            : []),
        ...(recipients.some((recipient) => !recipientIsComplete(recipient))
            ? ['Informe nome e e-mail de todos os signatários.']
            : []),
        ...(new Set(
            recipients.map((recipient) => normalizeEmail(recipient.email)),
        ).size !== recipients.length
            ? ['Cada signatário precisa de um e-mail diferente.']
            : []),
        ...(!completeness.fields
            ? ['Todo signatário precisa de pelo menos um campo de assinatura.']
            : []),
        ...(!plan.can_send && plan.reason ? [plan.reason] : []),
    ];

    const issues = [...new Set([...(serverIssues ?? []), ...localIssues])];

    const send = (): void => {
        autosave.flush();
        setSending(true);
        router.post(
            envelopeSend(envelope.id).url,
            {},
            {
                preserveScroll: true,
                // O envio troca de página (wizard → detalhe). Sem `preserveState: false`
                // o Inertia mantém o estado do wizard no swap e, com ele, os
                // `setLayoutProps` desta página: o detalhe abria com a trilha "Nova
                // solicitação" e sem a busca no topo, até a próxima navegação completa.
                preserveState: false,
                onError: () =>
                    toast.error(
                        'Não foi possível enviar. Revise as pendências acima.',
                    ),
                onFinish: () => setSending(false),
            },
        );
    };

    const savedLabel =
        autosave.status === 'saving' || autosave.status === 'pending'
            ? 'Salvando…'
            : autosave.status === 'error'
              ? 'Não foi possível salvar'
              : `Rascunho salvo às ${formatTime((autosave.savedAt ?? new Date(envelope.updated_at)).toISOString())}`;

    setLayoutProps({
        breadcrumbs: [
            { title: 'Documentos', href: envelopesIndex() },
            {
                title: 'Nova solicitação',
                href: envelopeEdit(envelope.id),
            },
        ],
        hideSearch: true,
        topbarExtra: (
            <span
                aria-live="polite"
                className={
                    autosave.status === 'error'
                        ? 'text-danger hidden text-[12.5px] font-semibold sm:inline'
                        : 'text-muted-foreground hidden text-[12.5px] sm:inline'
                }
            >
                {savedLabel}
            </span>
        ),
    });

    const stepperSteps = WIZARD_STEPS.map((definition, index) => ({
        ...definition,
        disabled:
            (index >= 1 && !completeness.document) ||
            (index >= 2 && recipients.length === 0),
    }));

    return (
        <>
            <Head title={metadata.title || 'Nova solicitação'} />

            <div className="mx-auto flex w-full max-w-[1040px] flex-col gap-5">
                <PageHeader
                    title="Nova solicitação de assinatura"
                    subtitle="Envie o documento, defina quem assina, posicione os campos e revise antes de enviar."
                    actions={
                        <div className="flex items-center gap-2">
                            {/*
                                "Sair" preserva o rascunho (o autosave já gravou tudo).
                                "Descartar" existe porque o assistente cria o envelope no
                                primeiro clique: sem esta saída, quem só foi olhar não teria
                                como remover o que não pediu para criar.
                            */}
                            <Button
                                variant="ghost"
                                onClick={() => setDiscarding(true)}
                                disabled={sending}
                            >
                                Descartar
                            </Button>
                            <Button asChild variant="outline">
                                <Link href={envelopesIndex()}>Sair</Link>
                            </Button>
                        </div>
                    }
                />

                <ConfirmDialog
                    open={discarding}
                    onOpenChange={setDiscarding}
                    title="Descartar este rascunho?"
                    description="O documento enviado, os signatários e os campos deste rascunho serão removidos. Documentos já enviados para assinatura não são afetados."
                    confirmLabel="Descartar rascunho"
                    cancelLabel="Continuar editando"
                    destructive
                    processing={discardingInFlight}
                    onConfirm={() => {
                        setDiscardingInFlight(true);
                        router.delete(envelopeDestroy(envelope.id).url, {
                            preserveScroll: true,
                            onFinish: () => setDiscardingInFlight(false),
                        });
                    }}
                />

                <Stepper
                    steps={stepperSteps}
                    current={step - 1}
                    onSelect={(index) => goToStep(index + 1)}
                />

                {step === 3 && (
                    <div className="border-primary-soft-border bg-primary-soft text-primary flex items-start gap-2 rounded-[10px] border p-3 text-[12.5px] leading-[1.5] md:hidden">
                        <MonitorSmartphone className="mt-0.5 size-4 shrink-0" />
                        <span>
                            A preparação dos campos funciona melhor no
                            computador. Aqui você consegue conferir o documento
                            e ajustar os campos existentes.
                        </span>
                    </div>
                )}

                {step === 1 && (
                    <WizardStepDocument
                        document={document}
                        folders={folders}
                        limits={limits}
                        metadata={metadata}
                        onMetadataChange={patchMetadata}
                        onUpload={uploadDocument}
                        onUploadReject={setUploadError}
                        onRemove={removeDocument}
                        uploadProgress={uploadProgress}
                        uploadError={uploadError}
                        errors={errors}
                    />
                )}

                {step === 2 && (
                    <WizardStepRecipients
                        recipients={recipients}
                        signingOrder={signingOrder}
                        roleSuggestions={role_suggestions}
                        onChange={changeRecipients}
                        onSigningOrderChange={changeSigningOrder}
                        errors={errors}
                    />
                )}

                {step === 3 &&
                    (document ? (
                        <WizardStepFields
                            document={document}
                            pdf={pdf}
                            page={page}
                            onPageChange={setPage}
                            zoom={zoom}
                            onZoomChange={setZoom}
                            recipients={recipients}
                            fields={fields}
                            onFieldsChange={changeFields}
                            initialsOnAllPages={initialsOnAllPages}
                            onInitialsOnAllPagesChange={changeInitials}
                            errors={errors}
                        />
                    ) : (
                        <div className="border-border bg-card shadow-card text-text-secondary flex items-center gap-2 rounded-xl border p-5 text-[13px]">
                            <TriangleAlert className="text-warning size-4" />
                            Envie um documento no passo 1 para posicionar os
                            campos.
                        </div>
                    ))}

                {step === 4 && (
                    <WizardStepReview
                        document={document}
                        folder={
                            folders.find(
                                (folder) => folder.id === metadata.folder_id,
                            ) ?? null
                        }
                        title={metadata.title}
                        expiresInDays={metadata.expires_in_days}
                        signingOrder={signingOrder}
                        recipients={recipients}
                        fields={fields}
                        initialsOnAllPages={initialsOnAllPages}
                        message={metadata.message}
                        onMessageChange={(message) =>
                            patchMetadata({ message })
                        }
                        sendCopyToAll={metadata.send_copy_to_all}
                        onSendCopyChange={(send_copy_to_all) =>
                            patchMetadata({ send_copy_to_all })
                        }
                        plan={plan}
                        issues={issues}
                        onEditStep={goToStep}
                        disabled={sending}
                    />
                )}

                <div className="border-border flex flex-wrap items-center justify-between gap-3 border-t pt-4 pb-2">
                    <Button
                        variant="outline"
                        disabled={step === 1}
                        onClick={() => goToStep(step - 1)}
                    >
                        <ArrowLeft className="size-[15px]" />
                        Voltar
                    </Button>

                    <div className="flex items-center gap-2">
                        <span
                            aria-hidden
                            className="text-muted-foreground inline-flex items-center gap-1.5 text-[12.5px] sm:hidden"
                        >
                            {autosave.status === 'saving' ||
                            autosave.status === 'pending' ? (
                                <Loader2 className="size-3.5 animate-spin" />
                            ) : (
                                <Check className="size-3.5" />
                            )}
                        </span>
                        <Button asChild variant="link" size="sm">
                            <Link href={envelopesIndex()}>Salvar rascunho</Link>
                        </Button>
                        {step < 4 ? (
                            <Button onClick={() => goToStep(step + 1)}>
                                Continuar
                                <ArrowRight className="size-[15px]" />
                            </Button>
                        ) : (
                            <Button
                                onClick={send}
                                disabled={
                                    sending ||
                                    issues.length > 0 ||
                                    !plan.can_send
                                }
                            >
                                {sending ? (
                                    <Loader2 className="size-[15px] animate-spin" />
                                ) : (
                                    <Send className="size-[15px]" />
                                )}
                                Enviar para assinatura
                            </Button>
                        )}
                    </div>
                </div>
            </div>
        </>
    );
}
