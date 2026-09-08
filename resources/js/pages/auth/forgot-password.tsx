import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Check } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';
import { email as sendResetLink } from '@/routes/password';

type Props = { status?: string | null };

/**
 * Recuperar senha (ROUTES §2.3; DESIGN §6.1 view=recuperar).
 * `status` do Fortify ≠ null → card verde "Verifique seu e-mail" com o e-mail submetido.
 */
export default function ForgotPassword({ status }: Props) {
    const form = useForm({ email: '' });
    const [submittedEmail, setSubmittedEmail] = useState<string | null>(null);
    const sent = !!status && submittedEmail !== null;

    const submit = (event?: FormEvent) => {
        event?.preventDefault();
        const value = form.data.email;
        form.post(sendResetLink.url(), {
            preserveScroll: true,
            onSuccess: () => setSubmittedEmail(value),
        });
    };

    return (
        <>
            <Head title="Recuperar senha" />

            <Link
                href={login()}
                className="inline-flex w-fit items-center gap-1.5 text-[13px] font-semibold text-text-secondary hover:text-primary"
            >
                <ArrowLeft className="size-3.5" />
                Voltar para o login
            </Link>

            {sent ? (
                <div className="flex flex-col gap-4 rounded-xl border border-success-border bg-success-bg p-6">
                    <span className="flex size-10 items-center justify-center rounded-[10px] bg-success-solid text-white">
                        <Check className="size-5 stroke-[2.5]" />
                    </span>
                    <div>
                        <h1 className="text-[20px] font-bold">Verifique seu e-mail</h1>
                        <p className="mt-2 text-[14px] leading-[1.55] text-success">
                            Enviamos um link para <b>{submittedEmail}</b>. Se não
                            aparecer em alguns minutos, confira a pasta de spam.
                        </p>
                        <InputError message={form.errors.email} className="mt-2" />
                    </div>
                    <Button
                        type="button"
                        variant="success"
                        size="sm"
                        className="self-start"
                        disabled={form.processing}
                        onClick={() => submit()}
                    >
                        {form.processing && <Spinner />}
                        Reenviar link
                    </Button>
                </div>
            ) : (
                <>
                    <div>
                        <h1 className="text-[26px] font-bold tracking-[-.01em]">
                            Recuperar senha
                        </h1>
                        <p className="mt-2 text-[14px] leading-[1.55] text-text-secondary">
                            Informe o e-mail da sua conta. Enviaremos um link para
                            redefinir a senha, válido por 30 minutos.
                        </p>
                    </div>
                    <form onSubmit={submit} className="flex flex-col gap-4">
                        <div className="grid gap-1.5">
                            <Label htmlFor="email">E-mail</Label>
                            <Input
                                id="email"
                                type="email"
                                name="email"
                                autoComplete="email"
                                autoFocus
                                required
                                placeholder="voce@empresa.com.br"
                                className="h-10"
                                value={form.data.email}
                                onChange={(e) => form.setData('email', e.target.value)}
                                aria-invalid={!!form.errors.email}
                            />
                            <InputError message={form.errors.email} />
                        </div>
                        <Button
                            type="submit"
                            size="lg"
                            className="w-full"
                            disabled={form.processing}
                            data-test="email-password-reset-link-button"
                        >
                            {form.processing && <Spinner />}
                            Enviar link de redefinição
                        </Button>
                    </form>
                </>
            )}
        </>
    );
}

ForgotPassword.layout = { hideHeader: true };
