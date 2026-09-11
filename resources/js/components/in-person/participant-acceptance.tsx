import { router, usePage } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { type ReactNode, useMemo, useRef, useState } from 'react';
import { CaptureStepCard } from '@/components/identity/capture-step';
import type {
    PresenceLegal,
    PresenceLimits,
    PresencePrivacy,
    PresenceSigning,
} from '@/components/in-person/types';
import { DocumentSwitcher } from '@/components/pdf/document-switcher';
import type { PdfDocumentStatus } from '@/components/pdf/use-pdf-document';
import { ConsentBox } from '@/components/sign/consent-box';
import { FieldChecklist } from '@/components/sign/field-checklist';
import { PrivacyNotice } from '@/components/sign/privacy-notice';
import { SignerDocument } from '@/components/sign/signer-document';
import type {
    OtherField,
    SignerField,
} from '@/components/sign/signer-field-layer';
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
import { plural } from '@/lib/format';
import type { SignatureKind } from '@/types';
import type { IdentityCaptureStep } from '@/types/models';

type PlacedField = SignerField & { documentId: string | null };
type PlacedOther = OtherField & { documentId: string | null };

const METHOD_ALIAS: Record<SignatureKind, string> = {
    drawn: 'draw',
    typed: 'type',
    uploaded: 'upload',
};

function toBase64(dataUrl: string): string {
    const comma = dataUrl.indexOf(',');

    return comma >= 0 ? dataUrl.slice(comma + 1) : dataUrl;
}

function fileLabel(item: { position: number; name: string | null }): string {
    return `${item.position}. ${item.name?.trim() || `Arquivo ${item.position}`}`;
}

/**
 * Revisar o documento e registrar o aceite — no dispositivo presencial e em
 * cada item do lote. Mesmo comportamento da tela `sign` do fluxo individual
 * (mesmos componentes de documento, campos, captura da representação visual e
 * aceite): o botão só habilita depois de o(s) documento(s) chegar(em) a esta
 * sessão, dos campos obrigatórios e da caixa de aceite marcada pela própria
 * pessoa. Cada aceite é de UM documento/envelope.
 */
