import { Form, Head } from '@inertiajs/react';
import { useRef } from 'react';
import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import type { Props as ManageTwoFactorProps } from '@/components/manage-two-factor';
import ManageTwoFactor from '@/components/manage-two-factor';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { edit as securityEdit } from '@/routes/security';

type Props = {
    passwordRules?: string;
} & ManageTwoFactorProps;

/** Segurança da conta: senha + autenticação em duas etapas (TOTP). Sem passkeys na Fase 1. */
export default function Security(props: Props) {
    const passwordInput = useRef<HTMLInputElement>(null);
    const currentPasswordInput = useRef<HTMLInputElement>(null);

    return (
        <>
            <Head title="Segurança da conta" />

            <div className="flex flex-col gap-4 rounded-xl border border-border bg-card p-5 shadow-card">
                <Heading
                    variant="small"
                    title="Alterar senha"
                    description="Use uma senha longa e única. Você continuará conectado neste dispositivo."
                />

                <Form
                    {...SecurityController.update.form()}
                    options={{ preserveScroll: true }}
                    resetOnError={['password', 'password_confirmation', 'current_password']}
                    resetOnSuccess
                    onError={(errors) => {
                        if (errors.password) {
                            passwordInput.current?.focus();
                        }

                        if (errors.current_password) {
                            currentPasswordInput.current?.focus();
                        }
                    }}
                    className="flex flex-col gap-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid gap-1.5">
                                <Label htmlFor="current_password">Senha atual</Label>
                                <PasswordInput
                                    id="current_password"
                                    ref={currentPasswordInput}
                                    name="current_password"
                                    autoComplete="current-password"
                                    placeholder="Sua senha atual"
                                    aria-invalid={!!errors.current_password}
                                />
                                <InputError message={errors.current_password} />
                            </div>
                            <div className="grid gap-3" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))' }}>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="password">Nova senha</Label>
                                    <PasswordInput
                                        id="password"
                                        ref={passwordInput}
                                        name="password"
                                        autoComplete="new-password"
                                        placeholder="Mínimo de 8 caracteres"
                                        passwordrules={props.passwordRules}
                                        aria-invalid={!!errors.password}
                                    />
                                    <InputError message={errors.password} />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="password_confirmation">Confirmar nova senha</Label>
                                    <PasswordInput
                                        id="password_confirmation"
                                        name="password_confirmation"
                                        autoComplete="new-password"
                                        placeholder="Repita a nova senha"
                                        passwordrules={props.passwordRules}
                                        aria-invalid={!!errors.password_confirmation}
                                    />
                                    <InputError message={errors.password_confirmation} />
                                </div>
                            </div>
                            <div className="flex items-center justify-between gap-3">
                                <span className="text-[12px] text-muted-foreground">Use letras, números e um símbolo.</span>
                                <Button type="submit" disabled={processing} data-test="update-password-button">
                                    {processing && <Spinner />}
                                    Salvar nova senha
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>

            <ManageTwoFactor
                canManageTwoFactor={props.canManageTwoFactor}
                requiresConfirmation={props.requiresConfirmation}
                twoFactorEnabled={props.twoFactorEnabled}
            />
        </>
    );
}

Security.layout = {
    breadcrumbs: [{ title: 'Segurança da conta', href: securityEdit() }],
};
