import { Head, Link, router } from '@inertiajs/react';
import { Check, Copy, FileInput, Plus } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { EmptyState } from '@/components/empty-state';
import { PageHeader } from '@/components/page-header';
import { CreateFormDialog } from '@/components/public-forms/create-form-dialog';
import {
    FormStatusBadge,
    SubmissionStatusBadge,
} from '@/components/public-forms/status-badge';
import type {
    PublicFormRow,
    SubmissionRow,
    TemplateOption,
} from '@/components/public-forms/types';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatDateTime, plural } from '@/lib/format';
import { edit as envelopeEdit } from '@/routes/envelopes';
import { edit, index as publicFormsIndex } from '@/routes/public_forms';
import { approve, reject } from '@/routes/public_forms/submissions';
import { index as templatesIndex } from '@/routes/templates';

interface PublicFormsIndexProps {
    forms: PublicFormRow[];
    queue: SubmissionRow[];
    templates: TemplateOption[];
    can: { create: boolean; approve: boolean };
}

/**
 * Formulários públicos (Fase 2 §2.2): lista de formulários e fila de revisão
 * dos envios confirmados que aguardam ação da equipe.
 */
export default function PublicFormsIndex({
    forms,
    queue,
    templates,
    can,
}: PublicFormsIndexProps) {
    const [creating, setCreating] = useState(false);

    return (
        <>
            <Head title="Formulários públicos" />
            <PageHeader
                title="Formulários públicos"
                subtitle="Links onde qualquer pessoa preenche os próprios dados, confirma o e-mail e recebe o documento do modelo para assinar."
                actions={
                    can.create &&
                    templates.length > 0 && (
                        <Button onClick={() => setCreating(true)}>
                            <Plus className="size-[15px]" strokeWidth={2.5} />
                            Novo formulário
                        </Button>
                    )
                }
            />

            {queue.length > 0 && <ReviewQueue queue={queue} can={can} />}

            {forms.length === 0 ? (
                <EmptyState
                    icon={FileInput}
                    title="Nenhum formulário ainda"
                    description={
                        templates.length > 0
                            ? 'Crie um formulário a partir de um modelo e compartilhe o link.'
                            : 'Os formulários usam um modelo ativo. Crie um modelo primeiro.'
                    }
                    action={
                        templates.length > 0 ? (
                            can.create && (
                                <Button onClick={() => setCreating(true)}>
                                    Novo formulário
                                </Button>
                            )
                        ) : (
                            <Button asChild variant="outline">
                                <Link href={templatesIndex()}>
                                    Ir para Modelos
                                </Link>
                            </Button>
                        )
                    }
                />
            ) : (
                <div className="border-border bg-card overflow-hidden rounded-xl border">
                    <ul className="divide-border divide-y">
                        {forms.map((form) => (
                            <FormRow key={form.id} form={form} />
                        ))}
                    </ul>
                </div>
            )}

            {can.create && (
                <CreateFormDialog
                    open={creating}
                    onOpenChange={setCreating}
                    templates={templates}
                />
            )}
        </>
    );
}

PublicFormsIndex.layout = {
    breadcrumbs: [{ title: 'Formulários públicos', href: publicFormsIndex() }],
};

function FormRow({ form }: { form: PublicFormRow }) {
    return (
        <li className="flex flex-col gap-3 p-4 md:flex-row md:items-center md:gap-5 md:px-5">
            <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                    <Link
                        href={edit(form.id)}
                        className="hover:text-primary truncate text-[14.5px] font-semibold"
                    >
                        {form.title}
                    </Link>
                    <FormStatusBadge
                        status={form.status}
                        label={form.status_label}
                        expired={form.expired}
                    />
                    {form.issues_count > 0 && (
                        <Badge variant="warning">
                            {plural(
                                form.issues_count,
                                'pendência',
                                'pendências',
                            )}
                        </Badge>
                    )}
                </div>
                <p className="text-text-secondary mt-1 text-[12.5px]">
                    Modelo: {form.template.name} · {form.destination_label}
                    {form.expires_at &&
                        ` · encerra em ${formatDateTime(form.expires_at)}`}
                </p>
            </div>
            <div className="text-text-secondary flex flex-wrap items-center gap-4 text-[12.5px]">
                <span>
                    {plural(
                        form.sent_count,
                        'documento enviado',
                        'documentos enviados',
                    )}
                </span>
                {form.pending_review_count > 0 && (
                    <Badge variant="count">
                        {form.pending_review_count} na fila
                    </Badge>
                )}
                {form.public_url && <CopyLinkButton url={form.public_url} />}
                <Button asChild variant="outline" size="sm">
                    <Link href={edit(form.id)}>Configurar</Link>
                </Button>
            </div>
        </li>
    );
}