export function ParticipantAcceptance({
    signing,
    participantName,
    submitUrl,
    privacy,
    legal,
    limits = null,
    captureStep: initialCapture = null,
    heading,
    footer,
}: {
    signing: PresenceSigning;
    participantName: string;
    submitUrl: string;
    privacy: PresencePrivacy;
    legal: PresenceLegal;
    limits?: PresenceLimits | null;
    captureStep?: IdentityCaptureStep | null;
    heading?: ReactNode;
    footer?: ReactNode;
}) {
    const errors = usePage().props.errors as Record<string, string>;
    const docs = signing.documents;
    const multi = docs.length > 1;
    const firstDocId = docs[0]?.id ?? null;

    const [page, setPage] = useState(1);
    const [docId, setDocId] = useState<string | null>(firstDocId);
    const [signature, setSignature] = useState<SignatureValue | null>(null);
    const [initials, setInitials] = useState<SignatureValue | null>(null);
    const [values, setValues] = useState<Record<string, string | boolean>>({});
    const [accepted, setAccepted] = useState(false);
    const [activeFieldId, setActiveFieldId] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);
    const [localError, setLocalError] = useState<string | null>(null);
    const [captureStep, setCaptureStep] = useState<IdentityCaptureStep | null>(
        initialCapture,
    );
    const [documentStatus, setDocumentStatus] =
        useState<PdfDocumentStatus>('idle');
    const [delivered, setDelivered] = useState<Record<string, boolean>>(() =>
        Object.fromEntries(docs.map((item) => [item.id, item.presented])),
    );

    const fieldRefs = useRef<Record<string, HTMLElement | null>>({});
    const signatureRef = useRef<HTMLDivElement | null>(null);
    const initialsRef = useRef<HTMLDivElement | null>(null);

    const currentDoc = docs.find((item) => item.id === docId) ?? docs[0];
    const currentDocId = currentDoc?.id ?? null;
    const pageCount = currentDoc?.pages ?? signing.envelope.pages ?? 1;

    const approving = !signing.action.requires_signature;
    const witnessing = signing.action.type === 'witness';

    const docIndex = (id: string | null): number =>
        Math.max(
            0,
            docs.findIndex((item) => item.id === id),
        );

    const myFields: PlacedField[] = useMemo(() => {
        const pagesIn = (id: string | null): number =>
            docs.find((item) => item.id === id)?.pages ??
            signing.envelope.pages ??
            1;

        return signing.my_fields.flatMap((field) => {
            const documentId = field.document_id ?? firstDocId;
            const pages =
                field.page === 'all'
                    ? Array.from(
                          { length: pagesIn(documentId) },
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
    }, [signing.my_fields, signing.envelope.pages, docs, firstDocId]);

    const otherFields: PlacedOther[] = useMemo(
        () =>
            signing.other_fields.map((field, index) => ({
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
                    : `${field.recipient_name.split(' ')[0]} · ainda não assinou`,
                documentId: field.document_id ?? firstDocId,
            })),
        [signing.other_fields, firstDocId],
    );

    const uniqueFields = useMemo(() => {
        const seen = new Map<string, PlacedField>();
        myFields.forEach((field) => {
            if (!seen.has(field.id)) {
                seen.set(field.id, field);
            }
        });

        return [...seen.values()];
    }, [myFields]);

    const needsSignature =
        !approving && uniqueFields.some((f) => f.type === 'signature');
    const needsInitials =
        !approving && uniqueFields.some((f) => f.type === 'initials');
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

    const ordered = myFields.filter(
        (field) => field.required && !isFilled(field),
    );
    const nextPending = multi
        ? (ordered.find((field) => {
              const index = docIndex(field.documentId);
              const current = docIndex(currentDocId);

              return (
                  index > current || (index === current && field.page > page)
              );
          }) ??
          ordered[0] ??
          null)
        : (ordered.find((field) => field.page > page) ?? ordered[0] ?? null);

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

        if (currentDocId && (arrived || status === 'ready')) {
            setDelivered((current) =>
                current[currentDocId]
                    ? current
                    : { ...current, [currentDocId]: true },
            );
        }
    };

    const missingDocs = docs.filter((item) => !delivered[item.id]);
    const documentPresented = missingDocs.length === 0;
    const capturePending = captureStep !== null && !captureStep.complete;

    const canSubmit =
        accepted &&
        documentPresented &&
        pending.length === 0 &&
        !capturePending &&
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
            kind: image.kind,
            image_base64: toBase64(image.image_base64),
            text: image.text,
            font: image.font,
        });

        const base = {
            authorization: signing.authorization.token,
            fields: values,
            consent: true,
        };

        router.post(
            submitUrl,
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
                // Recusado: a assinatura desenhada continua na tela. Aceito: a
                // página seguinte é outra (fila ou lista) e nada fica em memória.
                preserveState: 'errors',
                onFinish: () => setSubmitting(false),
            },
        );
    };

    const visibleFields = multi
        ? myFields.filter((field) => field.documentId === currentDocId)
        : myFields;
    const visibleOthers = multi
        ? otherFields.filter((field) => field.documentId === currentDocId)
        : otherFields;
    const buttonLabel = signing.action.button_label ?? 'Assinar documento';
    const topError = errors.signature ?? errors.item ?? localError;

    return (
        <div className="flex flex-col gap-4 lg:flex-row lg:items-start">
            <div className="min-w-0 lg:flex-[1.5_1_380px]">
                {multi && (
                    <DocumentSwitcher
                        className="mb-3"
                        label="Confira todos os arquivos"
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
                                    `Arquivo ${item.position}`,
                                meta: !open
                                    ? 'Ainda não aberto'
                                    : missing > 0
                                      ? plural(
                                            missing,
                                            'campo pendente',
                                            'campos pendentes',
                                        )
                                      : 'Aberto · sem pendências',
                                tone:
                                    open && missing === 0
                                        ? 'done'
                                        : 'attention',
                            };
                        })}
                        current={currentDocId}
                        onSelect={selectDocument}
                    />
                )}
                {currentDoc && (
                    <SignerDocument
                        key={currentDocId ?? 'single'}
                        pdfUrl={currentDoc.pdf_url}
                        title={
                            multi
                                ? fileLabel(currentDoc)
                                : signing.envelope.title
                        }
                        pages={pageCount}
                        displayCode={signing.envelope.display_code}
                        fields={visibleFields}
                        others={visibleOthers}
                        values={values}
                        signatureImage={
                            approving ? null : (signature?.image_base64 ?? null)
                        }
                        initialsImage={
                            approving ? null : (initials?.image_base64 ?? null)
                        }
                        activeFieldId={activeFieldId}
                        onActivateField={activateField}
                        page={page}
                        onPageChange={setPage}
                        nextPending={nextPending}
                        onGoToNextPending={goToNextPending}
                        onStatusChange={onDocumentStatus}
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
                            {heading ??
                                (approving
                                    ? 'Sua aprovação'
                                    : witnessing
                                      ? 'Sua assinatura como testemunha'
                                      : 'Sua assinatura')}
                        </h1>
                        <p className="text-text-secondary mt-1.5 text-[13.5px] leading-[1.55]">
                            {approving ? (
                                <>
                                    Você aprova o conteúdo{' '}
                                    {multi ? 'dos arquivos' : 'do documento'}. O
                                    que registra sua{' '}
                                    <b className="text-foreground">
                                        aprovação eletrônica
                                    </b>{' '}
                                    é o aceite abaixo.
                                </>
                            ) : (
                                <>
                                    A imagem é a{' '}
                                    <b className="text-foreground">
                                        representação visual
                                    </b>{' '}
                                    da sua assinatura; o que registra sua
                                    vontade é o aceite abaixo, só para{' '}
                                    <b className="text-foreground">
                                        {signing.envelope.title}
                                    </b>
                                    .
                                </>
                            )}
                        </p>
                    </div>

                    {needsSignature && (
                        <div ref={signatureRef} className="scroll-mt-24">
                            <SignatureCapture
                                value={signature}
                                onChange={setSignature}
                                defaultText={participantName}
                                options={signing.signature_options}
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
                                name={participantName}
                                options={signing.signature_options}
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
                        notice={{
                            summary: privacy.summary,
                            body: privacy.notice,
                        }}
                        privacyUrl={legal.privacy_url}
                    />

                    {signing.consent.completion_notice && (
                        <p className="border-border bg-sidebar text-text-secondary rounded-[10px] border p-3 text-[12.5px] leading-[1.5]">
                            {signing.consent.completion_notice}
                        </p>
                    )}

                    <ConsentBox
                        checked={accepted}
                        onCheckedChange={setAccepted}
                        label={signing.consent.checkbox_label}
                        statement={signing.consent.statement}
                        version={signing.consent.version}
                        termsUrl={legal.terms_url}
                        privacyUrl={legal.privacy_url}
                    />

                    {!documentPresented && (
                        <p className="text-warning text-[12.5px]">
                            {multi
                                ? `Abra e confira todos os arquivos antes de ${approving ? 'aprovar' : 'assinar'}. Falta${missingDocs.length === 1 ? '' : 'm'}: ${missingDocs.map(fileLabel).join(', ')}.`
                                : documentStatus === 'error'
                                  ? 'O documento não chegou. Use “Tentar de novo” ou “Baixar PDF” na barra do documento.'
                                  : 'Aguarde o documento terminar de carregar.'}
                        </p>
                    )}
                    {documentPresented && pending.length > 0 && (
                        <p className="text-warning text-[12.5px]">
                            Faltam{' '}
                            {plural(
                                pending.length,
                                'campo obrigatório',
                                'campos obrigatórios',
                            )}
                            .
                        </p>
                    )}
                    {capturePending && (
                        <p className="text-warning text-[12.5px]">
                            Envie as fotos pedidas antes de concluir.
                        </p>
                    )}

                    {topError && (
                        <p role="alert" className="text-danger text-[12.5px]">
                            {topError}
                        </p>
                    )}

                    <Button
                        type="button"
                        size="xl"
                        onClick={submit}
                        disabled={!canSubmit}
                    >
                        {submitting && <Spinner className="size-4" />}
                        {buttonLabel}
                    </Button>

                    {footer}
                </div>
            </aside>
        </div>
    );
}
