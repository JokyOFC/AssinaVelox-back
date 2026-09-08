import { Form, Head } from '@inertiajs/react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { register } from '@/routes';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

type Props = {
    status?: string | null;
    canResetPassword: boolean;
};

/** Login (ROUTES §2.1; DESIGN §6.1). Sem certificado digital e sem passkeys na Fase 1. */
export default function Login({ status, canResetPassword }: Props) {
    return (
        <>
            <Head title="Entrar" />

            {status && (
                <div
                    role="status"
                    className="rounded-[10px] border border-success-border bg-success-bg px-3.5 py-3 text-[13px] font-medium text-success"
                >
                    {status}
                </div>
            )}

            <Form
                {...store.form()}
                resetOnSuccess={['password']}
                className="flex flex-col gap-4"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-1.5">
                            <Label htmlFor="email">E-mail</Label>
                            <Input
                                id="email"
                                type="email"
                                name="email"
                                required
                                autoFocus
                                tabIndex={1}
                                autoComplete="email"
                                placeholder="voce@empresa.com.br"
                                className="h-10 shadow-card"
                                aria-invalid={!!errors.email}
                            />
                            <InputError message={errors.email} />
                        </div>

                        <div className="grid gap-1.5">
                            <Label htmlFor="password">Senha</Label>
                            <PasswordInput
                                id="password"
                                name="password"
                                required
                                tabIndex={2}
                                autoComplete="current-password"
                                placeholder="Sua senha"
                                className="h-10 shadow-card"
                                aria-invalid={!!errors.password}
                            />
                            <InputError message={errors.password} />
                        </div>

                        <div className="flex items-center justify-between gap-3 text-[13px]">
                            <label className="flex cursor-pointer items-center gap-2 text-text-secondary">
                                <Checkbox
                                    id="remember"
                                    name="remember"
                                    defaultChecked
                                    tabIndex={3}
                                />
                                Lembrar de mim
                            </label>
                            {canResetPassword && (
                                <TextLink href={request()} tabIndex={5} className="hover:no-underline">
                                    Esqueci minha senha
                                </TextLink>
                            )}
                        </div>

                        <Button
                            type="submit"
                            size="lg"
                            className="w-full"
                            tabIndex={4}
                            disabled={processing}
                            data-test="login-button"
                        >
                            {processing && <Spinner />}
                            Entrar
                        </Button>
                    </>
                )}
            </Form>

            <p className="text-center text-[13.5px] text-text-secondary">
                Não tem conta?{' '}
                <TextLink href={register()} tabIndex={6}>
                    Criar conta grátis
                </TextLink>
            </p>
        </>
    );
}

Login.layout = {
    title: 'Entrar',
    description: 'Acesse sua conta AssinaVelox.',
};