export function CopyLinkButton({ url }: { url: string }) {
    const [copied, setCopied] = useState(false);

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(url);
            setCopied(true);
            toast.success('Link copiado.');
            window.setTimeout(() => setCopied(false), 2000);
        } catch {
            toast.error('Não foi possível copiar. Selecione o link e copie.');
        }
    };

    return (
        <Button type="button" variant="outline" size="sm" onClick={copy}>
            {copied ? (
                <Check className="size-3.5" />
            ) : (
                <Copy className="size-3.5" />
            )}
            {copied ? 'Copiado' : 'Copiar link'}
        </Button>
    );
}

function ReviewQueue({
    queue,
    can,
}: {
    queue: SubmissionRow[];
    can: { approve: boolean };
}) {
    return (
        <section className="border-warning-border bg-card overflow-hidden rounded-xl border">
            <div className="bg-warning-bg/50 border-warning-border border-b px-5 py-3">
                <h2 className="text-[14px] font-semibold">
                    Aguardando revisão ({queue.length})
                </h2>
                <p className="text-text-secondary text-[12.5px]">
                    E-mails confirmados. O documento está em rascunho e só é
                    enviado quando alguém da equipe aprovar.
                </p>
            </div>
            <ul className="divide-border divide-y">
                {queue.map((submission) => (
                    <SubmissionItem
                        key={submission.id}
                        submission={submission}
                        canApprove={can.approve}
                    />
                ))}
            </ul>
        </section>
    );
}

export function SubmissionItem({
    submission,
    canApprove,
    showForm = true,
}: {
    submission: SubmissionRow;
    canApprove: boolean;
    showForm?: boolean;
}) {
    const [busy, setBusy] = useState(false);
    const pending = submission.status === 'pending_review';

    const act = (url: string) => {
        setBusy(true);
        router.post(
            url,
            {},
            {
                preserveScroll: true,
                onError: (errors) =>
                    toast.error(
                        errors.submission ??
                            'Não foi possível concluir a ação.',
                    ),
                onFinish: () => setBusy(false),
            },
        );
    };

    return (
        <li className="flex flex-col gap-3 p-4 md:flex-row md:items-center md:px-5">
            <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                    <span className="truncate text-[14px] font-semibold">
                        {submission.filler?.name ?? 'Participante'}
                    </span>
                    <SubmissionStatusBadge
                        status={submission.status}
                        label={submission.status_label}
                    />
                </div>
                <p className="text-text-secondary mt-0.5 text-[12.5px]">
                    {submission.filler?.email}
                    {showForm && ` · ${submission.form.title}`}
                    {submission.confirmed_at &&
                        ` · confirmado em ${formatDateTime(submission.confirmed_at)}`}
                </p>
                {submission.failure_message && (
                    <p className="text-warning mt-1 text-[12.5px]">
                        {submission.failure_message}
                    </p>
                )}
            </div>
            <div className="flex flex-wrap items-center gap-2">
                {submission.envelope && (
                    <Button asChild variant="outline" size="sm">
                        <Link href={envelopeEdit(submission.envelope.id)}>
                            {submission.envelope.draft
                                ? 'Abrir no editor'
                                : 'Ver documento'}
                        </Link>
                    </Button>
                )}
                {pending && canApprove && (
                    <Button
                        size="sm"
                        disabled={busy}
                        onClick={() => act(approve.url(submission.id))}
                    >
                        Aprovar e enviar
                    </Button>
                )}
                {pending && (
                    <AlertDialog>
                        <AlertDialogTrigger asChild>
                            <Button size="sm" variant="outline" disabled={busy}>
                                Recusar
                            </Button>
                        </AlertDialogTrigger>
                        <AlertDialogContent>
                            <AlertDialogHeader>
                                <AlertDialogTitle>
                                    Recusar este envio?
                                </AlertDialogTitle>
                                <AlertDialogDescription>
                                    O rascunho gerado será excluído e ninguém
                                    será convidado a assinar. A pessoa que
                                    preencheu não é avisada automaticamente.
                                </AlertDialogDescription>
                            </AlertDialogHeader>
                            <AlertDialogFooter>
                                <AlertDialogCancel>Voltar</AlertDialogCancel>
                                <AlertDialogAction
                                    onClick={() =>
                                        act(reject.url(submission.id))
                                    }
                                >
                                    Recusar envio
                                </AlertDialogAction>
                            </AlertDialogFooter>
                        </AlertDialogContent>
                    </AlertDialog>
                )}
            </div>
        </li>
    );
}
