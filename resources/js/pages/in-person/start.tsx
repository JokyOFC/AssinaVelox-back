import { Head, router, useForm } from '@inertiajs/react';
import { Info, Tablet } from 'lucide-react';
import type { FormEvent } from 'react';
import { EmptyState } from '@/components/empty-state';
import { PageHeader } from '@/components/page-header';
import { Phase2EmptyState } from '@/components/phase2-empty-state';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { formatDateMedium, formatDateTime } from '@/lib/format';
import { index as envelopesIndex } from '@/routes/envelopes';
import {
    create as inPersonCreate,
    end as inPersonEnd,
    store as inPersonStore,
} from '@/routes/in_person';

interface EnvelopeOption {
    id: string;
    title: string;
    display_code: string;
    sent_at: string | null;
    expires_at: string | null;
    signing_order: string;
    participants: number;
    pending: number;
    done: number;
}

interface ActiveSession {
    id: string;
    device_label: string;
    host_name: string | null;
    hosted_by_me: boolean;
    started_at: string;
    last_activity_at: string;
    envelope: {
        id: string | null;
        title: string | null;
        display_code: string | null;
    };
}

type InPersonStartProps =
    | { enabled: false }
    | {
          enabled: true;
          can_start: boolean;
          selected: string | null;
          idle_minutes: number;
          max_hours: number;
          envelopes: EnvelopeOption[];
          sessions: ActiveSession[];
      };

/**
 * Anfitrião da assinatura presencial (Fase 2 §2.6,
 * docs/fase-2/presencial-e-lote.md §2.1): escolhe o documento enviado, dá
 * nome ao dispositivo e abre a sessão NESTE navegador — que vai para as mãos
 * dos participantes. Cada pessoa confirma o próprio código e registra o
 * próprio aceite; o anfitrião não aceita por ninguém.
 */
export default function InPersonStart(props: InPersonStartProps) {
    if (!props.enabled) {
        return (
            <>
                <Head title="Assinatura presencial" />
                <div className="flex flex-col gap-6">
                    <PageHeader
                        title="Assinatura presencial"
                        subtitle="Várias pessoas registram o próprio aceite no mesmo dispositivo, no balcão ou numa vistoria."
                    />
                    <Phase2EmptyState
                        title="Assinatura presencial em tablet"
                        description="Cada participante confirma o próprio código e registra o próprio aceite no dispositivo que você entregar. Disponível quando o recurso estiver ativo no plano da conta."
                        ctaHref={envelopesIndex().url}
                    />
                </div>
            </>
        );
    }

    return <EnabledStart {...props} />;
}

