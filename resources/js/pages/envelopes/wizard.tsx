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
import { remindersUrl } from '@/components/envelopes/phase2-routes';
import { ScheduleSendCard } from '@/components/envelopes/schedule-send-card';
import { useWizardAutosave } from '@/components/envelopes/use-wizard-autosave';
import {
    WizardStepDocument,
    type WizardMetadata,
} from '@/components/envelopes/wizard-step-document';
import { WizardStepFields } from '@/components/envelopes/wizard-step-fields';
import {
    roleOf,
    WizardStepRecipients,
} from '@/components/envelopes/wizard-step-recipients';
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
    DomainFeatures,
    EnvelopeDocument,
    EnvelopeReminders,
    FolderRef,
    ParticipantRoleOption,
    ReminderSettings,
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
    /**
     * Fase 2 §2.3: todos os arquivos, na ordem de apresentação (o primeiro é `document`).
     * Contrato em docs/fase-2/multi-documento-e-papeis.md §8.1.
     */
    documents?: EnvelopeDocument[];
    /** Flags de domínio desta organização (config global **e** plano). */
    domain_features?: DomainFeatures;
    /** Rótulos PT-BR dos papéis de participante (Fase 2 §2.4). */
    participant_roles?: ParticipantRoleOption[];
    /**
     * Fase 2 §2.5 (`ReminderProps::forEnvelope`). Ainda não exposta por
     * `EnvelopeController::edit` — ausente, a interface fica a da Fase 1.
     */
    reminders?: EnvelopeReminders | null;
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
        /** Fase 2 §2.3: 1 com a flag `multi_document` desligada. */
        max_documents?: number;
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

/** Com papéis de participante (Fase 2 §2.4) o passo 2 deixa de ser só de quem assina. */
const WIZARD_STEPS_WITH_ROLES = WIZARD_STEPS.map((definition) =>
    definition.key === 'recipients'
        ? {
              ...definition,
              title: 'Participantes',
              subtitle: 'Quem assina, aprova ou acompanha',
          }
        : definition,
);

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

function isProcessing(document: EnvelopeDocument): boolean {
    return (
        document.processing.status === 'uploaded' ||
        document.processing.status === 'converting'
    );
}

/**
 * Wizard "Nova solicitação" (ROUTES §2.6; DESIGN §6.5): 4 passos com autosave
 * por grupo de alterações. O passo vem da query-string (`?step=`) e o backend
 * pode rebaixá-lo quando o passo anterior está incompleto.
 *
 * Fase 2 (onda A) — cada recurso só aparece com a sua flag; com todas desligadas o
 * wizard é exatamente o da Fase 1 (mesmas telas, mesmos payloads):
 * - `domain_features.multi_document` (§2.3): vários arquivos, ordem e campos por arquivo;
 * - `domain_features.participant_roles` (§2.4): testemunha, aprovador e visualizador;
 * - `reminders.available` (§2.5): lembretes automáticos e envio agendado;
 * - `features.templates` (§2.1): seletor "Ou comece por um modelo".
 */
