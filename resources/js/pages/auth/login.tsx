import { Form, Head } from '@inertiajs/react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { SsoLoginEntry } from '@/components/sso/sso-login-entry';
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
                    className="border-success-border bg-success-bg text-success rounded-[10px] border px-3.5 py-3 text-[13px] font-medium"
                >
                    {status}
                </div>
            )}

            {/* `viewTransition`: entrada suave da transição de autenticação (auth-transition). */}
            <Form
                {...store.form()}
                resetOnSuccess={['password']}
                options={{ viewTransition: true }}
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
                                className="shadow-card h-10"
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
                                className="shadow-card h-10"
                                aria-invalid={!!errors.password}
                            />
                            <InputError message={errors.password} />
                        </div>

                        <div className="flex items-center justify-between gap-3 text-[13px]">
                            <label className="text-text-secondary flex cursor-pointer items-center gap-2">
                                <Checkbox
                                    id="remember"
                                    name="remember"
                                    defaultChecked
                                    tabIndex={3}
                                />
                                Manter conectado
                            </label>
                            {canResetPassword && (
                                <TextLink
                                    href={request()}
                                    tabIndex={5}
                                    className="hover:no-underline"
                                >
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

            <SsoLoginEntry />

            <p className="text-text-secondary text-center text-[13.5px]">
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