function EnabledStart({
    can_start,
    selected,
    idle_minutes,
    max_hours,
    envelopes,
    sessions,
}: Extract<InPersonStartProps, { enabled: true }>) {
    const initial =
        (selected && envelopes.some((item) => item.id === selected)
            ? selected
            : envelopes[0]?.id) ?? '';

    const form = useForm({
        envelope: initial,
        device_label: '',
        keep_signed_in: false,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(inPersonStore().url);
    };

    const chosen = envelopes.find((item) => item.id === form.data.envelope);

    return (
        <>
            <Head title="Assinatura presencial" />

            <div className="flex flex-col gap-6">
                <PageHeader
                    title="Assinatura presencial"
                    subtitle="Abra a sessão no dispositivo que vai ficar com os participantes (tablet do balcão, celular da vistoria)."
                />

                <div className="grid gap-6 lg:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)]">
                    <form
                        onSubmit={submit}
                        className="border-border bg-card shadow-card flex flex-col gap-5 rounded-xl border p-5 sm:p-6"
                    >
                        <h2 className="flex items-center gap-2 text-[16px] font-semibold">
                            <Tablet className="text-primary size-4" />
                            Abrir sessão neste dispositivo
                        </h2>

                        {!can_start || envelopes.length === 0 ? (
                            <EmptyState
                                variant="inline"
                                title="Nenhum documento em andamento para abrir"
                                description="A sessão presencial é aberta para um documento já enviado, que você pode enviar, com participantes pendentes."
                            />
                        ) : (
                            <>
                                <div className="flex flex-col gap-1.5">
                                    <Label htmlFor="in-person-envelope">
                                        Documento
                                    </Label>
                                    <Select
                                        value={form.data.envelope}
                                        onValueChange={(value) =>
                                            form.setData('envelope', value)
                                        }
                                    >
                                        <SelectTrigger id="in-person-envelope">
                                            <SelectValue placeholder="Escolha o documento" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {envelopes.map((item) => (
                                                <SelectItem
                                                    key={item.id}
                                                    value={item.id}
                                                >
                                                    {item.title} ·{' '}
                                                    {item.display_code}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {chosen && (
                                        <p className="text-muted-foreground text-[12.5px]">
                                            {chosen.pending} de{' '}
                                            {chosen.participants} participantes
                                            pendentes
                                            {chosen.expires_at &&
                                                ` · prazo ${formatDateMedium(chosen.expires_at)}`}
                                            {chosen.signing_order ===
                                                'sequential' &&
                                                ' · ordem sequencial'}
                                        </p>
                                    )}
                                    {form.errors.envelope && (
                                        <p className="text-danger text-[12.5px]">
                                            {form.errors.envelope}
                                        </p>
                                    )}
                                </div>

                                <div className="flex flex-col gap-1.5">
                                    <Label htmlFor="in-person-device">
                                        Nome do dispositivo
                                    </Label>
                                    <Input
                                        id="in-person-device"
                                        value={form.data.device_label}
                                        maxLength={80}
                                        placeholder="Tablet do balcão"
                                        onChange={(event) =>
                                            form.setData(
                                                'device_label',
                                                event.target.value,
                                            )
                                        }
                                    />
                                    <p className="text-muted-foreground text-[12.5px]">
                                        Vai para a evidência de cada aceite
                                        registrado nesta sessão.
                                    </p>
                                    {form.errors.device_label && (
                                        <p className="text-danger text-[12.5px]">
                                            {form.errors.device_label}
                                        </p>
                                    )}
                                </div>

                                <label className="flex items-start gap-2.5 text-[13px] leading-[1.5]">
                                    <Checkbox
                                        checked={form.data.keep_signed_in}
                                        onCheckedChange={(value) =>
                                            form.setData(
                                                'keep_signed_in',
                                                value === true,
                                            )
                                        }
                                        className="mt-0.5"
                                    />
                                    <span>
                                        Manter minha conta conectada neste
                                        dispositivo.{' '}
                                        <span className="text-muted-foreground">
                                            Desmarcado (recomendado), sua conta
                                            sai deste navegador antes de ele ir
                                            para os participantes.
                                        </span>
                                    </span>
                                </label>

                                <Button
                                    type="submit"
                                    size="lg"
                                    disabled={
                                        form.processing ||
                                        form.data.envelope === '' ||
                                        form.data.device_label.trim().length < 2
                                    }
                                >
                                    {form.processing && (
                                        <Spinner className="size-4" />
                                    )}
                                    Abrir sessão presencial
                                </Button>
                            </>
                        )}
                    </form>

                    <div className="border-border bg-sidebar text-text-secondary flex flex-col gap-2.5 rounded-xl border p-5 text-[13px] leading-[1.55]">
                        <p className="text-foreground flex items-center gap-2 font-semibold">
                            <Info className="text-primary size-4" />
                            Como funciona
                        </p>
                        <p>
                            O dispositivo mostra a fila de participantes. Cada
                            pessoa toca no próprio nome, confirma o código
                            enviado para o e-mail (ou celular) dela e o PIN, se
                            houver, lê o documento e registra o próprio aceite.
                        </p>
                        <p>
                            Entre uma pessoa e outra a tela é bloqueada e limpa:
                            nada do participante anterior fica acessível.
                        </p>
                        <p>
                            A evidência registra que o aceite foi presencial, na
                            presença de quem abriu a sessão, e o dispositivo. O
                            aceite continua sendo de cada participante.
                        </p>
                        <p>
                            A sessão encerra sozinha depois de {idle_minutes}{' '}
                            minutos sem uso ou {max_hours} horas no total.
                        </p>
                    </div>
                </div>

                <section className="border-border bg-card shadow-card rounded-xl border">
                    <h2 className="border-border border-b px-5 py-3.5 text-[15px] font-semibold">
                        Sessões presenciais ativas
                    </h2>
                    {sessions.length === 0 ? (
                        <EmptyState
                            variant="inline"
                            title="Nenhuma sessão presencial ativa"
                        />
                    ) : (
                        <ul className="divide-border divide-y">
                            {sessions.map((session) => (
                                <li
                                    key={session.id}
                                    className="flex flex-wrap items-center justify-between gap-3 px-5 py-3.5"
                                >
                                    <div className="min-w-0">
                                        <p className="truncate text-[14px] font-semibold">
                                            {session.envelope.title} ·{' '}
                                            {session.envelope.display_code}
                                        </p>
                                        <p className="text-muted-foreground text-[12.5px]">
                                            {session.device_label} · aberta por{' '}
                                            {session.hosted_by_me
                                                ? 'você'
                                                : (session.host_name ??
                                                  '—')}{' '}
                                            em{' '}
                                            {formatDateTime(session.started_at)}{' '}
                                            · última atividade{' '}
                                            {formatDateTime(
                                                session.last_activity_at,
                                            )}
                                        </p>
                                    </div>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            router.post(
                                                inPersonEnd(session.id).url,
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        Encerrar
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </>
    );
}

InPersonStart.layout = {
    breadcrumbs: [
        { title: 'Documentos', href: envelopesIndex() },
        { title: 'Assinatura presencial', href: inPersonCreate() },
    ],
};