export default function EnvelopeWizard({
    envelope,
    step,
    document,
    documents: serverDocuments,
    domain_features,
    participant_roles,
    reminders,
    recipients: serverRecipients,
    fields: serverFields,
    folders,
    role_suggestions,
    plan,
    limits,
    completeness,
    issues: serverIssues,
}: WizardProps) {
    const { errors, features } = usePage().props;
    const autosave = useWizardAutosave();

    const multiDocument =
        domain_features?.multi_document ?? features?.multi_document ?? false;
    const participantRoles =
        domain_features?.participant_roles ??
        features?.participant_roles ??
        false;
    const remindersAvailable = reminders?.available === true;
    const templatesEnabled = features?.templates === true;
    const maxDocuments = multiDocument ? (limits.max_documents ?? 1) : 1;

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
    const [reminderSettings, setReminderSettings] =
        useState<ReminderSettings | null>(reminders?.settings ?? null);

    const [page, setPage] = useState(1);
    const [zoom, setZoom] = useState(DEFAULT_ZOOM);
    const [uploadProgress, setUploadProgress] = useState<number | null>(null);
    const [uploadLabel, setUploadLabel] = useState<string | null>(null);
    const [uploadError, setUploadError] = useState<string | null>(null);
    const [sending, setSending] = useState(false);
    const [discarding, setDiscarding] = useState(false);
    const [discardingInFlight, setDiscardingInFlight] = useState(false);
    // Ordem otimista dos arquivos enquanto o PATCH não volta.
    const [documentOrder, setDocumentOrder] = useState<string[] | null>(null);
    const [activeDocumentId, setActiveDocumentId] = useState<string | null>(
        null,
    );

    // Lista de arquivos: com a flag desligada é só o `document` da Fase 1.
    const baseDocuments: EnvelopeDocument[] = multiDocument
        ? serverDocuments && serverDocuments.length > 0
            ? serverDocuments
            : document
              ? [document]
              : []
        : document
          ? [document]
          : [];
    const orderedDocuments = documentOrder
        ? [...baseDocuments].sort(
              (a, b) =>
                  documentOrder.indexOf(a.id) - documentOrder.indexOf(b.id),
          )
        : baseDocuments;
    const firstDocumentId = orderedDocuments[0]?.id ?? null;
    const activeDocument: EnvelopeDocument | null = multiDocument
        ? (orderedDocuments.find(
              (candidate) => candidate.id === activeDocumentId,
          ) ??
          orderedDocuments[0] ??
          null)
        : document;

    const processing = document?.processing.status ?? null;
    const anyProcessing = multiDocument
        ? orderedDocuments.some(isProcessing)
        : processing === 'uploaded' || processing === 'converting';
    // `ready` do resource já exige versão exibível — não basta o status.
    const activeReady = activeDocument?.processing.ready === true;
    const pdf = usePdfDocument(
        step === 3 && activeReady ? (activeDocument?.pdf_url ?? null) : null,
    );

    // Enquanto o arquivo é convertido, recarrega só o que muda (ROUTES §2.6).
    useEffect(() => {
        if (!anyProcessing) {
            return;
        }

        const timer = setInterval(() => {
            router.reload({
                only: multiDocument
                    ? ['document', 'documents', 'completeness', 'envelope']
                    : ['document', 'completeness', 'envelope'],
            });
        }, POLL_INTERVAL_MS);

        return () => clearInterval(timer);
    }, [anyProcessing, multiDocument]);

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
                        // Fase 2 §2.4: só com a flag; ausente, o servidor mantém o papel.
                        ...(participantRoles
                            ? { participant_role: roleOf(recipient) }
                            : {}),
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
                // Fase 2 §2.3: arquivo do campo (ausente = o primeiro, como na Fase 1).
                ...(multiDocument
                    ? { document_id: field.document_id ?? firstDocumentId }
                    : {}),
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

    const saveReminders = (next: ReminderSettings): void => {
        autosave.schedule('reminders', (done) => {
            router.put(
                remindersUrl(envelope.id),
                {
                    enabled: next.enabled,
                    first_after_days: next.first_after_days,
                    interval_days: next.interval_days,
                    max_count: next.max_count,
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

    const changeFields = (next: WizardField[]): void => {
        setFields(next);
        saveFields(next);
    };

    const changeRecipients = (next: WizardRecipient[]): void => {
        setRecipients(next);
        saveRecipients(next);

        if (!participantRoles) {
            return;
        }

        // Regras de papel (§2.4), as mesmas do `FieldSync`: visualizador não tem campo
        // e aprovador não tem assinatura nem rubrica. Trocar o papel remove o que deixou
        // de valer, em vez de deixar o servidor recusar a lista inteira.
        const affected = new Set<string>();
        const pruned = fields.filter((field) => {
            const owner = next.find(
                (recipient) =>
                    recipient.client_id === field.recipient_client_id,
            );

            if (!owner) {
                return true;
            }

            const participantRole = roleOf(owner);
            const keep =
                participantRole !== 'viewer' &&
                !(
                    participantRole === 'approver' &&
                    (field.type === 'signature' || field.type === 'initials')
                );

            if (!keep) {
                affected.add(owner.name || 'participante');
            }

            return keep;
        });

        if (pruned.length !== fields.length) {
            changeFields(pruned);
            toast.info(
                `Campos removidos de ${[...affected].join(', ')}: visualizadores não recebem campos e aprovadores não recebem assinatura nem rubrica.`,
            );
        }
    };

    const changeInitials = (value: boolean): void => {
        setInitialsOnAllPages(value);
        saveFields(fields, value);
    };

    const changeReminders = (next: ReminderSettings): void => {
        setReminderSettings(next);
        saveReminders(next);
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

    /**
     * Fila de uploads (Fase 2 §2.3): um `POST document.store` por arquivo, em sequência,
     * na ordem escolhida — o servidor acrescenta cada um ao fim da lista. Um erro para a
     * fila e mostra o motivo; os arquivos que já subiram continuam no envelope.
     */
    const uploadDocuments = (files: File[]): void => {
        if (files.length === 0) {
            return;
        }

        setUploadError(null);

        const finish = () => {
            setUploadProgress(null);
            setUploadLabel(null);
        };

        const upload = (index: number): void => {
            const file = files[index];

            if (!file) {
                finish();

                return;
            }

            let settled = false;

            setUploadLabel(
                files.length > 1
                    ? `Enviando arquivo ${index + 1} de ${files.length}: ${file.name}`
                    : `Enviando ${file.name}…`,
            );
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
                    onSuccess: () => {
                        settled = true;
                        upload(index + 1);
                    },
                    onError: (bag) => {
                        settled = true;
                        setUploadError(
                            `${file.name}: ${bag.file ?? 'não foi possível enviar o arquivo.'}`,
                        );
                        finish();
                    },
                    onFinish: () => {
                        if (!settled) {
                            finish();
                        }
                    },
                },
            );
        };

        upload(0);
    };

    const removeDocument = (): void => {
        setFields([]);
        setUploadError(null);
        router.delete(documentDestroy(envelope.id).url, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    /** Remove um arquivo (Fase 2): só os campos daquele arquivo saem. */
    const removeDocumentItem = (target: EnvelopeDocument): void => {
        setFields((current) =>
            current.filter(
                (field) => (field.document_id ?? firstDocumentId) !== target.id,
            ),
        );
        setUploadError(null);

        if (activeDocumentId === target.id) {
            setActiveDocumentId(null);
            setPage(1);
        }

        router.delete(
            documentDestroy(envelope.id, { query: { document: target.id } })
                .url,
            { preserveState: true, preserveScroll: true },
        );
    };

    /** Reordena os arquivos (`PATCH envelopes.update` com `document_order`). */
    const moveDocument = (from: number, to: number): void => {
        if (to < 0 || to >= orderedDocuments.length || from === to) {
            return;
        }

        const ids = orderedDocuments.map((item) => item.id);
        const [moved] = ids.splice(from, 1);
        ids.splice(to, 0, moved);
        setDocumentOrder(ids);

        autosave.schedule('documents', (done) => {
            router.patch(
                envelopeUpdate(envelope.id).url,
                { document_order: ids },
                {
                    preserveState: true,
                    preserveScroll: true,
                    onSuccess: () => done(true),
                    onError: () => done(false),
                    onFinish: () =>
                        setDocumentOrder((current) =>
                            current && current.join('|') === ids.join('|')
                                ? null
                                : current,
                        ),
                },
            );
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
    const scheduled = remindersAvailable && reminders?.scheduled_send != null;

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

    const stepperSteps = (
        participantRoles ? WIZARD_STEPS_WITH_ROLES : WIZARD_STEPS
    ).map((definition, index) => ({
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
                        documents={orderedDocuments}
                        multiDocument={multiDocument}
                        maxDocuments={maxDocuments}
                        folders={folders}
                        limits={limits}
                        metadata={metadata}
                        onMetadataChange={patchMetadata}
                        onUpload={uploadDocument}
                        onUploadMany={uploadDocuments}
                        onUploadReject={setUploadError}
                        onRemove={removeDocument}
                        onRemoveDocument={removeDocumentItem}
                        onMoveDocument={moveDocument}
                        uploadProgress={uploadProgress}
                        uploadLabel={uploadLabel}
                        uploadError={uploadError}
                        errors={errors}
                        reminders={remindersAvailable ? reminders : null}
                        reminderSettings={
                            remindersAvailable ? reminderSettings : null
                        }
                        onRemindersChange={changeReminders}
                        templatesEnabled={templatesEnabled}
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
                        participantRoles={participantRoles}
                        roleOptions={participant_roles}
                    />
                )}

                {step === 3 &&
                    (activeDocument ? (
                        <WizardStepFields
                            document={activeDocument}
                            documents={orderedDocuments}
                            multiDocument={multiDocument}
                            onDocumentChange={(id) => {
                                autosave.flush();
                                setActiveDocumentId(id);
                                setPage(1);
                            }}
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
                        documents={orderedDocuments}
                        multiDocument={multiDocument}
                        participantRoles={participantRoles}
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
                        reminderSettings={
                            remindersAvailable ? reminderSettings : null
                        }
                        scheduleSlot={
                            remindersAvailable && reminders ? (
                                <ScheduleSendCard
                                    envelopeId={envelope.id}
                                    reminders={reminders}
                                    canSchedule={
                                        issues.length === 0 && plan.can_send
                                    }
                                    blockedReason={
                                        issues.length > 0
                                            ? 'Resolva as pendências acima para agendar o envio.'
                                            : plan.reason
                                    }
                                    waitingSave={
                                        autosave.status === 'pending' ||
                                        autosave.status === 'saving'
                                    }
                                    errors={errors}
                                />
                            ) : undefined
                        }
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
                                {scheduled
                                    ? 'Enviar agora'
                                    : 'Enviar para assinatura'}
                            </Button>
                        )}
                    </div>
                </div>
            </div>
        </>
    );
}
