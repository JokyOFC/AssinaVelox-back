import { useForm, usePage } from '@inertiajs/react';
import { CircleSlash, MailCheck, PauseCircle, SearchX } from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';
import InputError from '@/components/input-error';
import { PrivacyNotice } from '@/components/public-forms/privacy-notice';
import {
    PublicFormMessage,
    PublicFormShell,
} from '@/components/public-forms/public-form-shell';
import type {
    FormVariable,
    PrivacyNoticeContent,
} from '@/components/public-forms/types';
import { VariableInput } from '@/components/templates/variable-input';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

type Screen =
    | 'form'
    | 'submitted'
    | 'paused'
    | 'expired'
    | 'unavailable'
    | 'not_found';

interface FillProps {
    screen: Screen;
    message?: string;
    form?: {
        title: string;
        organization_name: string;
        instructions?: string | null;
        role_name?: string | null;
        fields?: FormVariable[];
        max_length?: number;
        confirmation_ttl_minutes?: number;
    };
    antiabuse?: {
        timer_field: string;
        timer: string;
        honeypot_field: string;
    };
    privacy?: PrivacyNoticeContent;
    submitted?: { email_hint: string; ttl_minutes: number };
}

/**
 * Página pública do formulário (Fase 2 §2.2): sem login, mobile-first, em
 * PT-BR e sem rastreadores. O envio só grava os dados e manda o link de
 * confirmação; o documento nasce depois que a pessoa confirma o e-mail.
 */
export default function PublicFormFill(props: FillProps) {
    const title = props.form?.title ?? 'Formulário';

    return (
        <PublicFormShell
            title={title}
            organizationName={props.form?.organization_name}
        >
            <Screen {...props} />
        </PublicFormShell>
    );
}

// Sem o shell autenticado: a página desenha a própria casca.
PublicFormFill.layout = (page: ReactNode) => page;

function Screen(props: FillProps) {
    switch (props.screen) {
        case 'form':
            return <FillForm {...props} />;
        case 'submitted':
            return (
                <PublicFormMessage
                    tone="success"
                    icon={<MailCheck className="size-6" />}
                    title="Confira seu e-mail"
                >
                    <p>
                        Enviamos um link de confirmação para{' '}
                        <b>{props.submitted?.email_hint}</b>. Abra a mensagem e
                        confirme para gerar o documento.
                    </p>
                    <p>
                        O link vale por {props.submitted?.ttl_minutes} minutos.
                        Sem a confirmação nada é gerado e as respostas são
                        apagadas. Não chegou? Veja a caixa de spam.
                    </p>
                </PublicFormMessage>
            );
        case 'paused':
            return (
                <PublicFormMessage
                    tone="warning"
                    icon={<PauseCircle className="size-6" />}
                    title="Formulário pausado"
                >
                    <p>{props.message}</p>
                </PublicFormMessage>
            );
        case 'expired':
        case 'unavailable':
            return (
                <PublicFormMessage
                    icon={<CircleSlash className="size-6" />}
                    title={
                        props.screen === 'expired'
                            ? 'Formulário encerrado'
                            : 'Formulário indisponível'
                    }
                >
                    <p>{props.message}</p>
                </PublicFormMessage>
            );
        default:
            return (
                <PublicFormMessage
                    icon={<SearchX className="size-6" />}
                    title="Formulário não encontrado"
                >
                    <p>{props.message}</p>
                </PublicFormMessage>
            );
    }
}

function FillForm({ form: info, antiabuse, privacy }: FillProps) {
    const { url } = usePage();
    const fields = info?.fields ?? [];

    // `started` e `website` são os nomes fixos do servidor
    // (PublicFormIntake::TIMER e ::HONEYPOT), repetidos em `antiabuse`.
    const form = useForm<{
        name: string;
        email: string;
        privacy: boolean;
        values: Record<string, string>;
        started: string;
        website: string;
    }>({
        name: '',
        email: '',
        privacy: false,
        values: Object.fromEntries(
            fields.map((field) => [
                field.key,
                field.default_value ?? (field.type === 'boolean' ? '0' : ''),
            ]),
        ),
        started: antiabuse?.timer ?? '',
        website: '',
    });

    const errors = form.errors as Record<string, string | undefined>;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(url, { preserveScroll: true });
    };

    return (
        <form
            onSubmit={submit}
            noValidate
            className="border-border bg-card shadow-card flex flex-col gap-5 rounded-xl border p-5 md:p-7"
        >
            <div>
                <h1 className="text-[24px] leading-[1.2] font-bold tracking-[-.01em]">
                    {info?.title}
                </h1>
                {info?.instructions && (
                    <p className="text-text-secondary mt-2 text-[14px] leading-[1.55] whitespace-pre-line">
                        {info.instructions}
                    </p>
                )}
                {info?.role_name && (
                    <p className="text-muted-foreground mt-2 text-[12.5px]">
                        Você vai participar do documento como{' '}
                        <b>{info.role_name}</b>.
                    </p>
                )}
            </div>

            {errors.form && (
                <div
                    role="alert"
                    className="border-danger-border bg-danger-bg text-danger rounded-[10px] border px-3.5 py-2.5 text-[13px]"
                >
                    {errors.form}
                </div>
            )}

            <div className="grid gap-4">
                <div className="grid gap-2">
                    <Label htmlFor="pf-name">Seu nome completo</Label>
                    <Input
                        id="pf-name"
                        autoComplete="name"
                        maxLength={120}
                        value={form.data.name}
                        aria-invalid={errors.name ? true : undefined}
                        onChange={(e) => form.setData('name', e.target.value)}
                    />
                    <InputError message={errors.name} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="pf-email">Seu e-mail</Label>
                    <Input
                        id="pf-email"
                        type="email"
                        autoComplete="email"
                        inputMode="email"
                        maxLength={255}
                        value={form.data.email}
                        aria-invalid={errors.email ? true : undefined}
                        onChange={(e) => form.setData('email', e.target.value)}
                    />
                    <p className="text-muted-foreground text-[12px]">
                        Enviaremos um link para confirmar. O convite de
                        assinatura também chega nele.
                    </p>
                    <InputError message={errors.email} />
                </div>

                {fields.map((field) => {
                    const error = errors[`values.${field.key}`];

                    return (
                        <div key={field.key} className="grid gap-2">
                            <Label htmlFor={`pf-${field.key}`}>
                                {field.label}
                                {!field.required && (
                                    <span className="text-muted-foreground font-normal">
                                        {' '}
                                        (opcional)
                                    </span>
                                )}
                            </Label>
                            <VariableInput
                                id={`pf-${field.key}`}
                                type={field.type}
                                options={field.options}
                                value={form.data.values[field.key] ?? ''}
                                invalid={Boolean(error)}
                                onChange={(value) =>
                                    form.setData('values', {
                                        ...form.data.values,
                                        [field.key]: value,
                                    })
                                }
                            />
                            {field.help_text && (
                                <p className="text-muted-foreground text-[12px]">
                                    {field.help_text}
                                </p>
                            )}
                            <InputError message={error} />
                        </div>
                    );
                })}

                {/* Campo-isca: invisível e fora da ordem de tabulação. Pessoas não o veem; robôs o preenchem. */}
                {antiabuse && (
                    <div
                        aria-hidden="true"
                        className="absolute -left-[10000px] h-px w-px overflow-hidden"
                    >
                        <label htmlFor="pf-hp">Não preencha este campo</label>
                        <input
                            id="pf-hp"
                            type="text"
                            tabIndex={-1}
                            autoComplete="off"
                            name={antiabuse.honeypot_field}
                            value={form.data.website}
                            onChange={(e) =>
                                form.setData('website', e.target.value)
                            }
                        />
                    </div>
                )}
            </div>

            {privacy && <PrivacyNotice notice={privacy} />}

            <div className="grid gap-2">
                <label className="flex items-start gap-2.5 text-[13px] leading-[1.45]">
                    <Checkbox
                        id="pf-privacy"
                        checked={form.data.privacy}
                        onCheckedChange={(checked) =>
                            form.setData('privacy', checked === true)
                        }
                        aria-invalid={errors.privacy ? true : undefined}
                        className="mt-0.5"
                    />
                    <span>Li o aviso de privacidade acima.</span>
                </label>
                <InputError message={errors.privacy} />
            </div>

            <Button type="submit" size="lg" disabled={form.processing}>
                {form.processing && <Spinner />}
                Enviar e confirmar e-mail
            </Button>
        </form>
    );
}
